<?php

namespace App\Modules\Documents\Console;

use App\Modules\Documents\Jobs\ScanDocumentVersion;
use App\Modules\Documents\Models\DocumentVersion;
use Illuminate\Console\Command;

/** Re-queues documents still waiting for a scan (for example after the scanner was down). */
class ScanPending extends Command
{
    protected $signature = 'documents:scan-pending {--limit=500}';

    protected $description = 'Queue scans for documents that are still pending';

    public function handle(): int
    {
        $ids = DocumentVersion::where('scan_status', 'pending')->where('created_at', '<', now()->subMinute())
            ->limit((int) $this->option('limit'))->pluck('id');
        foreach ($ids as $id) {
            ScanDocumentVersion::dispatch($id)->onQueue(config('import.queue'));
        }
        $this->info("Queued {$ids->count()} pending scans.");

        return self::SUCCESS;
    }
}
