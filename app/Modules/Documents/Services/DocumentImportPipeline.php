<?php

namespace App\Modules\Documents\Services;

use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Imports\Jobs\StageBatch;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportError;
use App\Modules\Imports\Models\ImportRecord;
use App\Modules\Imports\Services\FileReader;
use App\Modules\Imports\Services\ImportPipeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Document imports (ZIP archives and download links): stage → check → preview → approve → write,
 * using the same batches, records and approval rules as data imports. Files are only attached to
 * applications after approval; a missing or failed document never affects the application itself.
 */
class DocumentImportPipeline
{
    public const URL_HEADERS = ['portal_application_no', 'course', 'document_type', 'url'];

    public function __construct(
        private Auditor $auditor,
        private FileInspector $inspector,
        private DocumentStore $store,
        private UrlFetcher $fetcher,
        private FileReader $reader,
    ) {}

    // ----- Upload ---------------------------------------------------------------------------------

    public function upload(UploadedFile $file, string $channel, ExternalSource $source, AdmissionRound $round, User $user): ImportBatch
    {
        $sha = hash_file('sha256', $file->getRealPath());
        $same = ImportBatch::where('admission_round_id', $round->id)->where('file_sha256', $sha)->where('status', '!=', ImportBatch::CANCELLED)->first();
        if ($same) {
            throw ValidationException::withMessages(['file' => "This file was already uploaded for this round (batch {$same->uid})."]);
        }
        $rows = $channel === 'zip' ? $this->inspectZip($file->getRealPath()) : $this->inspectUrlList($file->getRealPath());

        $batch = DB::transaction(function () use ($file, $channel, $source, $round, $user, $sha, $rows) {
            $batch = ImportBatch::create([
                'external_source_id' => $source->id, 'admission_round_id' => $round->id, 'channel' => $channel,
                'status' => ImportBatch::STAGING, 'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_sha256' => $sha, 'rows_total' => $rows, 'uploaded_by' => $user->id,
            ]);
            $ext = $channel === 'zip' ? 'zip' : 'csv';
            $batch->file_path = Storage::disk('private')->putFileAs("imports/{$batch->uid}", $file, "source.$ext");
            $batch->save();
            $this->auditor->record('import.uploaded', $batch, null, ['file' => $batch->original_filename, 'files' => $rows, 'kind' => $channel], scope: $batch->scope());
            StageBatch::dispatch($batch->id)->onQueue(config('import.queue'))->afterCommit();

            return $batch;
        });

        return $batch;
    }

    /** Portal push of document links. */
    public function push(array $documents, string $idempotencyKey, ExternalSource $source, AdmissionRound $round): ImportBatch
    {
        return DB::transaction(function () use ($documents, $idempotencyKey, $source, $round) {
            $batch = ImportBatch::create([
                'external_source_id' => $source->id, 'admission_round_id' => $round->id, 'channel' => 'urls',
                'status' => ImportBatch::STAGING, 'idempotency_key' => $idempotencyKey, 'rows_total' => count($documents),
            ]);
            foreach (array_values($documents) as $i => $doc) {
                ImportRecord::create(['import_batch_id' => $batch->id, 'row_number' => $i + 1, 'raw' => array_map(fn ($v) => is_scalar($v) || $v === null ? $v : null, $doc)]);
            }
            $this->auditor->record('import.pushed', $batch, null, ['documents' => count($documents), 'round' => $round->uid], scope: $batch->scope(), actorType: 'import');
            StageBatch::dispatch($batch->id)->onQueue(config('import.queue'))->afterCommit();

            return $batch;
        });
    }

    /** ZIP safety checks before anything is unpacked. @return int number of files */
    public function inspectZip(string $path): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'This server cannot open ZIP files: the PHP extension zip is not enabled. Import the files by link list (CSV) instead, or ask the host to enable zip.']);
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => 'The file is not a readable ZIP archive.']);
        }
        try {
            $cfg = config('documents.zip');
            if ($zip->numFiles > $cfg['max_files']) {
                throw ValidationException::withMessages(['file' => 'The ZIP has more than '.number_format($cfg['max_files']).' files.']);
            }
            $total = 0;
            $files = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if ($this->unsafePath($name)) {
                    throw ValidationException::withMessages(['file' => "The ZIP contains an unsafe path: $name"]);
                }
                $total += $stat['size'];
                if ($total > $cfg['max_unpacked_bytes']) {
                    throw ValidationException::withMessages(['file' => 'The ZIP unpacks to more than 2 GB.']);
                }
                if ($stat['comp_size'] > 0 && $cfg['max_ratio'] < $stat['size'] / $stat['comp_size'] && $stat['size'] > 1_000_000) {
                    throw ValidationException::withMessages(['file' => "The ZIP looks like a compression bomb ($name)."]);
                }
                if (! str_ends_with($name, '/') && strtolower(basename($name)) !== 'manifest.csv') {
                    $files++;
                }
            }

            return $files;
        } finally {
            $zip->close();
        }
    }

    private function inspectUrlList(string $path): int
    {
        try {
            $info = $this->reader->inspect($path, 'csv');
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'The link list could not be read. Upload a CSV with a header row.']);
        }
        $missing = array_diff(['portal_application_no', 'document_type', 'url'], array_map('strtolower', $info['headers']));
        if ($missing) {
            throw ValidationException::withMessages(['file' => 'The link list needs the columns: '.implode(', ', self::URL_HEADERS).' (course is optional).']);
        }

        return $info['rows'];
    }

    private function unsafePath(string $name): bool
    {
        return str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name) || str_contains($name, "\0");
    }

    // ----- Staging ------------------------------------------------------------------------------

    public function stage(ImportBatch $batch): void
    {
        $batch->refresh()->loadMissing('round');
        ImportError::where('import_batch_id', $batch->id)->delete();
        $types = DocumentType::where('status', 'active')->get()->keyBy(fn ($t) => strtoupper($t->code));

        if ($batch->channel === 'zip') {
            $this->stageZip($batch, $types);
        } else {
            $this->stageUrls($batch, $types);
        }

        $this->classify($batch);
        $batch->forceFill(['status' => ImportBatch::VALIDATED])->save();
        $this->auditor->record('import.validated', $batch, null, app(ImportPipeline::class)->counts($batch), scope: $batch->scope(), actorType: 'system');
    }

    private function stageZip(ImportBatch $batch, $types): void
    {
        ImportRecord::where('import_batch_id', $batch->id)->delete();
        $local = $this->localCopy($batch->file_path);
        $zip = new ZipArchive;
        $zip->open($local, ZipArchive::RDONLY);
        try {
            $manifest = $this->manifest($zip);
            $row = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if (str_ends_with($name, '/') || strtolower(basename($name)) === 'manifest.csv') {
                    continue;
                }
                $row++;
                $record = ImportRecord::create(['import_batch_id' => $batch->id, 'row_number' => $row, 'raw' => ['file' => $name]]);
                $opsys = $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    $this->error($record, $batch, 'file', 'Links inside the ZIP are not allowed.');

                    continue;
                }
                $target = $manifest[$name] ?? $this->fromName($name, $types);
                if ($target === null) {
                    $this->error($record, $batch, 'file', 'Cannot tell whose document this is. Name it like PRTM0000001_CLASS10.pdf, put it in a folder named after the application number, or list it in manifest.csv.');

                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'zip');
                $in = $zip->getStream($name);
                $out = fopen($tmp, 'wb');
                stream_copy_to_stream($in, $out, 100 * 1024 * 1024);
                fclose($out);
                fclose($in);
                $this->stageFile($record, $batch, $types, $target + ['file' => $name], $tmp, basename($name));
            }
            $batch->forceFill(['rows_total' => $row, 'rows_processed' => $row])->save();
        } finally {
            $zip->close();
            @unlink($local);
        }
    }

    private function stageUrls(ImportBatch $batch, $types): void
    {
        if ($batch->file_path) { // uploaded link list: (re)create records from the file
            ImportRecord::where('import_batch_id', $batch->id)->delete();
            $local = $this->localCopy($batch->file_path);
            $n = 0;
            foreach ($this->reader->rows($local, 'csv') as $line => $raw) {
                ImportRecord::create(['import_batch_id' => $batch->id, 'row_number' => $line, 'raw' => array_change_key_case($raw)]);
                $n++;
            }
            @unlink($local);
            $batch->forceFill(['rows_total' => $n])->save();
        }
        $batch->records()->orderBy('row_number')->each(function (ImportRecord $record) use ($batch, $types) {
            $raw = $record->raw;
            $target = [
                'portal_application_no' => strtoupper(trim((string) ($raw['portal_application_no'] ?? ''))),
                'course' => strtoupper(trim((string) ($raw['course'] ?? ''))) ?: null,
                'document_type' => strtoupper(trim((string) ($raw['document_type'] ?? ''))),
                'url' => trim((string) ($raw['url'] ?? '')),
            ];
            if ($target['portal_application_no'] === '' || $target['document_type'] === '' || $target['url'] === '') {
                $this->error($record, $batch, 'url', 'Application number, document type and URL are all needed.');

                return;
            }
            $type = $types[$target['document_type']] ?? null;
            try {
                $tmp = $this->fetcher->fetch($target['url'], ($type?->max_size_kb ?? 10240) * 1024);
            } catch (Throwable $e) {
                $this->error($record, $batch, 'url', 'Download failed: '.$e->getMessage());

                return;
            }
            $this->stageFile($record, $batch, $types, $target, $tmp, basename((string) parse_url($target['url'], PHP_URL_PATH)) ?: 'document');
            $batch->increment('rows_processed');
        });
    }

    /** Check one file, scan it, and keep it in the batch's staging area until approval. */
    private function stageFile(ImportRecord $record, ImportBatch $batch, $types, array $target, string $tmp, string $originalName): void
    {
        try {
            $type = $types[strtoupper($target['document_type'])] ?? null;
            if (! $type) {
                $this->error($record, $batch, 'document_type', "Unknown document type \"{$target['document_type']}\".");

                return;
            }
            $app = $this->findApplication($batch, $target['portal_application_no'], $target['course'] ?? null, $error);
            if (! $app) {
                $this->error($record, $batch, 'portal_application_no', $error);

                return;
            }
            if ($this->inspector->kind($tmp) === null && str_starts_with((string) file_get_contents($tmp, false, null, 0, 4), "PK\x03\x04")) {
                $this->error($record, $batch, 'file', 'Archives inside the ZIP are not allowed.');

                return;
            }
            if ($problem = $this->inspector->problem($tmp, $type->allowed_types, $type->max_size_kb)) {
                $this->error($record, $batch, 'file', $problem);

                return;
            }
            $scan = app(DocumentScanner::class)->scan($tmp);
            if ($scan['status'] === 'infected') {
                $this->error($record, $batch, 'file', 'Virus found; the file was not kept ('.$scan['detail'].').');
                $this->auditor->record('import.virus_found', $batch, null, ['row' => $record->row_number, 'result' => $scan['detail']], scope: $batch->scope(), actorType: 'system');

                return;
            }
            $staged = "imports/{$batch->uid}/files/{$record->id}";
            $in = fopen($tmp, 'rb');
            Storage::disk('private')->writeStream($staged, $in);
            is_resource($in) && fclose($in);

            $record->forceFill(['mapped' => [
                'portal_application_no' => $app->portal_application_no,
                'course' => $app->course->code,
                'full_name' => $app->applicant->full_name,
                'document_type' => $type->code,
                'document_type_id' => $type->id,
                'application_id' => $app->id,
                'staged_path' => $staged,
                'original_filename' => $originalName,
                'sha256' => hash_file('sha256', $tmp),
                'url' => $target['url'] ?? null,
                'scan' => $scan['status'] === 'clean' ? $scan : null,
            ], 'classification' => null])->save();
        } finally {
            @unlink($tmp);
        }
    }

    private function findApplication(ImportBatch $batch, string $portal, ?string $course, ?string &$error): ?Application
    {
        $q = Application::with('course', 'applicant')
            ->where('external_source_id', $batch->external_source_id)
            ->where('admission_cycle_id', $batch->round->admission_cycle_id)
            ->where('portal_application_no', strtoupper($portal));
        if ($course) {
            $q->whereHas('course', fn ($c) => $c->where('code', strtoupper($course)));
        }
        $apps = $q->limit(2)->get();
        if ($apps->count() === 1) {
            return $apps->first();
        }
        $error = $apps->isEmpty()
            ? "No application $portal in this cycle. Import the applicant's data first; the document is not attached."
            : "Application $portal exists for more than one course; give the course.";

        return null;
    }

    /** @return array<string, array{portal_application_no: string, course: ?string, document_type: string}> */
    private function manifest(ZipArchive $zip): array
    {
        $index = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (strtolower(basename($zip->getNameIndex($i))) === 'manifest.csv') {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return [];
        }
        $lines = array_map('str_getcsv', preg_split('/\r?\n/', trim((string) $zip->getFromIndex($index))));
        $head = array_map(fn ($h) => strtolower(trim($h)), array_shift($lines) ?? []);
        $prefix = trim(dirname($zip->getNameIndex($index)), './');
        $out = [];
        foreach ($lines as $line) {
            $row = @array_combine($head, array_pad($line, count($head), null));
            if (! $row || empty($row['file'])) {
                continue;
            }
            $path = ltrim(($prefix ? $prefix.'/' : '').trim($row['file']), '/');
            $out[$path] = [
                'portal_application_no' => strtoupper(trim((string) ($row['portal_application_no'] ?? ''))),
                'course' => strtoupper(trim((string) ($row['course'] ?? ''))) ?: null,
                'document_type' => strtoupper(trim((string) ($row['document_type'] ?? ''))),
            ];
        }

        return $out;
    }

    /**
     * Who and what a file is, from its name, using the known document type codes:
     *   PRTM0000001/CLASS10.pdf         (folder named after the application number)
     *   PRTM0000001_GRAD_MARKSHEET.pdf  (application number, underscore, type code)
     */
    private function fromName(string $name, $types): ?array
    {
        $base = strtoupper(pathinfo($name, PATHINFO_FILENAME));
        $dir = basename(dirname($name));
        if (isset($types[$base]) && $dir !== '.' && $dir !== '') {
            return ['portal_application_no' => strtoupper($dir), 'course' => null, 'document_type' => $base];
        }
        $codes = $types->keys()->sortByDesc(fn ($c) => strlen($c));
        foreach ($codes as $code) {
            if (str_ends_with($base, '_'.$code) && strlen($base) > strlen($code) + 1) {
                return ['portal_application_no' => substr($base, 0, -strlen($code) - 1), 'course' => null, 'document_type' => $code];
            }
        }

        return null;
    }

    private function error(ImportRecord $record, ImportBatch $batch, string $field, string $message): void
    {
        $record->forceFill(['classification' => ImportRecord::ERROR])->save();
        ImportError::create([
            'import_batch_id' => $batch->id, 'import_record_id' => $record->id, 'row_number' => $record->row_number,
            'field' => $field, 'message' => mb_substr($message, 0, 500), 'severity' => 'error',
        ]);
    }

    // ----- Classify, write ----------------------------------------------------------------------

    public function classify(ImportBatch $batch): void
    {
        $seen = [];
        $types = DocumentType::all()->keyBy('id');
        $batch->records()->where(fn ($q) => $q->whereNull('classification')->orWhere('classification', '!=', ImportRecord::ERROR))
            ->orderBy('row_number')->each(function (ImportRecord $r) use (&$seen, $types) {
                $m = $r->mapped;
                $key = $m['application_id'].'|'.$m['document_type_id'];
                if (isset($seen[$key])) {
                    $r->forceFill(['classification' => ImportRecord::DUPLICATE, 'diff' => ['first_row' => $seen[$key]]])->save();

                    return;
                }
                $seen[$key] = $r->row_number;
                $outcome = $this->store->classify(Application::find($m['application_id']), $types[$m['document_type_id']], $m['sha256']);
                $r->forceFill(['classification' => $outcome, 'application_id' => $m['application_id'], 'diff' => null])->save();
            });
        app(ImportPipeline::class)->recount($batch);
    }

    public function write(ImportBatch $batch, bool $careful = false): void
    {
        $batch->refresh()->loadMissing('round');
        $batch->forceFill(['status' => ImportBatch::WRITING])->save();
        $types = DocumentType::all()->keyBy('id');
        try {
            $batch->records()->whereIn('classification', [ImportRecord::NEW, ImportRecord::UPDATE, ImportRecord::UNCHANGED])
                ->where('excluded', false)->whereNull('written_at')
                ->chunkById(100, function ($records) use ($batch, $types) {
                    foreach ($records as $r) {
                        $m = $r->mapped;
                        if ($r->classification !== ImportRecord::UNCHANGED) {
                            $tmp = $this->localCopy($m['staged_path']);
                            try {
                                $this->store->store(Application::find($m['application_id']), $types[$m['document_type_id']], $tmp, $m['original_filename'], [
                                    'source' => $batch->channel === 'zip' ? 'zip' : 'url', 'batch' => $batch, 'url' => $m['url'] ?? null, 'scan' => $m['scan'] ?? null,
                                ]);
                            } finally {
                                @unlink($tmp);
                            }
                        }
                        DB::transaction(function () use ($r, $batch) {
                            $r->forceFill(['written_at' => now()])->save();
                            $batch->increment('rows_written');
                        });
                        if ($r->classification !== ImportRecord::UNCHANGED) {
                            Storage::disk('private')->delete($m['staged_path']);
                        }
                    }
                });
        } catch (Throwable $e) {
            $batch->refresh();
            $batch->forceFill([
                'status' => $batch->rows_written > 0 ? ImportBatch::PARTIAL : ImportBatch::FAILED,
                'status_message' => 'Writing stopped: '.class_basename($e).'. Files already attached are kept; retry to continue.',
            ])->save();
            report($e);

            return;
        }
        $batch->refresh()->forceFill(['status' => ImportBatch::COMPLETED, 'completed_at' => now()])->save();
        $this->auditor->record('import.completed', $batch, null, app(ImportPipeline::class)->counts($batch) + ['reconciled' => $batch->reconciles()],
            scope: $batch->scope(), actorId: $batch->approved_by);
    }

    private function localCopy(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        $in = Storage::disk('private')->readStream($path);
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);

        return $tmp;
    }
}
