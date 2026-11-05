<?php

namespace App\Modules\Documents\Services;

use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Documents\Jobs\ScanDocumentVersion;
use App\Modules\Documents\Models\ApplicationDocument;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Keeps document files: every new file is a new version; old versions are kept (superseded).
 * A file is viewable only after it has been scanned clean.
 */
class DocumentStore
{
    public const NEW_DOCUMENT = 'new';

    public const NEW_VERSION = 'update';

    public const UNCHANGED = 'unchanged';

    public function __construct(private FileInspector $inspector, private Auditor $auditor) {}

    /** What storing this file would do: new document, new version, or unchanged (same content). */
    public function classify(Application $app, DocumentType $type, string $sha256): string
    {
        $doc = ApplicationDocument::with('currentVersion')->where(['application_id' => $app->id, 'document_type_id' => $type->id])->first();
        if (! $doc) {
            return self::NEW_DOCUMENT;
        }

        return $doc->currentVersion?->sha256 === $sha256 ? self::UNCHANGED : self::NEW_VERSION;
    }

    /**
     * Store a local file as the current version of an application's document.
     *
     * @param  array{source: string, batch?: ?ImportBatch, url?: ?string, user?: ?User, scan?: ?array{status: string, detail: string}}  $meta
     * @return array{0: ?DocumentVersion, 1: string} the new version (null when unchanged) and the outcome
     */
    public function store(Application $app, DocumentType $type, string $localPath, string $originalName, array $meta): array
    {
        if ($problem = $this->inspector->problem($localPath, $type->allowed_types, $type->max_size_kb)) {
            throw ValidationException::withMessages(['file' => $problem]);
        }
        $sha = hash_file('sha256', $localPath);
        $outcome = $this->classify($app, $type, $sha);
        if ($outcome === self::UNCHANGED) {
            return [null, $outcome];
        }
        $kind = $this->inspector->kind($localPath);

        $version = DB::transaction(function () use ($app, $type, $localPath, $originalName, $meta, $sha, $kind) {
            $doc = ApplicationDocument::lockForUpdate()->firstOrCreate(
                ['application_id' => $app->id, 'document_type_id' => $type->id],
                ['status' => 'received'],
            );
            $number = (int) DocumentVersion::where('application_document_id', $doc->id)->max('version') + 1;
            $version = new DocumentVersion([
                'application_document_id' => $doc->id,
                'version' => $number,
                'original_filename' => mb_substr(basename($originalName), 0, 255),
                'mime_type' => config("documents.mime.$kind"),
                'size_bytes' => filesize($localPath),
                'sha256' => $sha,
                'source' => $meta['source'],
                'import_batch_id' => ($meta['batch'] ?? null)?->id,
                'source_url' => $meta['url'] ?? null,
                'uploaded_by' => ($meta['user'] ?? null)?->id,
                'scan_status' => $meta['scan']['status'] ?? 'pending',
                'scan_result' => $meta['scan']['detail'] ?? null,
                'scanned_at' => isset($meta['scan']) ? now() : null,
                'storage_path' => '',
            ]);
            $version->save();
            $ext = $kind === 'jpeg' ? 'jpg' : $kind;
            $path = "documents/{$app->uid}/{$type->code}/{$version->uid}.{$ext}";
            $in = fopen($localPath, 'rb');
            Storage::disk('private')->writeStream($path, $in);
            is_resource($in) && fclose($in);
            $version->forceFill(['storage_path' => $path])->save();

            DocumentVersion::where('application_document_id', $doc->id)->where('id', '!=', $version->id)
                ->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $old = $doc->status;
            $doc->forceFill(['current_version_id' => $version->id, 'status' => 'received'])->save();

            $this->auditor->record('document.version_added', $app, ['document' => $type->code, 'status' => $old], [
                'document' => $type->code, 'version' => $number, 'source' => $meta['source'], 'size' => $version->size_bytes,
            ], scope: $app->scope(), actorId: ($meta['user'] ?? null)?->id ?? ($meta['batch'] ?? null)?->approved_by);

            return $version;
        });

        if ($version->scan_status === 'pending') {
            ScanDocumentVersion::dispatch($version->id)->onQueue(config('import.queue'))->afterCommit();
        }
        Event::dispatch('documents.received', [$app]);

        return [$version, $outcome];
    }

    /** Scan a stored version; infected files are kept in quarantine and never served. */
    public function scan(DocumentVersion $version): void
    {
        if ($version->scan_status !== 'pending') {
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'scan');
        try {
            $in = Storage::disk('private')->readStream($version->storage_path);
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            $result = app(DocumentScanner::class)->scan($tmp);
        } finally {
            @unlink($tmp);
        }
        if ($result['status'] === 'error') {
            return; // stays pending; documents:scan-pending tries again
        }
        DB::transaction(function () use ($version, $result) {
            $version->forceFill(['scan_status' => $result['status'], 'scan_result' => $result['detail'], 'scanned_at' => now()])->save();
            if ($result['status'] === 'infected') {
                $app = $version->document->application;
                $this->auditor->record('document.quarantined', $app, null, [
                    'document' => $version->document->documentType->code, 'version' => $version->version, 'result' => $result['detail'],
                ], scope: $app->scope(), actorType: 'system');
            }
        });
    }
}
