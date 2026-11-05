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

class StageBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    /** One job per batch at a time, even with several workers. */
    public function uniqueId(): string
    {
        return 'stage-'.$this->batchId;
    }

    public function handle(ImportPipeline $pipeline): void
    {
        $pipeline->stage(ImportBatch::findOrFail($this->batchId));
    }

    public function failed(?Throwable $e): void
    {
        $batch = ImportBatch::find($this->batchId);
        if ($batch && ! $batch->isFinal()) {
            $batch->forceFill([
                'status' => 'stage' === 'stage' ? ImportBatch::MAPPED : ($batch->rows_written > 0 ? ImportBatch::PARTIAL : ImportBatch::FAILED),
                'status_message' => 'Processing stopped: '.class_basename($e ?? new \RuntimeException).'. Try again.',
            ])->save();
        }
    }
}
