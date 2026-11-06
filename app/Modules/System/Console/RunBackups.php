<?php

namespace App\Modules\System\Console;

use App\Modules\System\Services\BackupService;
use Illuminate\Console\Command;

/** Run by the cron: makes and emails the day's backup once the time set in System → Backups has passed. */
class RunBackups extends Command
{
    protected $signature = 'backups:run {--now : Make a database backup now, whatever the schedule}';

    protected $description = 'Make the scheduled backup (and the weekly full backup) when it is due';

    public function handle(BackupService $backups): int
    {
        $info = $this->option('now') ? $backups->create('db', null, 'command') : $backups->runScheduled();
        $this->line($info ? "Backup {$info['name']} (".BackupService::humanSize($info['size']).')' : 'No backup due.');

        return self::SUCCESS;
    }
}
