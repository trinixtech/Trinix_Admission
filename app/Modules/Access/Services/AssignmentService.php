<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Models\User;
use App\Modules\Access\Models\UserRoleAssignment;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\Course;
use App\Support\Scope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(private AccessControl $access, private Auditor $auditor) {}

    /** Validation rules for an assignment payload, optionally nested under a prefix. */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'role' => ['required', 'string', Rule::exists('roles', 'key')],
            $prefix.'branch_uid' => ['nullable', 'string', Rule::exists('branches', 'uid')],
            $prefix.'course_uid' => ['nullable', 'string', Rule::exists('courses', 'uid')],
            $prefix.'cycle_uid' => ['nullable', 'string', Rule::exists('admission_cycles', 'uid')],
            $prefix.'valid_from' => ['nullable', 'date'],
            $prefix.'valid_to' => ['nullable', 'date', 'after_or_equal:'.$prefix.'valid_from'],
        ];
    }

    /** @param  array{role: string, branch_uid?: ?string, course_uid?: ?string, cycle_uid?: ?string, valid_from?: ?string, valid_to?: ?string}  $data */
    public function assign(User $actor, User $user, array $data): UserRoleAssignment
    {
        $role = Role::with('permissions')->where('key', $data['role'])->firstOrFail();
        $scope = new Scope(
            empty($data['branch_uid']) ? null : Branch::where('uid', $data['branch_uid'])->value('id'),
            empty($data['course_uid']) ? null : Course::where('uid', $data['course_uid'])->value('id'),
            empty($data['cycle_uid']) ? null : AdmissionCycle::where('uid', $data['cycle_uid'])->value('id'),
        );

        if ($actor->is($user)) {
            throw new AuthorizationException('You cannot change your own roles.');
        }
        if (! $this->access->canAssign($actor, $role, $scope)) {
            throw new AuthorizationException('You cannot give this role in this scope. You can only give roles and scopes within your own.');
        }
        $this->ensureNoConflict($user, $role, $scope);

        return DB::transaction(function () use ($actor, $user, $role, $scope, $data) {
            $assignment = UserRoleAssignment::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'branch_id' => $scope->branchId,
                'course_id' => $scope->courseId,
                'admission_cycle_id' => $scope->cycleId,
                'valid_from' => $data['valid_from'] ?? today()->toDateString(),
                'valid_to' => $data['valid_to'] ?? null,
                'assigned_by' => $actor->id,
            ]);
            $this->auditor->record('assignment.created', $user, null, [
                'assignment' => $assignment->uid,
                'role' => $role->key,
                ...$scope->toArray(),
                'valid_from' => $assignment->valid_from->toDateString(),
                'valid_to' => $assignment->valid_to?->toDateString(),
            ], scope: $scope);
            $this->access->forget($user);

            return $assignment;
        });
    }

    public function revoke(User $actor, UserRoleAssignment $assignment, string $reason): void
    {
        $assignment->loadMissing('role.permissions', 'user');
        if ($actor->is($assignment->user)) {
            throw new AuthorizationException('You cannot change your own roles.');
        }
        if (! $this->access->canAssign($actor, $assignment->role, $assignment->scope())) {
            throw new AuthorizationException('You cannot revoke a role you could not give.');
        }
        if ($assignment->isRevoked()) {
            throw ValidationException::withMessages(['assignment' => 'This role is already revoked.']);
        }

        DB::transaction(function () use ($actor, $assignment, $reason) {
            $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();
            $this->auditor->record('assignment.revoked', $assignment->user, [
                'assignment' => $assignment->uid,
                'role' => $assignment->role->key,
                ...$assignment->scope()->toArray(),
            ], null, $reason, $assignment->scope());
            $this->access->forget($assignment->user);
        });
    }

    private function ensureNoConflict(User $user, Role $role, Scope $scope): void
    {
        $conflicting = DB::table('role_conflicts')
            ->where('role_a_id', $role->id)->pluck('role_b_id')
            ->merge(DB::table('role_conflicts')->where('role_b_id', $role->id)->pluck('role_a_id'))
            ->unique();

        $clash = $user->activeAssignments()->with('role')->get()
            ->first(fn ($a) => $conflicting->contains($a->role_id) && $a->scope()->overlaps($scope));

        if ($clash) {
            throw ValidationException::withMessages([
                'role' => "{$role->name} cannot be held together with {$clash->role->name} on the same scope (conflicting duties).",
            ]);
        }
    }
}
