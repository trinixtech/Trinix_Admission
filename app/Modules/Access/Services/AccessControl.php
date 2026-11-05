<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Models\User;
use App\Support\Scope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Server-side permission checks with organisational scope.
 *
 * A user holds roles through assignments, each limited to a scope (branch, course, cycle; null = all).
 * - Writes need a grant that COVERS the record's scope.
 * - Reads need a grant that OVERLAPS the record's scope.
 */
class AccessControl
{
    /** @var array<int, list<array{scope: Scope, permissions: array<string, true>, role: Role}>> */
    private array $cache = [];

    /** @return list<array{scope: Scope, permissions: array<string, true>, role: Role}> */
    public function grants(User $user): array
    {
        if (! $user->isActive()) {
            return [];
        }

        return $this->cache[$user->id] ??= $user->activeAssignments()
            ->with('role.permissions')
            ->get()
            ->map(fn ($a) => [
                'scope' => $a->scope(),
                'permissions' => array_fill_keys($a->role->permissionKeys(), true),
                'role' => $a->role,
            ])
            ->all();
    }

    public function forget(?User $user = null): void
    {
        if ($user) {
            unset($this->cache[$user->id]);
        } else {
            $this->cache = [];
        }
    }

    /** Write check: some grant with the permission covers the target scope. */
    public function can(User $user, string $permission, ?Scope $target = null): bool
    {
        $target ??= Scope::global();
        foreach ($this->grants($user) as $grant) {
            if (isset($grant['permissions'][$permission]) && $grant['scope']->covers($target)) {
                return true;
            }
        }

        return false;
    }

    /** Read check: some grant with the permission overlaps the target scope. */
    public function canSee(User $user, string $permission, ?Scope $target = null): bool
    {
        $target ??= Scope::global();
        foreach ($this->grants($user) as $grant) {
            if (isset($grant['permissions'][$permission]) && $grant['scope']->overlaps($target)) {
                return true;
            }
        }

        return false;
    }

    /** True if the user holds the permission anywhere. */
    public function hasAnywhere(User $user, string $permission): bool
    {
        return $this->scopesFor($user, $permission) !== [];
    }

    /**
     * Scopes in which the user holds the permission. Null means unrestricted (a global grant).
     *
     * @return list<Scope>|null
     */
    public function scopesFor(User $user, string $permission): ?array
    {
        $scopes = [];
        foreach ($this->grants($user) as $grant) {
            if (isset($grant['permissions'][$permission])) {
                if ($grant['scope']->covers(Scope::global())) {
                    return null;
                }
                $scopes[] = $grant['scope'];
            }
        }

        return $scopes;
    }

    /** @return list<string> */
    public function permissionsAnywhere(User $user): array
    {
        $all = [];
        foreach ($this->grants($user) as $grant) {
            $all += $grant['permissions'];
        }

        return array_keys($all);
    }

    /** 403 unless the user holds the permission somewhere (used for list endpoints). */
    public function authorizeAnywhere(User $user, string $permission): void
    {
        if (! $this->hasAnywhere($user, $permission)) {
            throw new AuthorizationException('You do not have permission to do this.');
        }
    }

    /**
     * Read check for a single record. Records outside the user's scope return 404,
     * so their existence is not revealed.
     */
    public function authorizeSee(User $user, string $permission, Scope $target): void
    {
        $this->authorizeAnywhere($user, $permission);
        if (! $this->canSee($user, $permission, $target)) {
            throw new NotFoundHttpException;
        }
    }

    /** Write check for a single record: 404 when out of sight, 403 when visible but not allowed. */
    public function authorize(User $user, string $permission, ?Scope $target = null, ?string $seePermission = null): void
    {
        $target ??= Scope::global();
        $this->authorizeAnywhere($user, $permission);
        if ($seePermission && ! $this->canSee($user, $seePermission, $target)) {
            throw new NotFoundHttpException;
        }
        if (! $this->can($user, $permission, $target)) {
            throw new AuthorizationException('This is outside your assigned scope.');
        }
    }

    /**
     * Limit a query to records the user may see.
     *
     * @param  array{branch?: ?string, course?: ?string, cycle?: ?string}  $columns  record column per scope dimension
     * @param  'overlaps'|'covers'  $mode  overlaps: records with an empty dimension are visible too;
     *                                     covers: every limited dimension must match exactly
     */
    public function applyScope(Builder $query, User $user, string $permission, array $columns, string $mode = 'overlaps'): Builder
    {
        $scopes = $this->scopesFor($user, $permission);
        if ($scopes === null) {
            return $query;
        }
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        $dims = ['branch' => 'branchId', 'course' => 'courseId', 'cycle' => 'cycleId'];

        return $query->where(function (Builder $outer) use ($scopes, $columns, $dims, $mode) {
            foreach ($scopes as $scope) {
                $outer->orWhere(function (Builder $q) use ($scope, $columns, $dims, $mode) {
                    foreach ($dims as $dim => $prop) {
                        $value = $scope->{$prop};
                        $column = $columns[$dim] ?? null;
                        if ($value === null) {
                            continue;
                        }
                        if ($column === null) {
                            // The record has no such dimension: visible when overlapping, hidden when covering.
                            if ($mode === 'covers') {
                                $q->whereRaw('1 = 0');
                            }

                            continue;
                        }
                        if ($mode === 'covers') {
                            $q->where($column, $value);
                        } else {
                            $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, $value));
                        }
                    }
                });
            }
        });
    }

    /**
     * No privilege escalation: the actor may give a role in a scope only if they manage users
     * in that scope AND already hold every permission of that role in that scope.
     */
    public function canAssign(User $actor, Role $role, Scope $scope): bool
    {
        if (! $this->can($actor, 'users.manage', $scope)) {
            return false;
        }
        foreach ($role->permissionKeys() as $permission) {
            if (! $this->can($actor, $permission, $scope)) {
                return false;
            }
        }

        return true;
    }

    /** The actor may manage a user only if they could have given every role that user holds. */
    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }
        $assignments = $target->activeAssignments()->with('role.permissions')->get();
        if ($assignments->isEmpty()) {
            return $this->can($actor, 'users.manage', Scope::global());
        }
        foreach ($assignments as $assignment) {
            if (! $this->canAssign($actor, $assignment->role, $assignment->scope())) {
                return false;
            }
        }

        return true;
    }

    /** Whether the actor can see a user: global view, or the user holds a role inside the actor's scope. */
    public function canSeeUser(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        return $this->applyUserScope(User::query()->whereKey($target->id), $actor, 'users.view')->exists();
    }

    public function applyUserScope(Builder $query, User $actor, string $permission): Builder
    {
        $scopes = $this->scopesFor($actor, $permission);
        if ($scopes === null) {
            return $query;
        }

        return $query->whereHas('assignments', function (Builder $q) use ($actor, $permission) {
            $q->active();
            $this->applyScope($q, $actor, $permission, [
                'branch' => 'branch_id', 'course' => 'course_id', 'cycle' => 'admission_cycle_id',
            ], 'covers');
        });
    }
}
