<?php

namespace Database\Seeders;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Models\User;
use App\Modules\Access\Models\UserRoleAssignment;
use App\Modules\Access\Services\AccessCatalog;
use App\Modules\Organisation\Models\Institution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Local demo data: the catalogue, the institution and one Super Admin.
 * Branch, course and cycle setup is left to the screens (that is the Phase 1 acceptance walk-through).
 * Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(AccessCatalog $catalog): void
    {
        if (App::isProduction()) {
            $this->command?->error('DemoSeeder does not run in production.');

            return;
        }

        $catalog->sync();

        Institution::firstOrCreate(['code' => 'IIFT'], [
            'name' => 'Indian Institute of Foreign Trade',
            'short_name' => 'IIFT',
        ]);

        $email = env('DEMO_ADMIN_EMAIL', 'admin@example.test');
        $password = env('DEMO_ADMIN_PASSWORD', 'change-me-please-123');

        $admin = User::firstOrCreate(['email' => $email], [
            'name' => 'Demo Super Admin',
            'password' => $password,
            'status' => User::ACTIVE,
        ]);

        UserRoleAssignment::firstOrCreate(
            ['user_id' => $admin->id, 'role_id' => Role::where('key', 'super_admin')->value('id')],
            ['valid_from' => now()->toDateString()],
        );

        $this->command?->info("Demo Super Admin: {$email} / {$password} (two-factor setup is required at first sign-in).");
    }
}
