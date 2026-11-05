<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Services\ImportPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class WriteBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $batchId, public bool $careful = false) {}

    /** One job per batch at a time, even with several workers. */
    public function uniqueId(): string
    {
        return 'write-'.$this->batchId;
    }

    public function handle(ImportPipeline $pipeline): void
    {
        $pipeline->write(ImportBatch::findOrFail($this->batchId), $this->careful);
    }

    public function failed(?Throwable $e): void
    {
        $batch = ImportBatch::find($this->batchId);
        if ($batch && ! $batch->isFinal()) {
            $batch->forceFill([
                'status' => 'write' === 'stage' ? ImportBatch::MAPPED : ($batch->rows_written > 0 ? ImportBatch::PARTIAL : ImportBatch::FAILED),
                'status_message' => 'Processing stopped: '.class_basename($e ?? new \RuntimeException).'. Try again.',
            ])->save();
        }
    }
}
