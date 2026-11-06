<?php

namespace App\Modules\Imports\Services;

use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Services\ApplicationRecord;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Documents\Services\DocumentImportPipeline;
use App\Modules\Imports\Jobs\StageBatch;
use App\Modules\Imports\Jobs\WriteBatch;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportError;
use App\Modules\Imports\Models\ImportRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * upload → map → stage → validate → classify → preview → approve → write → reconcile.
 * Nothing reaches the applicant tables before approval.
 */
class ImportPipeline
{
    public const OTHER_COURSE = 'This portal application number is already used for another course in this cycle.';

    public function __construct(private Auditor $auditor, private FileReader $reader) {}

    // ----- Upload and mapping -------------------------------------------------------------------

    public function upload(UploadedFile $file, ExternalSource $source, AdmissionRound $round, ?int $courseId, User $user): ImportBatch
    {
        $channel = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $sha = hash_file('sha256', $file->getRealPath());

        $same = ImportBatch::where('admission_round_id', $round->id)->where('file_sha256', $sha)
            ->where('status', '!=', ImportBatch::CANCELLED)->first();
        if ($same) {
            throw ValidationException::withMessages(['file' => "This file was already uploaded for this round (batch {$same->uid})."]);
        }

        try {
            $info = $this->reader->inspect($file->getRealPath(), $channel);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => FileReader::unreadable($channel)]);
        }
        if ($info['rows'] > config('import.max_rows')) {
            throw ValidationException::withMessages(['file' => 'The file has more than '.number_format(config('import.max_rows')).' rows. Split it into smaller files.']);
        }

        return DB::transaction(function () use ($file, $source, $round, $courseId, $user, $channel, $sha, $info) {
            $batch = ImportBatch::create([
                'external_source_id' => $source->id,
                'admission_round_id' => $round->id,
                'course_id' => $courseId,
                'channel' => $channel,
                'status' => ImportBatch::UPLOADED,
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_sha256' => $sha,
                'headers' => $info['headers'],
                'column_map' => app(ImportLayouts::class)->columnMap($info['headers']) ?: null, // a suggestion from the admin's template; saved only on Map
                'rows_total' => $info['rows'],
                'uploaded_by' => $user->id,
            ]);
            $batch->file_path = Storage::disk('private')->putFileAs("imports/{$batch->uid}", $file, "source.{$channel}");
            $batch->save();
            $this->auditor->record('import.uploaded', $batch, null, [
                'file' => $batch->original_filename, 'rows' => $info['rows'], 'round' => $round->uid,
            ], scope: $batch->scope());

            return $batch;
        });
    }

    /** Sample rows for the mapping screen. */
    public function sample(ImportBatch $batch): array
    {
        if ($batch->channel === 'api') {
            return $batch->records()->orderBy('row_number')->limit(5)->pluck('raw')->all();
        }

        return $this->withLocalFile($batch, fn ($path) => $this->reader->inspect($path, $batch->channel)['sample']);
    }

    public function setMapping(ImportBatch $batch, array $columnMap, array $valueMap): void
    {
        $this->ensureEditable($batch);
        $headers = $batch->headers ?? [];
        $errors = [];
        foreach ($columnMap as $field => $column) {
            if (! FieldCatalogue::isKnown($field)) {
                $errors["column_map.$field"] = "Unknown system field \"$field\".";
            } elseif ($column !== null && $column !== '' && ! in_array($column, $headers, true)) {
                $errors["column_map.$field"] = "The file has no column \"$column\".";
            }
        }
        $columnMap = array_filter($columnMap, fn ($c) => $c !== null && $c !== '');
        foreach (['portal_application_no', 'full_name'] as $must) {
            if (! isset($columnMap[$must])) {
                $errors["column_map.$must"] = 'Map a column to '.FieldCatalogue::fields()[$must]['label'].'.';
            }
        }
        if (! isset($columnMap['course']) && ! $batch->course_id) {
            $errors['column_map.course'] = 'Map a column to Course, or choose a course for the whole batch.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($batch, $columnMap, $valueMap) {
            $this->auditor->update('import.mapped', $batch, [
                'column_map' => $columnMap,
                'value_map' => $valueMap ?: null,
                'status' => ImportBatch::MAPPED,
            ], $batch->scope());
        });
    }

    /** Queue staging and validation. Allowed again until approval (e.g. after changing the mapping). */
    public function startValidation(ImportBatch $batch): void
    {
        $this->ensureEditable($batch);
        if (! in_array($batch->status, [ImportBatch::MAPPED, ImportBatch::VALIDATED], true)) {
            throw ValidationException::withMessages(['status' => 'Map the columns first.']);
        }
        $batch->forceFill(['status' => ImportBatch::STAGING, 'rows_processed' => 0, 'status_message' => null])->save();
        StageBatch::dispatch($batch->id)->onQueue(config('import.queue'))->afterCommit();
    }

    // ----- Staging, validation and classification ------------------------------------------------

    public function stage(ImportBatch $batch): void
    {
        if ($batch->isDocuments()) {
            app(DocumentImportPipeline::class)->stage($batch);

            return;
        }
        $batch->refresh()->loadMissing('round');
        $context = new ImportContext($batch);
        $normalizer = new RowNormalizer($context);

        ImportError::where('import_batch_id', $batch->id)->delete();

        if ($batch->channel === 'api') {
            $batch->records()->chunkById(config('import.chunk'), function ($records) use ($batch, $normalizer) {
                DB::transaction(function () use ($records, $batch, $normalizer) {
                    foreach ($records as $record) {
                        $identity = array_combine(array_keys($record->raw), array_keys($record->raw));
                        $known = array_filter($identity, fn ($k) => FieldCatalogue::isKnown($k));
                        $this->stageRecord($record, $normalizer->map($record->raw, $known, $batch->value_map ?? []), $normalizer, $batch);
                    }
                });
                $batch->increment('rows_processed', $records->count());
            });
        } else {
            ImportRecord::where('import_batch_id', $batch->id)->delete();
            $this->withLocalFile($batch, function ($path) use ($batch, $normalizer) {
                $buffer = [];
                foreach ($this->reader->rows($path, $batch->channel) as $line => $raw) {
                    $buffer[$line] = $raw;
                    if (count($buffer) >= config('import.chunk')) {
                        $this->stageChunk($buffer, $batch, $normalizer);
                        $buffer = [];
                    }
                }
                if ($buffer) {
                    $this->stageChunk($buffer, $batch, $normalizer);
                }
            });
        }

        $this->classify($batch);
        $batch->forceFill(['status' => ImportBatch::VALIDATED])->save();
        $this->auditor->record('import.validated', $batch, null, $this->counts($batch), scope: $batch->scope(), actorType: 'system');
    }

    private function stageChunk(array $rows, ImportBatch $batch, RowNormalizer $normalizer): void
    {
        DB::transaction(function () use ($rows, $batch, $normalizer) {
            foreach ($rows as $line => $raw) {
                $raw = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $raw);
                $record = ImportRecord::create(['import_batch_id' => $batch->id, 'row_number' => $line, 'raw' => $raw]);
                $this->stageRecord($record, $normalizer->map($raw, $batch->column_map ?? [], $batch->value_map ?? []), $normalizer, $batch);
            }
        });
        $batch->increment('rows_processed', count($rows));
    }

    private function stageRecord(ImportRecord $record, array $mapped, RowNormalizer $normalizer, ImportBatch $batch): void
    {
        [$values, $errors] = $normalizer->normalize($mapped);
        foreach ($errors as $e) {
            ImportError::create([
                'import_batch_id' => $batch->id, 'import_record_id' => $record->id, 'row_number' => $record->row_number,
                'field' => $e['field'], 'message' => $e['message'], 'severity' => 'error',
            ]);
        }
        $record->forceFill([
            'mapped' => $values,
            'classification' => $errors ? ImportRecord::ERROR : null,
            'diff' => null,
        ])->save();
    }

    /**
     * Sort valid rows into new / update / unchanged / duplicate, and catch a portal number used for
     * two courses. Re-runnable: approval runs it again to detect changes since the preview.
     */
    public function classify(ImportBatch $batch): void
    {
        if ($batch->isDocuments()) {
            app(DocumentImportPipeline::class)->classify($batch);

            return;
        }
        $batch->loadMissing('round');
        $cycleId = $batch->round->admission_cycle_id;
        $seen = [];          // portal no|course id => row number of first occurrence
        $portalCourse = [];  // portal no => course id

        // Errors raised by classification itself are rebuilt each time.
        $flagged = ImportError::where('import_batch_id', $batch->id)->where('message', self::OTHER_COURSE)->pluck('import_record_id');
        ImportError::where('import_batch_id', $batch->id)->where('message', self::OTHER_COURSE)->delete();
        ImportRecord::whereIn('id', $flagged)
            ->whereNotIn('id', ImportError::where('import_batch_id', $batch->id)->whereNotNull('import_record_id')->select('import_record_id'))
            ->update(['classification' => null]);

        $batch->records()->where(fn ($q) => $q->whereNull('classification')->orWhere('classification', '!=', ImportRecord::ERROR))
            ->orderBy('row_number')
            ->chunk(config('import.chunk'), function ($records) use ($batch, $cycleId, &$seen, &$portalCourse) {
                $portals = $records->map(fn ($r) => $r->mapped['portal_application_no'] ?? null)->filter()->unique()->values();
                $existing = Application::with('applicant', 'entrance', 'academics', 'preferences')
                    ->where('external_source_id', $batch->external_source_id)
                    ->where('admission_cycle_id', $cycleId)
                    ->whereIn('portal_application_no', $portals)
                    ->get()->groupBy('portal_application_no');

                DB::transaction(function () use ($records, $batch, $existing, &$seen, &$portalCourse) {
                    foreach ($records as $record) {
                        $v = $record->mapped;
                        $portal = $v['portal_application_no'];
                        $courseId = $v['course_id'];
                        $key = "$portal|$courseId";

                        $otherCourse = ($portalCourse[$portal] ?? $courseId) !== $courseId
                            || ($existing[$portal] ?? collect())->contains(fn ($a) => $a->course_id !== $courseId);
                        if ($otherCourse) {
                            $this->classifiedError($record, $batch, self::OTHER_COURSE);

                            continue;
                        }
                        $portalCourse[$portal] = $courseId;

                        if (isset($seen[$key])) {
                            $record->forceFill(['classification' => ImportRecord::DUPLICATE, 'diff' => ['first_row' => $seen[$key]]])->save();

                            continue;
                        }
                        $seen[$key] = $record->row_number;

                        $app = ($existing[$portal] ?? collect())->firstWhere('course_id', $courseId);
                        if (! $app) {
                            $record->forceFill(['classification' => ImportRecord::NEW, 'diff' => null, 'application_id' => null])->save();

                            continue;
                        }
                        $diff = ApplicationRecord::diff($app, $v);
                        $record->forceFill([
                            'classification' => $diff ? ImportRecord::UPDATE : ImportRecord::UNCHANGED,
                            'diff' => $diff ?: null,
                            'application_id' => $app->id,
                        ])->save();
                    }
                });
            });

        $this->recount($batch);
    }

    private function classifiedError(ImportRecord $record, ImportBatch $batch, string $message): void
    {
        $record->forceFill(['classification' => ImportRecord::ERROR, 'diff' => null])->save();
        ImportError::create([
            'import_batch_id' => $batch->id, 'import_record_id' => $record->id, 'row_number' => $record->row_number,
            'field' => 'portal_application_no', 'message' => $message, 'severity' => 'error',
        ]);
    }

    public function recount(ImportBatch $batch): void
    {
        $by = $batch->records()->selectRaw('classification, excluded, count(*) as n')->groupBy('classification', 'excluded')->get();
        $count = fn (string $c, ?bool $excluded = null) => (int) $by->filter(fn ($r) => $r->classification === $c && ($excluded === null || (bool) $r->excluded === $excluded))->sum('n');
        $batch->forceFill([
            'rows_total' => (int) $by->sum('n'),
            'rows_error' => $count(ImportRecord::ERROR),
            'rows_duplicate' => $count(ImportRecord::DUPLICATE),
            'rows_new' => $count(ImportRecord::NEW, false),
            'rows_update' => $count(ImportRecord::UPDATE, false),
            'rows_unchanged' => $count(ImportRecord::UNCHANGED, false),
            'rows_excluded' => $count(ImportRecord::NEW, true) + $count(ImportRecord::UPDATE, true) + $count(ImportRecord::UNCHANGED, true),
        ])->save();
        $batch->rows_valid = $batch->rows_new + $batch->rows_update + $batch->rows_unchanged + $batch->rows_excluded;
        $batch->save();
    }

    // ----- Preview actions --------------------------------------------------------------------

    /** @param list<int> $recordIds */
    public function setExcluded(ImportBatch $batch, array $recordIds, bool $excluded, User $user): int
    {
        if ($batch->status !== ImportBatch::VALIDATED) {
            throw ValidationException::withMessages(['status' => 'Rows can be excluded only in the preview.']);
        }

        return DB::transaction(function () use ($batch, $recordIds, $excluded) {
            $n = $batch->records()->whereIn('id', $recordIds)
                ->whereIn('classification', [ImportRecord::NEW, ImportRecord::UPDATE, ImportRecord::UNCHANGED])
                ->update(['excluded' => $excluded]);
            $this->recount($batch);
            $this->auditor->record($excluded ? 'import.rows_excluded' : 'import.rows_included', $batch, null, ['rows' => $n], scope: $batch->scope());

            return $n;
        });
    }

    public function cancel(ImportBatch $batch, string $reason): void
    {
        if (! in_array($batch->status, [ImportBatch::UPLOADED, ImportBatch::MAPPED, ImportBatch::VALIDATED], true)) {
            throw ValidationException::withMessages(['status' => 'Only a batch that has not been approved can be cancelled.']);
        }
        DB::transaction(fn () => $this->auditor->update('import.cancelled', $batch, [
            'status' => ImportBatch::CANCELLED, 'cancel_reason' => $reason,
        ], $batch->scope(), $reason));
    }

    // ----- Approval and writing -----------------------------------------------------------------

    /**
     * Approve for writing. The batch is classified again first; if anything changed since the
     * preview (another batch wrote the same applicants meanwhile), it stays in preview.
     *
     * @return bool true when approved, false when it went back to preview
     */
    public function approve(ImportBatch $batch, User $user): bool
    {
        if ($batch->status !== ImportBatch::VALIDATED) {
            throw ValidationException::withMessages(['status' => 'Only a validated batch can be approved.']);
        }
        if ($batch->uploaded_by === $user->id) {
            throw ValidationException::withMessages(['approver' => 'You uploaded this batch, so someone else must approve it.']);
        }
        if ($batch->rows_new + $batch->rows_update + $batch->rows_unchanged === 0) {
            throw ValidationException::withMessages(['status' => 'There are no rows to write.']);
        }

        $before = $this->signature($batch);
        $this->classify($batch);
        if ($this->signature($batch) !== $before) {
            $batch->forceFill(['status_message' => 'The data changed since the preview (another batch wrote some of these applicants). Review the preview again.'])->save();
            $this->auditor->record('import.preview_changed', $batch, scope: $batch->scope());

            return false;
        }

        DB::transaction(function () use ($batch, $user) {
            $this->auditor->update('import.approved', $batch, [
                'status' => ImportBatch::APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'status_message' => null,
            ], $batch->scope());
            WriteBatch::dispatch($batch->id)->onQueue(config('import.queue'))->afterCommit();
        });

        return true;
    }

    public function retry(ImportBatch $batch): void
    {
        if (! in_array($batch->status, [ImportBatch::PARTIAL, ImportBatch::FAILED], true)) {
            throw ValidationException::withMessages(['status' => 'Only a failed or partially completed batch can be retried.']);
        }
        DB::transaction(function () use ($batch) {
            $this->auditor->update('import.retried', $batch, ['status' => ImportBatch::APPROVED, 'status_message' => null], $batch->scope());
            WriteBatch::dispatch($batch->id, careful: true)->onQueue(config('import.queue'))->afterCommit();
        });
    }

    /** Write approved rows in chunks. Each chunk is one transaction; a retry continues where it stopped. */
    public function write(ImportBatch $batch, bool $careful = false): void
    {
        if ($batch->isDocuments()) {
            app(DocumentImportPipeline::class)->write($batch, $careful);

            return;
        }
        $batch->refresh()->loadMissing('round');
        $batch->forceFill(['status' => ImportBatch::WRITING])->save();

        try {
            $batch->records()
                ->whereIn('classification', [ImportRecord::NEW, ImportRecord::UPDATE, ImportRecord::UNCHANGED])
                ->where('excluded', false)
                ->whereNull('written_at')
                ->chunkById(500, function ($records) use ($batch, $careful) {
                    DB::transaction(function () use ($records, $batch, $careful) {
                        $created = 0;
                        foreach ($records as $record) {
                            $created += $this->writeRecord($record, $batch, $careful) ? 1 : 0;
                        }
                        $batch->increment('rows_written', $records->count());
                        if ($created) {
                            $this->auditor->record('import.applications_created', $batch, null, ['count' => $created], scope: $batch->scope(), actorId: $batch->approved_by);
                        }
                    });
                });
        } catch (Throwable $e) {
            $batch->refresh();
            $status = $batch->rows_written > 0 ? ImportBatch::PARTIAL : ImportBatch::FAILED;
            $batch->forceFill(['status' => $status, 'status_message' => 'Writing stopped: '.class_basename($e).'. Rows already written are kept; retry to continue.'])->save();
            $this->auditor->record('import.write_failed', $batch, null, ['written' => $batch->rows_written, 'error' => class_basename($e)], scope: $batch->scope(), actorType: 'system');
            report($e);

            return;
        }

        $batch->refresh();
        $batch->forceFill(['status' => ImportBatch::COMPLETED, 'completed_at' => now()])->save();
        $this->auditor->record('import.completed', $batch, null, $this->counts($batch) + ['reconciled' => $batch->reconciles()], scope: $batch->scope(), actorId: $batch->approved_by);
    }

    /**
     * @param  bool  $careful  on a retry, look up "new" rows too: another batch may have written them meanwhile
     * @return bool true when a new application was created
     */
    private function writeRecord(ImportRecord $record, ImportBatch $batch, bool $careful): bool
    {
        $v = $record->mapped;
        $app = $record->application_id ? Application::with('applicant', 'entrance')->find($record->application_id) : null;
        // A row classified as new was re-checked at approval; if another batch wrote the same applicant since,
        // the unique key stops a duplicate, the chunk fails, and a retry finds the application here.
        if (! $app && ($record->classification !== ImportRecord::NEW || $careful)) {
            $app = Application::with('applicant', 'entrance')
                ->where('external_source_id', $batch->external_source_id)
                ->where('portal_application_no', $v['portal_application_no'])
                ->where('course_id', $v['course_id'])
                ->where('admission_cycle_id', $batch->round->admission_cycle_id)
                ->first();
        }
        $created = false;
        if (! $app) {
            $app = ApplicationRecord::create($v, $batch);
            $created = true;
        } else {
            $diff = ApplicationRecord::diff($app, $v);
            if ($diff) {
                ApplicationRecord::update($app, $v, $batch);
                $this->auditor->record(
                    'application.import_updated', $app,
                    array_map(fn ($d) => $d['old'], $diff), array_map(fn ($d) => $d['new'], $diff),
                    "Batch {$batch->uid}, row {$record->row_number}", $app->scope(), $batch->approved_by,
                );
            }
        }
        ApplicationRecord::addToRound($app, $batch, $created);
        $record->forceFill(['application_id' => $app->id, 'written_at' => now()])->save();

        return $created;
    }

    // ----- Helpers ----------------------------------------------------------------------------

    public function counts(ImportBatch $batch): array
    {
        return collect(['total', 'error', 'duplicate', 'new', 'update', 'unchanged', 'excluded', 'written'])
            ->mapWithKeys(fn ($k) => [$k => $batch->{"rows_$k"}])->all();
    }

    private function signature(ImportBatch $batch): string
    {
        $ctx = hash_init('sha256');
        $batch->records()->orderBy('id')->select(['id', 'classification', 'diff', 'excluded'])
            ->chunk(5000, function ($rows) use ($ctx) {
                foreach ($rows as $r) {
                    hash_update($ctx, $r->id.'|'.$r->classification.'|'.json_encode($r->diff).'|'.(int) $r->excluded."\n");
                }
            });

        return hash_final($ctx);
    }

    private function ensureEditable(ImportBatch $batch): void
    {
        if (! in_array($batch->status, [ImportBatch::UPLOADED, ImportBatch::MAPPED, ImportBatch::VALIDATED], true)) {
            throw ValidationException::withMessages(['status' => 'This batch can no longer be changed.']);
        }
    }

    /** Run a callback with a local path to the stored file (downloads it first when storage is remote). */
    private function withLocalFile(ImportBatch $batch, callable $fn): mixed
    {
        $disk = Storage::disk('private');
        if (config('filesystems.disks.private.driver') === 'local') {
            return $fn($disk->path($batch->file_path));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        try {
            $in = $disk->readStream($batch->file_path);
            $out = fopen($tmp, 'w');
            stream_copy_to_stream($in, $out);
            fclose($out);

            return $fn($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
