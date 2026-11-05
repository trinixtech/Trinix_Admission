<?php

use App\Modules\Applicants\Models\Application;
use App\Modules\Documents\Models\ApplicationDocument;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Models\FileAccessLog;
use App\Modules\Documents\Services\EicarScanner;
use App\Modules\Documents\Services\UrlFetcher;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportError;
use App\Modules\Imports\Models\ImportRecord;
use App\Modules\System\Models\SystemSetting;
use App\Support\Scope;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    EicarScanner::$down = false;
    UrlFetcher::$resolver = fn ($host) => ['203.0.113.10'];
    importSetup($this);
    importApplicants($this, 3);
    $this->actingAs($this->approver)->postJson('/api/v1/document-types/suggested')->assertOk();
});

function docImport(object $t, UploadedFile $file, string $kind = 'zip'): ImportBatch
{
    $uid = $t->actingAs($t->officer)->post('/api/v1/document-imports', [
        'kind' => $kind, 'source_uid' => $t->source->uid, 'round_uid' => $t->round1->uid, 'file' => $file,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.uid');

    return ImportBatch::where('uid', $uid)->firstOrFail();
}

it('loads the suggested document types once and lets the admin add and edit types', function () {
    expect(DocumentType::count())->toBe(9);
    $this->actingAs($this->approver)->postJson('/api/v1/document-types/suggested')->assertOk()->assertJsonPath('message', '0 suggested document types added.');
    $this->postJson('/api/v1/document-types', ['code' => 'NOC', 'name' => 'No objection certificate', 'allowed_types' => ['pdf'], 'max_size_kb' => 2048])->assertCreated();
    $this->postJson('/api/v1/document-types', ['code' => 'bad code', 'name' => 'x', 'allowed_types' => ['pdf'], 'max_size_kb' => 2048])->assertStatus(422);
    $noc = DocumentType::where('code', 'NOC')->first();
    $this->patchJson("/api/v1/document-types/{$noc->uid}", ['status' => 'inactive'])->assertOk();
    $this->actingAs(userWithRole('admission_staff'))->postJson('/api/v1/document-types', ['code' => 'X1', 'name' => 'x', 'allowed_types' => ['pdf'], 'max_size_kb' => 100])->assertForbidden();
});

it('imports a ZIP matched by file names, folders and a manifest, after approval', function () {
    $batch = docImport($this, makeZip([
        'PRTM0000001_CLASS10.pdf' => fakePdf('a10'),
        'PRTM0000001_GRAD_MARKSHEET.pdf' => fakePdf('agrad'),
        'PRTM0000002/CLASS12.pdf' => fakePdf('b12'),
        'scans/x.pdf' => fakePdf('c10'),
        'manifest.csv' => "portal_application_no,course,document_type,file\nPRTM0000003,MBA-IB,CLASS10,scans/x.pdf\n",
    ]));
    expect($batch->status)->toBe('validated')->and($batch->rows_new)->toBe(4)->and($batch->rows_error)->toBe(0)
        ->and(ApplicationDocument::count())->toBe(0);

    approve($this, $batch)->assertStatus(202);
    $batch->refresh();
    expect($batch->status)->toBe('completed')->and($batch->reconciles())->toBeTrue()->and(ApplicationDocument::count())->toBe(4)
        ->and(DocumentVersion::pluck('scan_status')->unique()->all())->toBe(['clean']);
    $app = Application::where('portal_application_no', 'PRTM0000002')->first();
    expect(ApplicationDocument::where('application_id', $app->id)->first()->documentType->code)->toBe('CLASS12');
});

it('reports bad files in a ZIP and never attaches them', function () {
    $batch = docImport($this, makeZip([
        'PRTM0000001_CLASS10.pdf' => eicarPdf(),
        'PRTM0000002_CLASS10.pdf' => 'MZ this is an exe pretending to be a pdf',
        'PRTM9999999_CLASS10.pdf' => fakePdf(),
        'PRTM0000003_UNKNOWNTYPE.pdf' => fakePdf(),
        'random.pdf' => fakePdf(),
        'PRTM0000003_CLASS12.zip' => file_get_contents(makeZip(['inner.pdf' => fakePdf()])->getRealPath()),
        'PRTM0000003_CLASS10.pdf' => fakePdf('ok'),
    ]));
    expect($batch->rows_error)->toBe(6)->and($batch->rows_new)->toBe(1);
    $messages = collect($this->actingAs($this->officer)->getJson("/api/v1/import-batches/{$batch->uid}/errors?per_page=50")->json('data'))->pluck('message')->implode(' | ');
    expect($messages)->toContain('Virus found')->toContain('not a PDF, JPEG or PNG')->toContain('No application PRTM9999999')
        ->toContain('Cannot tell whose document')->toContain('Archives inside the ZIP');

    approve($this, $batch)->assertStatus(202);
    expect(ApplicationDocument::count())->toBe(1)->and($batch->fresh()->reconciles())->toBeTrue();
    expect(collect(Storage::disk('private')->allFiles())->filter(fn ($f) => str_contains($f, '/files/'))->all())->toBe([]);
});

it('refuses unsafe ZIP archives at upload', function () {
    $this->actingAs($this->officer)->post('/api/v1/document-imports', [
        'kind' => 'zip', 'source_uid' => $this->source->uid, 'round_uid' => $this->round1->uid,
        'file' => makeZip(['../../etc/passwd.pdf' => fakePdf()]),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'unsafe path'));

    $bomb = makeZip(['PRTM0000001_CLASS10.pdf' => fakePdf().str_repeat('0', 5_000_000)]);
    $this->post('/api/v1/document-imports', [
        'kind' => 'zip', 'source_uid' => $this->source->uid, 'round_uid' => $this->round1->uid, 'file' => $bomb,
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'compression bomb'));
});

it('keeps old versions: same file is unchanged, a different file is a new version', function () {
    approve($this, docImport($this, makeZip(['PRTM0000001_CLASS10.pdf' => fakePdf('v1')])))->assertStatus(202);
    $again = docImport($this, makeZip(['PRTM0000001_CLASS10.pdf' => fakePdf('v1'), 'PRTM0000002_CLASS10.pdf' => fakePdf('b')], 'second.zip'));
    expect($again->rows_unchanged)->toBe(1)->and($again->rows_new)->toBe(1);
    approve($this, $again)->assertStatus(202);

    $third = docImport($this, makeZip(['PRTM0000001_CLASS10.pdf' => fakePdf('v2')], 'third.zip'));
    expect($third->rows_update)->toBe(1);
    approve($this, $third)->assertStatus(202);

    $doc = ApplicationDocument::whereHas('application', fn ($q) => $q->where('portal_application_no', 'PRTM0000001'))->first();
    expect($doc->versions()->count())->toBe(2)->and($doc->currentVersion->version)->toBe(2)
        ->and($doc->versions()->where('version', 1)->first()->superseded_at)->not->toBeNull();
});

it('downloads documents from allowlisted links pushed by the portal', function () {
    SystemSetting::where('key', 'document_url_hosts')->update(['value' => json_encode(['files.portal.example'])]);
    Settings::forget();
    Http::fake([
        'https://files.portal.example/a.pdf' => Http::response(fakePdf('url'), 200),
        'https://files.portal.example/missing.pdf' => Http::response('', 404),
        'https://files.portal.example/redirect.pdf' => Http::response('', 302, ['Location' => 'https://evil.example/x.pdf']),
    ]);
    $token = $this->actingAs(userWithRole('super_admin'))->postJson("/api/v1/sources/{$this->source->uid}/tokens", ['name' => 'p'])->json('data.token');
    signOut();

    $res = $this->withToken($token)->postJson('/api/v1/integration/document-batches', [
        'round' => $this->round1->uid, 'idempotency_key' => 'docs-1', 'documents' => [
            ['portal_application_no' => 'PRTM0000001', 'document_type' => 'CLASS10', 'url' => 'https://files.portal.example/a.pdf'],
            ['portal_application_no' => 'PRTM0000002', 'document_type' => 'CLASS10', 'url' => 'https://files.portal.example/missing.pdf'],
            ['portal_application_no' => 'PRTM0000002', 'document_type' => 'CLASS12', 'url' => 'https://files.portal.example/redirect.pdf'],
            ['portal_application_no' => 'PRTM0000003', 'document_type' => 'CLASS10', 'url' => 'https://other.example/a.pdf'],
            ['portal_application_no' => 'PRTM0000003', 'document_type' => 'CLASS12', 'url' => 'http://files.portal.example/a.pdf'],
        ],
    ])->assertStatus(202);
    $batch = ImportBatch::where('uid', $res->json('data.batch'))->first();
    expect($batch->rows_new)->toBe(1)->and($batch->rows_error)->toBe(4);
    $messages = ImportRecord::where('import_batch_id', $batch->id)->get()->map(fn ($r) => ImportError::where('import_record_id', $r->id)->value('message'))->filter()->implode(' | ');
    expect($messages)->toContain('HTTP 404')->toContain('evil.example is not on the allowed list')->toContain('other.example is not on the allowed list')->toContain('Only plain https');
});

it('blocks links that point at internal addresses', function () {
    SystemSetting::where('key', 'document_url_hosts')->update(['value' => json_encode(['intranet.example'])]);
    Settings::forget();
    UrlFetcher::$resolver = fn ($host) => ['10.0.0.5'];
    expect(fn () => app(UrlFetcher::class)->check('https://intranet.example/x.pdf'))->toThrow(RuntimeException::class, 'internal address');
    UrlFetcher::$resolver = fn ($host) => ['169.254.169.254'];
    expect(fn () => app(UrlFetcher::class)->check('https://intranet.example/x.pdf'))->toThrow(RuntimeException::class, 'internal address');
});

it('lets staff upload one document, then open it through a short signed link that is logged', function () {
    $app = Application::where('portal_application_no', 'PRTM0000001')->first();
    $class12 = DocumentType::where('code', 'CLASS12')->first();
    $file = UploadedFile::fake()->createWithContent('marksheet.pdf', fakePdf('12'));
    $this->actingAs($this->officer)->post("/api/v1/applications/{$app->uid}/documents", ['document_type_uid' => $class12->uid, 'file' => $file], ['Accept' => 'application/json'])
        ->assertCreated();
    $version = DocumentVersion::first();
    expect($version->source)->toBe('manual')->and($version->scan_status)->toBe('clean');

    $url = $this->postJson("/api/v1/document-versions/{$version->uid}/link")->assertOk()->json('url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(FileAccessLog::where('user_id', $this->officer->id)->count())->toBe(1);

    // Another user cannot use the link; an unsigned or expired link does not work.
    $this->actingAs($this->approver)->get($url)->assertNotFound();
    $this->actingAs($this->officer)->get(route('files.show', ['version' => $version->uid, 'u' => $this->officer->uid]))->assertForbidden();
    $this->travel(6)->minutes();
    $this->actingAs($this->officer)->get($url)->assertForbidden();
});

it('never serves infected or unscanned files', function () {
    $app = Application::where('portal_application_no', 'PRTM0000001')->first();
    $type = DocumentType::where('code', 'CLASS10')->first();
    $this->actingAs($this->officer)->post("/api/v1/applications/{$app->uid}/documents", [
        'document_type_uid' => $type->uid, 'file' => UploadedFile::fake()->createWithContent('bad.pdf', eicarPdf()),
    ], ['Accept' => 'application/json'])->assertCreated();
    $infected = DocumentVersion::first();
    expect($infected->scan_status)->toBe('infected');
    $this->postJson("/api/v1/document-versions/{$infected->uid}/link")->assertStatus(422)->assertJsonPath('errors.document.0', fn ($m) => str_contains($m, 'quarantined'));

    EicarScanner::$down = true;
    $this->post("/api/v1/applications/{$app->uid}/documents", [
        'document_type_uid' => $type->uid, 'file' => UploadedFile::fake()->createWithContent('good.pdf', fakePdf('fine')),
    ], ['Accept' => 'application/json'])->assertCreated();
    $pending = DocumentVersion::latest('id')->first();
    expect($pending->scan_status)->toBe('pending');
    $this->postJson("/api/v1/document-versions/{$pending->uid}/link")->assertStatus(422);

    EicarScanner::$down = false;
    $this->travel(2)->minutes();
    $this->artisan('documents:scan-pending')->assertSuccessful();
    expect($pending->fresh()->scan_status)->toBe('clean');
});

it('rejects files whose content is not what their name says', function () {
    $app = Application::where('portal_application_no', 'PRTM0000001')->first();
    $this->actingAs($this->officer)->post("/api/v1/applications/{$app->uid}/documents", [
        'document_type_uid' => DocumentType::where('code', 'CLASS10')->value('uid'),
        'file' => UploadedFile::fake()->createWithContent('photo.pdf', '<html>not a pdf</html>'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
});

it('limits documents to staff whose scope covers the application', function () {
    $app = Application::where('portal_application_no', 'PRTM0000001')->first();
    $ipmStaff = userWithRole('admission_staff', new Scope(null, $this->w->ipm->id));
    $this->actingAs($ipmStaff)->getJson("/api/v1/applications/{$app->uid}/documents")->assertNotFound();
    $faculty = userWithRole('faculty');
    $this->actingAs($faculty)->getJson("/api/v1/applications/{$app->uid}/documents")->assertForbidden();
});

it('lets the IT administrator set the allowed link hosts', function () {
    $it = userWithRole('it_admin');
    $this->actingAs($it)->patchJson('/api/v1/system/settings/document_url_hosts', ['value' => ['files.portal.example', '*.cdn.example']])->assertOk();
    expect(Settings::get('document_url_hosts'))->toBe(['files.portal.example', '*.cdn.example']);
    $this->patchJson('/api/v1/system/settings/document_url_hosts', ['value' => ['http://bad host']])->assertStatus(422);
});
