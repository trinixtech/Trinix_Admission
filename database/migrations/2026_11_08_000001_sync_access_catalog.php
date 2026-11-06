<?php

use App\Modules\Access\Services\AccessCatalog;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds roles, permissions and settings introduced since the installation (e.g. backups permissions and the
 * import category list) on servers updated by uploading a release: only missing entries are added.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(AccessCatalog::class)->sync();
    }

    public function down(): void
    {
        // Nothing to undo: the catalogue only ever adds missing entries.
    }
};
