<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $actor = $this->actor();
        if (! $this->access()->hasAnywhere($actor, 'users.view') && ! $this->access()->hasAnywhere($actor, 'roles.manage')) {
            $this->access()->authorizeAnywhere($actor, 'users.view');
        }

        return response()->json(['data' => Role::with('permissions:id,key')->orderBy('id')->get()->map(fn (Role $r) => [
            'key' => $r->key,
            'name' => $r->name,
            'description' => $r->description,
            'requires_two_factor' => $r->requires_two_factor,
            'permissions' => $r->permissionKeys(),
        ])]);
    }

    public function permissions(): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'roles.manage');

        return response()->json(['data' => Permission::orderBy('module')->orderBy('key')->get(['key', 'module', 'description'])]);
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'roles.manage');
        if ($role->key === 'super_admin') {
            $this->fail('permissions', 'The Super Admin role always has every permission.');
        }
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
            'requires_two_factor' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($role, $data) {
            $old = $role->permissionKeys();
            $role->permissions()->sync(Permission::whereIn('key', $data['permissions'])->pluck('id'));
            $role->load('permissions');
            $new = $role->permissionKeys();
            sort($old);
            sort($new);
            if ($old !== $new) {
                $this->audit()->record('role.permissions_changed', $role, ['permissions' => $old], ['permissions' => $new]);
            }
            if (array_key_exists('requires_two_factor', $data)) {
                $this->audit()->update('role.two_factor_changed', $role, ['requires_two_factor' => $data['requires_two_factor']]);
            }
        });
        $this->access()->forget();

        return $this->index();
    }

    public function conflicts(): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'users.view');

        return response()->json(['data' => $this->conflictPairs()]);
    }

    public function updateConflicts(Request $request): JsonResponse
    {
        $this->access()->authorize($this->actor(), 'roles.manage');
        $data = $request->validate([
            'conflicts' => ['present', 'array'],
            'conflicts.*' => ['array', 'size:2'],
            'conflicts.*.*' => ['string', Rule::exists('roles', 'key')],
        ]);
        $ids = Role::pluck('id', 'key');

        DB::transaction(function () use ($data, $ids) {
            $old = $this->conflictPairs();
            DB::table('role_conflicts')->delete();
            foreach ($data['conflicts'] as [$a, $b]) {
                if ($a === $b) {
                    $this->fail('conflicts', 'A role cannot conflict with itself.');
                }
                DB::table('role_conflicts')->insertOrIgnore(['role_a_id' => $ids[$a], 'role_b_id' => $ids[$b]]);
            }
            $this->audit()->record('role.conflicts_changed', null, ['conflicts' => $old], ['conflicts' => $this->conflictPairs()]);
        });

        return response()->json(['data' => $this->conflictPairs()]);
    }

    private function conflictPairs(): array
    {
        return DB::table('role_conflicts')
            ->join('roles as a', 'a.id', '=', 'role_conflicts.role_a_id')
            ->join('roles as b', 'b.id', '=', 'role_conflicts.role_b_id')
            ->orderBy('a.key')->get(['a.key as a', 'b.key as b'])
            ->map(fn ($r) => [$r->a, $r->b])->all();
    }
}
