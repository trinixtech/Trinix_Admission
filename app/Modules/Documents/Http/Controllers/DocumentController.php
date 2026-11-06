<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Services\ApplicationAccess;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Models\FileAccessLog;
use App\Modules\Documents\Services\DocumentChecklist;
use App\Modules\Documents\Services\DocumentImportPipeline;
use App\Modules\Documents\Services\DocumentStore;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Selection\Services\PanelAccess;
use App\Support\Rules\UploadedTable;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DocumentController extends Controller
{
    public function __construct(private ApplicationAccess $applications, private DocumentChecklist $checklist) {}

    public function index(Application $application): JsonResponse
    {
        $this->ensure($application, 'documents.view');

        return response()->json(['data' => $this->checklist->for($application), 'can_upload' => $this->applications->allows($this->actor(), 'documents.manage', $application)]);
    }

    /** Staff attach one document, e.g. one the applicant sent by email after a deficiency notice. */
    public function upload(Request $request, Application $application, DocumentStore $store): JsonResponse
    {
        $this->ensure($application, 'documents.view');
        $this->ensure($application, 'documents.manage', 403);
        $data = $request->validate([
            'document_type_uid' => ['required', Rule::exists('document_types', 'uid')->where('status', 'active')],
            'file' => ['required', 'file', 'max:51200'],
        ]);
        $type = DocumentType::where('uid', $data['document_type_uid'])->firstOrFail();
        $file = $request->file('file');
        [$version, $outcome] = $store->store($application, $type, $file->getRealPath(), $file->getClientOriginalName(), ['source' => 'manual', 'user' => $this->actor()]);

        return response()->json([
            'message' => $outcome === DocumentStore::UNCHANGED ? 'This exact file is already the current version.' : 'Document received. It can be opened once the virus scan finishes.',
            'data' => $this->checklist->for($application->fresh()),
        ], $version ? 201 : 200);
    }

    /** A 5-minute link for one file, after the permission check. */
    public function link(DocumentVersion $version): JsonResponse
    {
        $app = $version->document->application;
        $this->ensure($app, 'documents.view');
        if ($version->scan_status !== 'clean') {
            $this->fail('document', $version->scan_status === 'infected' ? 'This file is quarantined: a virus was found.' : 'This file is still being scanned.');
        }
        $url = URL::temporarySignedRoute('files.show', now()->addMinutes(config('documents.download_link_minutes')),
            ['version' => $version->uid, 'u' => $this->actor()->uid]);

        return response()->json(['url' => $url]);
    }

    /** Serves a file to the user the link was made for. Signed, short-lived, and logged. */
    public function file(Request $request, DocumentVersion $version): StreamedResponse
    {
        $user = $request->user();
        if (! $user || $request->query('u') !== $user->uid || $version->scan_status !== 'clean') {
            throw new NotFoundHttpException;
        }
        $app = $version->document->application;
        $this->ensure($app, 'documents.view');
        FileAccessLog::create([
            'document_version_id' => $version->id, 'user_id' => $user->id,
            'action' => $request->boolean('download') ? 'download' : 'view', 'ip' => $request->ip(), 'occurred_at' => now(),
        ]);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$version->mime_type] ?? 'bin';
        $name = "{$app->portal_application_no}-{$version->document->documentType->code}-v{$version->version}.$ext";

        return Storage::disk('private')->response($version->storage_path, $name, [
            'Content-Type' => $version->mime_type,
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline')."; filename=\"$name\"",
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** ZIP or link-list upload for a round: staged, checked and previewed like a data import. */
    public function import(Request $request, DocumentImportPipeline $pipeline): JsonResponse
    {
        $this->access()->authorizeAnywhere($this->actor(), 'documents.manage');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['zip', 'urls'])],
            'source_uid' => ['required', Rule::exists('external_sources', 'uid')],
            'round_uid' => ['required', Rule::exists('admission_rounds', 'uid')],
            'file' => ['required', 'file', new UploadedTable($request->input('kind') === 'zip' ? ['zip'] : ['csv', 'txt']), 'max:'.(2 * 1024 * 1024)],
        ]);
        $round = AdmissionRound::with('cycle')->where('uid', $data['round_uid'])->firstOrFail();
        $this->access()->authorize($this->actor(), 'documents.manage', Scope::cycle($round->admission_cycle_id));
        $this->access()->authorize($this->actor(), 'imports.manage', Scope::cycle($round->admission_cycle_id));
        if ($round->isClosed() || $round->cycle->isClosed()) {
            $this->fail('round_uid', 'This round is closed.');
        }
        $batch = $pipeline->upload($request->file('file'), $data['kind'], ExternalSource::where('uid', $data['source_uid'])->firstOrFail(), $round, $this->actor());

        return response()->json(['data' => ['uid' => $batch->uid]], 201);
    }

    private function ensure(Application $application, string $permission, int $status = 404): void
    {
        // Panel members may view the documents of candidates on their own panels (D24); file openings are logged.
        if ($permission === 'documents.view' && ! $this->applications->allows($this->actor(), $permission, $application)) {
            if (PanelAccess::canSeeApplication($this->actor(), $application)) {
                return;
            }
            if (! $this->access()->hasAnywhere($this->actor(), $permission) && PanelAccess::onAnyPanel($this->actor())) {
                throw new NotFoundHttpException;
            }
        }
        $this->access()->authorizeAnywhere($this->actor(), $permission);
        if (! $this->applications->allows($this->actor(), $permission, $application)) {
            $status === 404 ? throw new NotFoundHttpException : abort(403, 'This application is outside your assigned scope.');
        }
    }
}
