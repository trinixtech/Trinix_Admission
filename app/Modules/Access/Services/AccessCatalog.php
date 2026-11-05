<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\Permission;
use App\Modules\Access\Models\Role;
use App\Modules\System\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

/**
 * Creates the roles, permissions, conflicts and settings listed in config/access.php.
 * Safe to run on every deploy: it only adds what is missing and never removes
 * permissions the Super Admin gave or took away, except that super_admin always has everything.
 */
class AccessCatalog
{
    /** @return array{permissions: int, roles: int, settings: int} */
    public function sync(): array
    {
        $config = config('access');
        $created = ['permissions' => 0, 'roles' => 0, 'settings' => 0];

        DB::transaction(function () use ($config, &$created) {
            $newPermissions = [];
            foreach ($config['permissions'] as $key => [$module, $description]) {
                $permission = Permission::firstOrNew(['key' => $key]);
                $isNew = ! $permission->exists;
                $permission->fill(['module' => $module, 'description' => $description])->save();
                if ($isNew) {
                    $newPermissions[$key] = $permission->id;
                    $created['permissions']++;
                }
            }
            $allIds = Permission::pluck('id', 'key');

            foreach ($config['roles'] as $key => $def) {
                $role = Role::firstOrNew(['key' => $key]);
                $isNew = ! $role->exists;
                $role->fill([
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => true,
                    'requires_two_factor' => $isNew ? $def['two_factor'] : $role->requires_two_factor,
                ])->save();

                if ($def['permissions'] === ['*']) {
                    $role->permissions()->sync($allIds->values());
                } elseif ($isNew) {
                    $role->permissions()->sync($allIds->only($def['permissions'])->values());
                    $created['roles']++;
                } else {
                    // Existing role: add only newly created permissions that belong to it by default.
                    $toAdd = collect($newPermissions)->only($def['permissions'])->values();
                    $role->permissions()->syncWithoutDetaching($toAdd);
                }
            }

            $roleIds = Role::pluck('id', 'key');
            foreach ($config['conflicts'] as [$a, $b]) {
                DB::table('role_conflicts')->insertOrIgnore(['role_a_id' => $roleIds[$a], 'role_b_id' => $roleIds[$b]]);
            }

            foreach ($config['settings'] as $key => [$value, $description, $technical]) {
                $setting = SystemSetting::firstOrNew(['key' => $key]);
                if (! $setting->exists) {
                    $setting->fill(['value' => $value, 'description' => $description, 'is_technical' => $technical])->save();
                    $created['settings']++;
                }
            }
        });

        return $created;
    }
}
