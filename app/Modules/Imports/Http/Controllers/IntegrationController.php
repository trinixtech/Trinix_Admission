<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Documents\Services\DocumentImportPipeline;
use App\Modules\Imports\Jobs\StageBatch;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRecord;
use App\Modules\Organisation\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The portal pushes applicant records. They go through the same staging, preview and
 * approval as an uploaded file; nothing is written until staff approve the batch.
 */
class IntegrationController extends Controller
{
    public function push(Request $request, Auditor $auditor): JsonResponse
    {
        /** @var ExternalSource $source */
        $source = $request->attributes->get('source');
        $data = $request->validate([
            'round' => ['required', 'string', Rule::exists('admission_rounds', 'uid')],
            'course' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'records' => ['required', 'array', 'min:1', 'max:'.config('import.api_max_records')],
            'records.*' => ['array'],
        ]);

        $existing = ImportBatch::where('external_source_id', $source->id)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json(['data' => $this->status($existing), 'replayed' => true]);
        }

        $round = AdmissionRound::with('cycle')->where('uid', $data['round'])->firstOrFail();
        if ($round->isClosed() || $round->cycle->isClosed()) {
            return response()->json(['message' => 'This round is closed.'], 422);
        }
        $courseId = null;
        if (! empty($data['course'])) {
            $courseId = Course::where('code', strtoupper($data['course']))->value('id');
            if (! $courseId) {
                return response()->json(['message' => 'Unknown course code.'], 422);
            }
        }

        $batch = DB::transaction(function () use ($source, $round, $courseId, $data, $auditor) {
            $batch = ImportBatch::create([
                'external_source_id' => $source->id,
                'admission_round_id' => $round->id,
                'course_id' => $courseId,
                'channel' => 'api',
                'status' => ImportBatch::STAGING,
                'idempotency_key' => $data['idempotency_key'],
                'headers' => array_values(array_unique(array_merge(...array_map('array_keys', $data['records'])))),
                'rows_total' => count($data['records']),
            ]);
            $now = now();
            foreach (array_chunk($data['records'], 500, true) as $chunk) {
                ImportRecord::insert(array_map(fn ($r, $i) => [
                    'import_batch_id' => $batch->id, 'row_number' => $i + 1,
                    'raw' => json_encode(array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $r)),
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk, array_keys($chunk)));
            }
            $auditor->record('import.pushed', $batch, null, ['records' => count($data['records']), 'round' => $round->uid],
                scope: $batch->scope(), actorType: 'import');
            StageBatch::dispatch($batch->id)->onQueue(config('import.queue'))->afterCommit();

            return $batch;
        });

        return response()->json(['data' => $this->status($batch->fresh())], 202);
    }

    /** The portal sends document links; files are downloaded, checked and previewed before staff approve. */
    public function pushDocuments(Request $request, DocumentImportPipeline $pipeline): JsonResponse
    {
        /** @var ExternalSource $source */
        $source = $request->attributes->get('source');
        $data = $request->validate([
            'round' => ['required', 'string', Rule::exists('admission_rounds', 'uid')],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'documents' => ['required', 'array', 'min:1', 'max:'.config('import.api_max_records')],
            'documents.*.portal_application_no' => ['required', 'string', 'max:64'],
            'documents.*.course' => ['nullable', 'string', 'max:32'],
            'documents.*.document_type' => ['required', 'string', 'max:32'],
            'documents.*.url' => ['required', 'string', 'max:2000'],
        ]);
        $existing = ImportBatch::where('external_source_id', $source->id)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json(['data' => $this->status($existing), 'replayed' => true]);
        }
        $round = AdmissionRound::with('cycle')->where('uid', $data['round'])->firstOrFail();
        if ($round->isClosed() || $round->cycle->isClosed()) {
            return response()->json(['message' => 'This round is closed.'], 422);
        }
        $batch = $pipeline->push($data['documents'], $data['idempotency_key'], $source, $round);

        return response()->json(['data' => $this->status($batch->fresh())], 202);
    }

    public function show(Request $request, ImportBatch $batch): JsonResponse
    {
        if ($batch->external_source_id !== $request->attributes->get('source')->id) {
            abort(404);
        }

        return response()->json(['data' => $this->status($batch)]);
    }

    private function status(ImportBatch $batch): array
    {
        return [
            'batch' => $batch->uid,
            'status' => $batch->status,
            'rows' => $batch->rows_total,
            'errors' => $batch->rows_error,
            'new' => $batch->rows_new,
            'updates' => $batch->rows_update,
            'unchanged' => $batch->rows_unchanged,
            'duplicates' => $batch->rows_duplicate,
            'written' => $batch->rows_written,
        ];
    }
}
