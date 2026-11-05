<?php

namespace App\Modules\Documents\Jobs;

use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ScanDocumentVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public function __construct(public int $versionId) {}

    public function handle(DocumentStore $store): void
    {
        if ($version = DocumentVersion::find($this->versionId)) {
            $store->scan($version);
        }
    }
}
