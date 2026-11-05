<?php

namespace App\Modules\Access\Console;

use App\Modules\Access\Services\AccessCatalog;
use Illuminate\Console\Command;

class SyncAccessCommand extends Command
{
    protected $signature = 'access:sync';

    protected $description = 'Create missing roles, permissions, role conflicts and settings from config/access.php';

    public function handle(AccessCatalog $catalog): int
    {
        $created = $catalog->sync();
        $this->info(sprintf(
            'Access catalogue in sync (new: %d permissions, %d roles, %d settings).',
            $created['permissions'], $created['roles'], $created['settings'],
        ));

        return self::SUCCESS;
    }
}
