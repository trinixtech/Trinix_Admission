<?php

namespace Database\Seeders;

use App\Modules\Access\Services\AccessCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Production-safe: only the roles, permissions and settings catalogue. */
    public function run(AccessCatalog $catalog): void
    {
        $catalog->sync();
    }
}
