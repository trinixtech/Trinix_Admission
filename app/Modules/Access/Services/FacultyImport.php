<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Imports\Services\FileReader;
use App\Modules\Organisation\Models\Branch;
use App\Support\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk import of faculty and panel members (Stage A, D26): upload → preview (nothing saved) → confirm.
 * Only panel roles can be given this way; existing users only gain roles; new users get the usual invitation.
 */
class FacultyImport
{
    /** Roles this import may give (keys). */
    public const ROLES = ['faculty', 'committee_member', 'committee_chair'];

    public const MAX_ROWS = 2000;

    private const COLUMNS = [
        'name' => ['name', 'full name', 'faculty name'],
        'email' => ['email', 'email id', 'e-mail', 'email address'],
        'phone' => ['mobile', 'mobile no', 'phone', 'phone no', 'mobile number'],
        'designation' => ['designation'],
        'department' => ['department', 'area', 'dept'],
        'campus' => ['campus', 'branch', 'campus code', 'branch code'],
        'roles' => ['role', 'roles'],
    ];

    public function __construct(private FileReader $reader, private AccessControl $access, private AssignmentService $assignments, private Invitations $invitations, private Auditor $auditor) {}

    /** Store the file, check every row, and return the preview with a token for confirming. */
    public function preview(UploadedFile $file, User $actor, bool $alsoCommitteeMember): array
    {
        $ext = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $token = (string) Str::ulid();
        $path = "faculty-imports/$token.$ext";
        Storage::disk('private')->putFileAs('faculty-imports', $file, "$token.$ext");
        $rows = $this->check($path, $ext, $actor, $alsoCommitteeMember);
        Cache::put("faculty-import:$token", ['actor' => $actor->id, 'path' => $path, 'ext' => $ext, 'committee' => $alsoCommitteeMember], now()->addHour());

        return ['token' => $token, 'rows' => $rows, 'counts' => $this->counts($rows)];
    }

    public function confirm(string $token, User $actor): array
    {
        $staged = Cache::get("faculty-import:$token");
        if (! $staged || $staged['actor'] !== $actor->id) {
            throw ValidationException::withMessages(['token' => 'This preview has expired. Upload the file again.']);
        }
        $rows = $this->check($staged['path'], $staged['ext'], $actor, $staged['committee']); // checked again: nothing changes between preview and confirm unnoticed
        $done = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'error' || $r['status'] === 'unchanged') {
                $done[] = $r;

                continue;
            }
            try {
                $invite = null;
                DB::transaction(function () use (&$r, &$invite, $actor) {
                    $user = User::where('email', $r['email'])->first();
                    if (! $user) {
                        $user = User::create(['name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'], 'designation' => $r['designation'],
                            'department' => $r['department'], 'password' => Str::random(64), 'status' => User::ACTIVE]);
                        $this->auditor->record('user.invited', $user, null, ['name' => $user->name, 'email' => $user->email, 'via' => 'faculty import'],
                            scope: new Scope($r['branch_id'], null, null));
                        $invite = $user;
                    }
                    foreach ($r['add_roles'] as $role) {
                        $this->assignments->assign($actor, $user, ['role' => $role, 'branch_uid' => $r['campus_uid']]);
                    }
                });
                if ($invite) {
                    $this->invitations->send($invite);
                }
                $r['status'] = $r['status'] === 'new' ? 'created' : 'role_added';
            } catch (Throwable $e) {
                $r['status'] = 'error';
                $r['errors'] = [$e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()];
            }
            $done[] = $r;
        }
        Storage::disk('private')->delete($staged['path']);
        Cache::forget("faculty-import:$token");
        $counts = $this->counts($done);
        DB::transaction(fn () => $this->auditor->record('faculty.imported', null, null, $counts));

        return ['rows' => $done, 'counts' => $counts];
    }

    /** @return list<array<string, mixed>> */
    private function check(string $path, string $ext, User $actor, bool $alsoCommitteeMember): array
    {
        $roles = Role::whereIn('key', self::ROLES)->get();
        $byName = $roles->mapWithKeys(fn ($r) => [mb_strtolower($r->key) => $r->key, mb_strtolower($r->name) => $r->key])
            ->merge(['faculty' => 'faculty', 'panel member' => 'faculty', 'committee member' => 'committee_member', 'committee chairperson' => 'committee_chair', 'chair' => 'committee_chair']);
        $branches = Branch::get(['id', 'uid', 'code'])->keyBy(fn ($b) => mb_strtoupper($b->code));
        $seen = [];
        $out = [];
        $n = 0;
        foreach ($this->reader->rows(Storage::disk('private')->path($path), $ext) as $line => $raw) {
            if (++$n > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'At most '.self::MAX_ROWS.' rows per file.']);
            }
            $v = $this->map($raw);
            $errors = [];
            $email = mb_strtolower(trim((string) $v['email']));
            if (trim((string) $v['name']) === '') {
                $errors[] = 'Name is missing.';
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email is missing or not valid.';
            } elseif (isset($seen[$email])) {
                $errors[] = "Same email as row {$seen[$email]}.";
            }
            $seen[$email] ??= $line;
            $branch = null;
            if (trim((string) $v['campus']) !== '') {
                $branch = $branches->get(mb_strtoupper(trim((string) $v['campus'])));
                if (! $branch) {
                    $errors[] = "Unknown campus code \"{$v['campus']}\".";
                }
            }
            $wanted = [];
            foreach (preg_split('/[;,]/', (string) ($v['roles'] ?: 'faculty')) as $name) {
                $name = mb_strtolower(trim($name));
                if ($name === '') {
                    continue;
                }
                $key = $byName[$name] ?? null;
                $key ? $wanted[] = $key : $errors[] = "Role \"$name\" cannot be given by this import (Faculty / Panel Member, Committee Member or Committee Chairperson).";
            }
            if ($alsoCommitteeMember) {
                $wanted[] = 'committee_member';
            }
            $wanted = array_values(array_unique($wanted));
            $scope = new Scope($branch?->id, null, null);
            foreach ($wanted as $key) {
                if (! $this->access->canAssign($actor, $roles->firstWhere('key', $key), $scope)) {
                    $errors[] = "You cannot give the role {$roles->firstWhere('key', $key)->name}".($branch ? " at {$branch->code}" : ' for all campuses').'.';
                }
            }
            $existing = $email ? User::where('email', $email)->first() : null;
            if ($existing && $existing->is($actor)) {
                $errors[] = 'You cannot change your own roles.';
            }
            $add = $wanted;
            if ($existing) {
                $held = $existing->activeAssignments()->with('role')->get()->filter(fn ($a) => $a->scope()->covers($scope))->pluck('role.key')->all();
                $add = array_values(array_diff($wanted, $held));
            }
            $out[] = [
                'line' => $line, 'name' => trim((string) $v['name']), 'email' => $email, 'phone' => $this->str($v['phone']),
                'designation' => $this->str($v['designation']), 'department' => $this->str($v['department']),
                'campus' => $branch?->code, 'campus_uid' => $branch?->uid, 'branch_id' => $branch?->id,
                'roles' => $wanted, 'add_roles' => $add, 'errors' => $errors,
                'status' => $errors ? 'error' : (! $existing ? 'new' : ($add ? 'existing' : 'unchanged')),
            ];
        }
        if (! $out) {
            throw ValidationException::withMessages(['file' => 'The file has no rows.']);
        }

        return $out;
    }

    private function map(array $raw): array
    {
        $norm = [];
        foreach ($raw as $h => $value) {
            $norm[mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $h)))] = $value;
        }
        $out = [];
        foreach (self::COLUMNS as $key => $names) {
            $out[$key] = null;
            foreach ($names as $n) {
                if (array_key_exists($n, $norm)) {
                    $out[$key] = $norm[$n];
                    break;
                }
            }
        }

        return $out;
    }

    private function str(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, 120);
    }

    private function counts(array $rows): array
    {
        return collect($rows)->countBy('status')->all() + ['total' => count($rows)];
    }
}
