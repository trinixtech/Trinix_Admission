<?php

use App\Modules\Applicants\Models\Application;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cycles\Models\CourseOffering;
use App\Modules\Imports\Console\MakeSampleFile;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRecord;
use App\Modules\Imports\Services\ImportPipeline;
use App\Modules\Organisation\Models\BranchCourse;
use App\Modules\Organisation\Models\Course;
use App\Support\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    Storage::fake('private');
    importSetup($this);
});

it('imports a portal file end to end and reconciles', function () {
    $batch = stageSample($this, ['--rows' => 20]);
    expect($batch->status)->toBe('validated')
        ->and($batch->rows_total)->toBe(20)
        ->and($batch->rows_new)->toBe(20)
        ->and($batch->rows_error)->toBe(0)
        ->and(Application::count())->toBe(0); // nothing written before approval

    approve($this, $batch)->assertStatus(202);
    $batch->refresh();

    expect($batch->status)->toBe('completed')
        ->and($batch->rows_written)->toBe(20)
        ->and($batch->reconciles())->toBeTrue()
        ->and(Application::count())->toBe(20);

    $app = Application::with('applicant', 'entrance', 'academics', 'preferences', 'participations')->where('portal_application_no', 'PRTM0000001')->first();
    expect($app->category)->toBe('OBC-NCL')
        ->and($app->applicant->gender)->toBe('Female')
        ->and($app->applicant->date_of_birth->format('Y-m-d'))->toBe('1999-02-02')
        ->and($app->entrance->entrance_application_no)->toBe('CAT10000001')
        ->and((float) $app->entrance->percentile)->toBe(70.37)
        ->and($app->academics->pluck('level')->sort()->values()->all())->toBe(['class10', 'class12', 'graduation'])
        ->and($app->preferences->pluck('branch_id')->all())->toBe([$this->w->b->id, $this->w->a->id])
        ->and($app->participations->pluck('admission_round_id')->all())->toBe([$this->round1->id])
        ->and($app->extra)->toBeNull()
        ->and($app->statusHistory()->first()->to_status)->toBe('imported');

    expect(AuditLog::where('subject_type', 'ImportBatch')->pluck('action')->all())
        ->toContain('import.uploaded', 'import.mapped', 'import.validated', 'import.approved', 'import.completed');
});

it('keeps unmapped columns as extra data', function () {
    $batch = stageSample($this, ['--rows' => 25]);
    approve($this, $batch)->assertStatus(202);
    expect(Application::where('portal_application_no', 'PRTM0000025')->first()->extra)->toBe(['Remarks' => 'Sports quota claimed']);
});

it('stops the uploader approving their own batch', function () {
    $batch = stageSample($this, ['--rows' => 3]);
    $officerAsApprover = userWithRole('central_admin');
    $this->actingAs($this->officer)->postJson("/api/v1/import-batches/{$batch->uid}/approve")->assertForbidden(); // officer lacks imports.approve

    // A central admin who uploads cannot approve their own batch either.
    $this->officer = $officerAsApprover;
    $own = upload($this, sampleCsv(['--rows' => 3, '--start' => 100]));
    $this->putJson("/api/v1/import-batches/{$own['uid']}/mapping", MakeSampleFile::mapping())->assertOk();
    $this->postJson("/api/v1/import-batches/{$own['uid']}/validate")->assertStatus(202);
    $this->postJson("/api/v1/import-batches/{$own['uid']}/approve")->assertStatus(422)->assertJsonValidationErrors('approver');
});

it('refuses the same file twice for a round, but allows it after cancelling', function () {
    $file = sampleCsv(['--rows' => 3]);
    $copy = new UploadedFile($file->getRealPath(), 'again.csv', 'text/csv', null, true);
    $first = upload($this, $file);
    $this->actingAs($this->officer)->post('/api/v1/import-batches', [
        'source_uid' => $this->source->uid, 'round_uid' => $this->round1->uid, 'file' => $copy,
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

    $this->postJson("/api/v1/import-batches/{$first['uid']}/cancel", ['reason' => 'Wrong round'])->assertOk();
    $this->post('/api/v1/import-batches', [
        'source_uid' => $this->source->uid, 'round_uid' => $this->round1->uid, 'file' => new UploadedFile($file->getRealPath(), 'again.csv', 'text/csv', null, true),
    ], ['Accept' => 'application/json'])->assertCreated();
});

it('reports field errors per row and never writes them', function () {
    $bad = implode("\n", [
        'PRTMBAD0001,MBA(IB),Bad Email,CAT90000001,80,not-an-email,9000000101,01-01-2000,M,General,No,70,70,70,B.Com,0,,,',
        'PRTMBAD0002,MBA(IB),Bad Mobile,CAT90000002,80,a@example.test,12345,01-01-2000,M,General,No,70,70,70,B.Com,0,,,',
        'PRTMBAD0003,MBA(IB),Bad Date,CAT90000003,80,b@example.test,9000000103,31-02-2000,M,General,No,70,70,70,B.Com,0,,,',
        'PRTMBAD0004,MBA(IB),No Grad,CAT90000004,80,c@example.test,9000000104,01-01-2000,M,General,No,70,70,,,0,,,',
        'PRTMBAD0005,MBA-XX,Bad Course,CAT90000005,80,d@example.test,9000000105,01-01-2000,M,General,No,70,70,70,B.Com,0,,,',
        'PRTMBAD0006,MBA(IB),Bad Category,CAT90000006,80,e@example.test,9000000106,01-01-2000,M,NRI,No,70,70,70,B.Com,0,,,',
        'PRTMBAD0007,MBA(IB),Bad Pref,CAT90000007,80,f@example.test,9000000107,01-01-2000,M,General,No,70,70,70,B.Com,0,NOWHERE,,',
        'PRTMBAD0008,MBA(IB),Same Pref,CAT90000008,80,g@example.test,9000000108,01-01-2000,M,General,No,70,70,70,B.Com,0,BR-A,BR-A,',
        'PRTMBAD0009,MBA(IB),Too High,CAT90000009,180,h@example.test,9000000109,01-01-2000,M,General,No,70,70,70,B.Com,0,,,',
    ])."\n";
    $batch = stageSample($this, ['--rows' => 2], append: $bad);

    expect($batch->rows_total)->toBe(11)->and($batch->rows_error)->toBe(9)->and($batch->rows_new)->toBe(2);
    $fields = collect($this->actingAs($this->officer)->getJson("/api/v1/import-batches/{$batch->uid}/errors?per_page=100")->json('data'))
        ->pluck('field');
    expect($fields->all())->toContain('email', 'mobile', 'date_of_birth', 'graduation_marks', 'course', 'category', 'preference_1', 'entrance_percentile');

    $csv = $this->get("/api/v1/import-batches/{$batch->uid}/errors?format=csv")->assertOk()->streamedContent();
    expect($csv)->toContain('row,field,message')->toContain('Not a valid email address.');

    approve($this, $batch)->assertStatus(202);
    expect(Application::count())->toBe(2)->and($batch->fresh()->reconciles())->toBeTrue();
});

it('rejects a course that is not offered in the round\'s cycle', function () {
    $this->w->offerings['a-ipm']->update(['status' => 'closed']);
    $this->w->offerings['b-ipm']->update(['status' => 'closed']);
    $batch = stageSample($this, ['--rows' => 2, '--course' => 'IPM']);
    expect($batch->rows_error)->toBe(2);
    $msg = $this->actingAs($this->officer)->getJson("/api/v1/import-batches/{$batch->uid}/errors")->json('data.0.message');
    expect($msg)->toContain('not offered');
});

it('marks repeated rows in one file as duplicates', function () {
    $dup = "PRTM0000001,MBA(IB),Diya Sharma,CAT10000001,70.37,diya.sharma1@example.test,9000000001,02-02-1999,F,OBC,No,67.0,71.0,68.0,B.Com,5,BR-B,BR-A,\n";
    $batch = stageSample($this, ['--rows' => 3], append: $dup);
    expect($batch->rows_duplicate)->toBe(1)->and($batch->rows_new)->toBe(3);
    $rec = ImportRecord::where('import_batch_id', $batch->id)->where('classification', 'duplicate')->first();
    expect($rec->diff['first_row'])->toBe(2);
    approve($this, $batch)->assertStatus(202);
    expect(Application::count())->toBe(3)->and($batch->fresh()->reconciles())->toBeTrue();
});

it('flags a portal number used for two courses', function () {
    $other = "PRTM0000001,IPM,Diya Sharma,IPM10000001,70,diya@example.test,9000000001,02-02-2006,F,OBC,No,67.0,71.0,,,,BR-A,,\n";
    $batch = stageSample($this, ['--rows' => 2], append: $other);
    expect($batch->rows_error)->toBe(1);
    expect($this->actingAs($this->officer)->getJson("/api/v1/import-batches/{$batch->uid}/errors")->json('data.0.message'))->toContain('another course');
});

it('cross-references the same entrance number on two applications without merging them', function () {
    approve($this, stageSample($this, ['--rows' => 1]))->assertStatus(202);
    $ba = "PRTMBA00001,MBA(BA),Diya Sharma,CAT10000001,70.37,diya.sharma1@example.test,9000000001,02-02-1999,F,OBC,No,67.0,71.0,68.0,B.Com,5,BR-A,,\n";
    $this->w->institution; // MBA-BA course added for this test
    $baCourse = Course::create(['institution_id' => $this->w->institution->id, 'code' => 'MBA-BA', 'name' => 'MBA (Business Analytics)', 'level' => 'PG', 'duration_months' => 24, 'entrance_exam' => 'CAT']);
    $bc = BranchCourse::create(['branch_id' => $this->w->a->id, 'course_id' => $baCourse->id]);
    CourseOffering::create(['admission_cycle_id' => $this->w->cycle->id, 'branch_course_id' => $bc->id, 'sanctioned_intake' => 60, 'status' => 'active']);

    $batch = stageSample($this, ['--rows' => 0, '--start' => 900], append: $ba);
    expect($batch->rows_new)->toBe(1);
    approve($this, $batch)->assertStatus(202);

    expect(Application::count())->toBe(2);
    $ib = Application::where('portal_application_no', 'PRTM0000001')->first();
    $this->actingAs($this->approver)->getJson("/api/v1/applications/{$ib->uid}")->assertOk()
        ->assertJsonPath('data.related.0.portal_application_no', 'PRTMBA00001')
        ->assertJsonPath('data.related.0.course', 'MBA-BA');
});

it('re-imports for Round 2: unchanged and updated rows, round participation, no duplicates', function () {
    approve($this, stageSample($this, ['--rows' => 10]))->assertStatus(202);

    // Round 2 export: the same 10 (some percentiles changed) plus 5 new applicants.
    $batch = stageSample($this, ['--rows' => 15, '--vary' => 4], $this->round2); // rows 1-3 change
    expect($batch->rows_new)->toBe(5)
        ->and($batch->rows_update)->toBe(3)
        ->and($batch->rows_unchanged)->toBe(7);

    $update = ImportRecord::where('import_batch_id', $batch->id)->where('classification', 'update')->orderBy('row_number')->first();
    expect(array_keys($update->diff))->toBe(['entrance_percentile'])
        ->and($update->diff['entrance_percentile']['old'])->toBe('70.37')
        ->and($update->diff['entrance_percentile']['new'])->toBe('70.87');

    approve($this, $batch)->assertStatus(202);
    expect(Application::count())->toBe(15);
    $app = Application::where('portal_application_no', 'PRTM0000001')->first();
    expect($app->participations()->count())->toBe(2)
        ->and((float) $app->entrance->percentile)->toBe(70.87)
        ->and(AuditLog::where('action', 'application.import_updated')->where('subject_id', $app->id)->value('old_values'))->toBe(['entrance_percentile' => '70.37']);
});

it('never erases stored data with empty cells', function () {
    approve($this, stageSample($this, ['--rows' => 1]))->assertStatus(202);
    $blankGrad = "PRTM0000001,MBA(IB),Diya Sharma,CAT10000001,70.37,diya.sharma1@example.test,9000000001,02-02-1999,F,OBC,No,67.0,71.0,68.0,,,BR-B,BR-A,\n";
    $batch = stageSample($this, ['--rows' => 0], $this->round2, $blankGrad);
    expect($batch->rows_unchanged)->toBe(1);
});

it('sends the batch back to preview when the data changed after the preview', function () {
    $a = stageSample($this, ['--rows' => 3]);
    $b = stageSample($this, ['--rows' => 3, '--seed' => 7], $this->round2);
    expect($b->rows_new)->toBe(3);

    approve($this, $a)->assertStatus(202); // writes the same applicants first
    approve($this, $b)->assertStatus(409)->assertJsonPath('approved', false);
    $b->refresh();
    expect($b->status)->toBe('validated')->and($b->rows_unchanged)->toBe(3)->and($b->status_message)->toContain('changed');

    approve($this, $b)->assertStatus(202);
    expect(Application::count())->toBe(3)->and($b->fresh()->status)->toBe('completed');
});

it('does not write excluded rows', function () {
    $batch = stageSample($this, ['--rows' => 5]);
    $ids = ImportRecord::where('import_batch_id', $batch->id)->orderBy('row_number')->limit(2)->pluck('id')->all();
    $this->actingAs($this->officer)->postJson("/api/v1/import-batches/{$batch->uid}/exclusions", ['record_ids' => $ids, 'excluded' => true])
        ->assertOk()->assertJsonPath('data.counts.excluded', 2);
    approve($this, $batch)->assertStatus(202);
    $batch->refresh();
    expect(Application::count())->toBe(3)->and($batch->rows_written)->toBe(3)->and($batch->reconciles())->toBeTrue();
});

it('keeps written chunks when writing fails and finishes on retry without duplicates', function () {
    config(['import.chunk' => 1000]);
    $batch = stageSample($this, ['--rows' => 1200]);
    $fail = true;
    Application::creating(function ($app) use (&$fail) {
        if ($fail && $app->portal_application_no === 'PRTM0000700') {
            throw new RuntimeException('disk full');
        }
    });

    approve($this, $batch)->assertStatus(202);
    $batch->refresh();
    expect($batch->status)->toBe('partially_completed')
        ->and($batch->rows_written)->toBe(500)
        ->and(Application::count())->toBe(500)
        ->and($batch->status_message)->toContain('retry');

    $fail = false;
    $this->actingAs($this->approver)->postJson("/api/v1/import-batches/{$batch->uid}/retry")->assertStatus(202);
    $batch->refresh();
    expect($batch->status)->toBe('completed')->and(Application::count())->toBe(1200)->and($batch->reconciles())->toBeTrue();
});

it('reads Excel files', function () {
    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(MakeSampleFile::HEADERS));
    $writer->addRow(Row::fromValues([
        'PRTX0000001', 'MBA(IB)', 'Excel Person', 'CAT77777777', 91.5, 'excel@example.test', 9876543210,
        new DateTimeImmutable('2000-05-17'), 'F', 'EWS', 'No', 80, 85, 75.5, 'B.Tech', 12, 'BR-A', '', '',
    ]));
    $writer->close();

    $batch = upload($this, new UploadedFile($path, 'export.xlsx', null, null, true));
    expect($batch['channel'])->toBe('xlsx')->and($batch['headers'])->toContain('Application No');
    $this->putJson("/api/v1/import-batches/{$batch['uid']}/mapping", MakeSampleFile::mapping())->assertOk();
    $this->postJson("/api/v1/import-batches/{$batch['uid']}/validate")->assertStatus(202);
    $b = ImportBatch::where('uid', $batch['uid'])->first();
    expect($b->rows_new)->toBe(1);
    approve($this, $b)->assertStatus(202);
    $app = Application::with('applicant')->first();
    expect($app->applicant->date_of_birth->format('Y-m-d'))->toBe('2000-05-17')->and($app->applicant->mobile)->toBe('9876543210');
});

it('validates the column mapping', function () {
    $batch = upload($this, sampleCsv(['--rows' => 1]));
    $this->putJson("/api/v1/import-batches/{$batch['uid']}/mapping", ['column_map' => ['full_name' => 'Nope', 'bogus' => 'Programme']])
        ->assertStatus(422)->assertJsonValidationErrors(['column_map.full_name', 'column_map.bogus', 'column_map.portal_application_no']);
});

it('limits course-scoped staff to batches and applications of their course', function () {
    approve($this, stageSample($this, ['--rows' => 2]))->assertStatus(202);
    $ipmOfficer = userWithRole('admission_officer', new Scope(null, $this->w->ipm->id, $this->w->cycle->id));
    $mbaBatch = ImportBatch::first();
    $mbaApp = Application::first();

    $this->actingAs($ipmOfficer)->getJson("/api/v1/import-batches/{$mbaBatch->uid}")->assertNotFound();
    $this->actingAs($ipmOfficer)->getJson('/api/v1/import-batches')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($ipmOfficer)->getJson("/api/v1/applications/{$mbaApp->uid}")->assertNotFound();
    $this->actingAs($ipmOfficer)->getJson('/api/v1/applications')->assertOk()->assertJsonCount(0, 'data');

    // An IPM officer may upload only an IPM batch.
    $this->actingAs($ipmOfficer)->post('/api/v1/import-batches', [
        'source_uid' => $this->source->uid, 'round_uid' => $this->round1->uid, 'file' => sampleCsv(['--rows' => 1, '--start' => 50]),
    ], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($ipmOfficer)->post('/api/v1/import-batches', [
        'source_uid' => $this->source->uid, 'round_uid' => $this->round1->uid, 'course_uid' => $this->w->ipm->uid,
        'file' => sampleCsv(['--rows' => 1, '--start' => 60, '--course' => 'IPM']),
    ], ['Accept' => 'application/json'])->assertCreated();
});

it('lets branch staff see applications of the courses their branch offers', function () {
    approve($this, stageSample($this, ['--rows' => 2]))->assertStatus(202);
    $staffA = userWithRole('admission_staff', Scope::branch($this->w->a->id));
    $this->actingAs($staffA)->getJson('/api/v1/applications')->assertOk()->assertJsonCount(2, 'data');

    $this->w->offerings['a-mba']->branchCourse->update(['status' => 'inactive']);
    CourseOffering::where('id', $this->w->offerings['a-mba']->id)->delete();
    $this->actingAs($staffA)->getJson('/api/v1/applications')->assertOk()->assertJsonCount(0, 'data');
});

it('finds applications by portal number, entrance number, mobile and name', function (string $q) {
    approve($this, stageSample($this, ['--rows' => 5]))->assertStatus(202);
    $this->actingAs($this->approver)->getJson('/api/v1/applications?q='.urlencode($q))->assertOk()
        ->assertJsonPath('data.0.portal_application_no', 'PRTM0000003');
})->with(['PRTM0000003', 'prtm0000003', 'CAT10000003', '9000000003', 'Ananya']);

it('corrects a field with a reason and keeps the history', function () {
    approve($this, stageSample($this, ['--rows' => 1]))->assertStatus(202);
    $app = Application::first();

    $this->actingAs($this->officer)->postJson("/api/v1/applications/{$app->uid}/corrections", ['field' => 'mobile', 'value' => '9123456789'])
        ->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->actingAs($this->officer)->postJson("/api/v1/applications/{$app->uid}/corrections", ['field' => 'category', 'value' => 'NRI', 'reason' => 'x'])
        ->assertStatus(422);
    $this->actingAs($this->officer)->postJson("/api/v1/applications/{$app->uid}/corrections", ['field' => 'portal_application_no', 'value' => 'X', 'reason' => 'x'])
        ->assertStatus(422);

    $this->actingAs($this->officer)->postJson("/api/v1/applications/{$app->uid}/corrections", ['field' => 'mobile', 'value' => '9123456789', 'reason' => 'Applicant called with new number'])
        ->assertOk()->assertJsonPath('data.applicant.mobile', '9123456789')
        ->assertJsonPath('data.corrections.0.old_value', '9000000001');
    expect(AuditLog::where('action', 'application.corrected')->first()->reason)->toBe('Applicant called with new number');

    $viewer = userWithRole('admission_staff');
    $this->actingAs($viewer)->postJson("/api/v1/applications/{$app->uid}/corrections", ['field' => 'mobile', 'value' => '9123456780', 'reason' => 'x'])
        ->assertForbidden();
});

it('keeps personal details out of import audit rows', function () {
    approve($this, stageSample($this, ['--rows' => 3]))->assertStatus(202);
    $text = AuditLog::where('action', 'like', 'import.%')->get()->map(fn ($l) => json_encode([$l->old_values, $l->new_values]))->implode(' ');
    expect($text)->not->toContain('@example.test')->not->toContain('9000000001')->not->toContain('Diya');
});

it('makes completed and cancelled batches read-only', function () {
    $batch = stageSample($this, ['--rows' => 1]);
    approve($this, $batch)->assertStatus(202);
    $this->actingAs($this->officer)->putJson("/api/v1/import-batches/{$batch->uid}/mapping", MakeSampleFile::mapping())->assertStatus(422);
    $this->actingAs($this->officer)->postJson("/api/v1/import-batches/{$batch->uid}/cancel", ['reason' => 'x'])->assertStatus(422);
    approve($this, $batch)->assertStatus(422);
});

it('refuses uploads to a closed round', function () {
    $this->round1->update(['status' => 'closed']);
    $this->actingAs($this->officer)->post('/api/v1/import-batches', [
        'source_uid' => $this->source->uid, 'round_uid' => $this->round1->uid, 'file' => sampleCsv(['--rows' => 1]),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

it('recovers when another batch writes the same new applicants between approval and writing', function () {
    $a = stageSample($this, ['--rows' => 3]);
    $b = stageSample($this, ['--rows' => 3, '--seed' => 9], $this->round2);
    approve($this, $a)->assertStatus(202); // A writes the three applicants

    // B was approved before A wrote (simulated: write it directly, skipping the re-check at approval).
    $b->forceFill(['status' => ImportBatch::APPROVED, 'approved_by' => $this->approver->id, 'approved_at' => now()])->save();
    app(ImportPipeline::class)->write($b);
    expect($b->fresh()->status)->toBe('failed')->and(Application::count())->toBe(3);

    $this->actingAs($this->approver)->postJson("/api/v1/import-batches/{$b->uid}/retry")->assertStatus(202);
    $b->refresh();
    expect($b->status)->toBe('completed')->and($b->rows_written)->toBe(3)->and(Application::count())->toBe(3)
        ->and(Application::first()->participations()->count())->toBe(2);
});
