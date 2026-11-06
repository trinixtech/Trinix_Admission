<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportError;
use App\Modules\Imports\Models\ImportRecord;
use App\Modules\Imports\Services\ImportPipeline;
use App\Modules\Organisation\Models\Course;
use App\Support\Rules\UploadedTable;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImportBatchController extends Controller
{
    public function __construct(private ImportPipeline $pipeline) {}

    public function index(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'imports.view');
        $query = ImportBatch::query()
            ->join('admission_rounds', 'admission_rounds.id', '=', 'import_batches.admission_round_id')
            ->select('import_batches.*')
            ->with(['round:id,uid,name,number,admission_cycle_id', 'course:id,uid,code,name', 'source:id,uid,code,name', 'uploader:id,uid,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('import_batches.status', $s))
            ->when($request->query('round_uid'), fn ($q, $r) => $q->where('admission_rounds.uid', $r))
            ->orderByDesc('import_batches.id');
        $this->access()->applyScope($query, $this->actor(), 'imports.view', [
            'course' => 'import_batches.course_id', 'cycle' => 'admission_rounds.admission_cycle_id',
        ], 'covers');

        $page = $query->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(fn ($b) => $this->summary($b)));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'imports.manage');
        $data = $request->validate([
            'source_uid' => ['required', 'string', Rule::exists('external_sources', 'uid')],
            'round_uid' => ['required', 'string', Rule::exists('admission_rounds', 'uid')],
            'course_uid' => ['nullable', 'string', Rule::exists('courses', 'uid')],
            'file' => ['required', 'file', 'max:'.config('import.max_file_kb'), new UploadedTable],
        ]);
        $round = AdmissionRound::with('cycle')->where('uid', $data['round_uid'])->firstOrFail();
        $courseId = empty($data['course_uid']) ? null : Course::where('uid', $data['course_uid'])->value('id');
        $this->access()->authorize($this->actor(), 'imports.manage', new Scope(null, $courseId, $round->admission_cycle_id));

        if ($round->isClosed() || $round->cycle->isClosed()) {
            $this->fail('round_uid', 'This round is closed.');
        }
        $source = ExternalSource::where('uid', $data['source_uid'])->firstOrFail();
        if (! $source->isActive()) {
            $this->fail('source_uid', 'This source is inactive.');
        }

        $batch = $this->pipeline->upload($request->file('file'), $source, $round, $courseId, $this->actor());

        return response()->json(['data' => $this->present($batch)], 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        $this->ensureVisible($batch);

        return response()->json(['data' => $this->present($batch)]);
    }

    public function sample(ImportBatch $batch): JsonResponse
    {
        $this->ensureVisible($batch);

        return response()->json(['data' => $this->pipeline->sample($batch)]);
    }

    public function mapping(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->ensureManage($batch);
        $data = $request->validate([
            'column_map' => ['required', 'array'],
            'column_map.*' => ['nullable', 'string', 'max:255'],
            'value_map' => ['nullable', 'array'],
            'value_map.*' => ['array'],
            'value_map.*.*' => ['nullable', 'string', 'max:255'],
        ]);
        $this->pipeline->setMapping($batch, $data['column_map'], $data['value_map'] ?? []);

        return response()->json(['data' => $this->present($batch)]);
    }

    public function validateBatch(ImportBatch $batch): JsonResponse
    {
        $this->ensureManage($batch);
        $this->pipeline->startValidation($batch);

        return response()->json(['data' => $this->present($batch)], 202);
    }

    public function records(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->ensureVisible($batch);
        $request->validate(['classification' => ['nullable', Rule::in(['new', 'update', 'unchanged', 'duplicate', 'error', 'excluded'])]]);
        $c = $request->query('classification');
        $page = $batch->records()
            ->when($c === 'excluded', fn ($q) => $q->where('excluded', true))
            ->when($c && $c !== 'excluded', fn ($q) => $q->where('classification', $c)->when(in_array($c, ['new', 'update', 'unchanged'], true), fn ($q) => $q->where('excluded', false)))
            ->orderBy('row_number')
            ->paginate($this->perPage($request));
        $errors = ImportError::where('import_batch_id', $batch->id)->whereIn('import_record_id', $page->getCollection()->pluck('id'))->get()->groupBy('import_record_id');
        $page->setCollection($page->getCollection()->map(fn (ImportRecord $r) => [
            'id' => $r->id,
            'row_number' => $r->row_number,
            'classification' => $r->classification,
            'excluded' => $r->excluded,
            'portal_application_no' => $r->mapped['portal_application_no'] ?? null,
            'full_name' => $r->mapped['full_name'] ?? null,
            'course' => $r->mapped['course'] ?? null,
            'document_type' => $r->mapped['document_type'] ?? null,
            'file' => $r->raw['file'] ?? ($r->raw['url'] ?? null),
            'diff' => $r->classification === 'update' ? $r->diff : null,
            'first_row' => $r->classification === 'duplicate' ? ($r->diff['first_row'] ?? null) : null,
            'errors' => ($errors[$r->id] ?? collect())->map->only(['field', 'message'])->values(),
            'written' => $r->written_at !== null,
        ]));

        return response()->json($page);
    }

    /** Error report: JSON, or CSV with ?format=csv. */
    public function errors(Request $request, ImportBatch $batch): JsonResponse|StreamedResponse
    {
        $this->ensureVisible($batch);
        $query = ImportError::where('import_batch_id', $batch->id)->orderBy('row_number')->orderBy('id');
        if ($request->query('format') === 'csv') {
            $this->audit()->record('import.error_report_downloaded', $batch, scope: $batch->scope());

            return response()->streamDownload(function () use ($query) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['row', 'field', 'message']);
                $query->chunk(2000, function ($rows) use ($out) {
                    foreach ($rows as $e) {
                        fputcsv($out, [$e->row_number, $e->field, $e->message]);
                    }
                });
                fclose($out);
            }, "import-{$batch->uid}-errors.csv", ['Content-Type' => 'text/csv']);
        }

        return response()->json($query->paginate($this->perPage($request), ['row_number', 'field', 'message', 'severity']));
    }

    public function exclusions(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->ensureManage($batch);
        $data = $request->validate([
            'record_ids' => ['required', 'array', 'max:1000'],
            'record_ids.*' => ['integer'],
            'excluded' => ['required', 'boolean'],
        ]);
        $this->pipeline->setExcluded($batch, $data['record_ids'], $data['excluded'], $this->actor());

        return response()->json(['data' => $this->present($batch)]);
    }

    public function approve(ImportBatch $batch): JsonResponse
    {
        $this->ensureVisible($batch);
        $this->access()->authorize($this->actor(), 'imports.approve', $batch->scope());
        $approved = $this->pipeline->approve($batch, $this->actor());

        return response()->json(['data' => $this->present($batch), 'approved' => $approved], $approved ? 202 : 409);
    }

    public function cancel(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->ensureManage($batch);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->pipeline->cancel($batch, $data['reason']);

        return response()->json(['data' => $this->present($batch)]);
    }

    public function retry(ImportBatch $batch): JsonResponse
    {
        $this->ensureVisible($batch);
        $this->access()->authorize($this->actor(), 'imports.approve', $batch->scope());
        $this->pipeline->retry($batch);

        return response()->json(['data' => $this->present($batch)], 202);
    }

    private function ensureVisible(ImportBatch $batch): void
    {
        $this->access()->authorizeAnywhere($this->actor(), 'imports.view');
        if (! $this->access()->can($this->actor(), 'imports.view', $batch->scope())) {
            throw new NotFoundHttpException;
        }
    }

    private function ensureManage(ImportBatch $batch): void
    {
        $this->ensureVisible($batch);
        $this->access()->authorize($this->actor(), 'imports.manage', $batch->scope());
    }

    private function summary(ImportBatch $b): array
    {
        return [
            'uid' => $b->uid,
            'status' => $b->status,
            'channel' => $b->channel,
            'original_filename' => $b->original_filename,
            'source' => $b->source?->only(['uid', 'code', 'name']),
            'round' => $b->round?->only(['uid', 'name', 'number']),
            'course' => $b->course?->only(['uid', 'code', 'name']),
            'uploader' => $b->uploader?->only(['uid', 'name']),
            'counts' => $this->pipeline->counts($b) + ['processed' => $b->rows_processed, 'valid' => $b->rows_valid],
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }

    private function present(ImportBatch $batch): array
    {
        $batch->refresh()->load(['round.cycle', 'course', 'source', 'uploader:id,uid,name', 'approver:id,uid,name']);

        return $this->summary($batch) + [
            'cycle' => $batch->round->cycle->only(['uid', 'name', 'code']),
            'headers' => $batch->headers,
            'column_map' => $batch->column_map,
            'value_map' => $batch->value_map,
            'status_message' => $batch->status_message,
            'approver' => $batch->approver?->only(['uid', 'name']),
            'approved_at' => $batch->approved_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'cancel_reason' => $batch->cancel_reason,
            'reconciles' => $batch->status === ImportBatch::COMPLETED ? $batch->reconciles() : null,
            'can_manage' => $this->access()->can($this->actor(), 'imports.manage', $batch->scope()),
            'can_approve' => $this->access()->can($this->actor(), 'imports.approve', $batch->scope()) && $batch->uploaded_by !== $this->actor()->id,
            'uploaded_by_me' => $batch->uploaded_by === $this->actor()->id,
        ];
    }
}
