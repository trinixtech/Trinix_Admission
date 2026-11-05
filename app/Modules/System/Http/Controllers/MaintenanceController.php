<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\AccessCatalog;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * System → Maintenance (Stage A): pending database updates, run by an IT Administrator with confirmation and an
 * audit row, for servers without command-line access. Rolling deploys still run `migrate` as documented.
 */
class MaintenanceController extends Controller
{
    public function show(Migrator $migrator): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'system.maintain');

        return response()->json(['data' => ['pending' => $this->pending($migrator), 'version' => config('app.version'),
            'last_batch' => DB::table('migrations')->max('batch')]]);
    }

    public function migrate(Request $request, Migrator $migrator): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'system.maintain');
        $data = $request->validate(['confirm' => ['required', 'in:MIGRATE'], 'password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $this->actor()->password)) {
            $this->fail('password', 'The password is not correct.');
        }
        $pending = $this->pending($migrator);
        if (! $pending) {
            $this->fail('confirm', 'There are no pending database updates.');
        }
        Artisan::call('migrate', ['--force' => true]);
        $out = Artisan::output();
        app(AccessCatalog::class)->sync();
        DB::transaction(fn () => $this->audit()->record('system.migrated', null, null, ['migrations' => $pending]));

        return response()->json(['message' => count($pending).' database updates applied; roles and permissions reloaded.', 'data' => ['output' => $out, 'pending' => $this->pending($migrator)]]);
    }

    /** @return list<string> */
    private function pending(Migrator $migrator): array
    {
        $files = $migrator->getMigrationFiles([database_path('migrations')]);
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }
}
