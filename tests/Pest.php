<?php

use App\Modules\Access\Models\Role;
use App\Modules\Access\Models\User;
use App\Modules\Access\Models\UserRoleAssignment;
use App\Modules\Access\Services\AccessCatalog;
use App\Modules\Cycles\Models\AcademicYear;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Cycles\Models\CourseOffering;
use App\Modules\Imports\Console\MakeSampleFile;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\BranchCourse;
use App\Modules\Organisation\Models\Course;
use App\Modules\Organisation\Models\Institution;
use App\Support\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => app(AccessCatalog::class)->sync())
    ->in('Feature');

/** A user holding one role in a scope. Two-factor is confirmed unless asked otherwise. */
function userWithRole(string $role, ?Scope $scope = null, bool $twoFactor = true, array $attributes = []): User
{
    $factory = User::factory();
    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create($attributes);
    $scope ??= Scope::global();
    UserRoleAssignment::create([
        'user_id' => $user->id,
        'role_id' => Role::where('key', $role)->value('id'),
        'branch_id' => $scope->branchId,
        'course_id' => $scope->courseId,
        'admission_cycle_id' => $scope->cycleId,
        'valid_from' => today()->subDay(),
    ]);

    return $user;
}

/**
 * A small organisation: IIFT with branches A and B, courses MBA-IB and IPM offered at both,
 * academic year 2027-28 and a draft cycle with all four offerings.
 *
 * @return object{institution: Institution, a: Branch, b: Branch, mba: Course, ipm: Course, year: AcademicYear, cycle: AdmissionCycle, offerings: array<string, CourseOffering>}
 */
function world(): object
{
    $institution = Institution::create(['code' => 'IIFT', 'name' => 'Indian Institute of Foreign Trade']);
    $a = Branch::create(['institution_id' => $institution->id, 'code' => 'BR-A', 'name' => 'Branch A']);
    $b = Branch::create(['institution_id' => $institution->id, 'code' => 'BR-B', 'name' => 'Branch B']);
    $mba = Course::create(['institution_id' => $institution->id, 'code' => 'MBA-IB', 'name' => 'MBA (International Business)', 'level' => 'PG', 'duration_months' => 24, 'entrance_exam' => 'CAT']);
    $ipm = Course::create(['institution_id' => $institution->id, 'code' => 'IPM', 'name' => 'Integrated Programme in Management', 'level' => 'Integrated', 'duration_months' => 60, 'entrance_exam' => 'IPMAT']);
    $year = AcademicYear::create(['code' => '2027-28', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    $cycle = AdmissionCycle::create(['academic_year_id' => $year->id, 'code' => 'ADM-2027', 'name' => 'Admissions 2027', 'status' => 'draft']);

    $offerings = [];
    foreach (['a' => $a, 'b' => $b] as $bk => $branch) {
        foreach (['mba' => $mba, 'ipm' => $ipm] as $ck => $course) {
            $bc = BranchCourse::create(['branch_id' => $branch->id, 'course_id' => $course->id]);
            $offerings["$bk-$ck"] = CourseOffering::create([
                'admission_cycle_id' => $cycle->id, 'branch_course_id' => $bc->id, 'sanctioned_intake' => 60, 'status' => 'active',
            ]);
        }
    }

    return (object) compact('institution', 'a', 'b', 'mba', 'ipm', 'year', 'cycle', 'offerings');
}

function signOut(): void
{
    auth()->guard('web')->logout();
    app('auth')->forgetGuards();
}

/** Round 1 and 2, a source, an officer (uploads) and a central admin (approves) on top of world(). */
function importSetup(object $t): void
{
    $t->w = world();
    $t->w->cycle->update(['status' => 'open']);
    $t->round1 = AdmissionRound::create(['admission_cycle_id' => $t->w->cycle->id, 'number' => 1, 'type' => 'initial', 'name' => 'Round 1', 'status' => 'active']);
    $t->round2 = AdmissionRound::create(['admission_cycle_id' => $t->w->cycle->id, 'number' => 2, 'type' => 'fresh', 'name' => 'Round 2', 'status' => 'active']);
    $t->source = ExternalSource::create(['code' => 'PORTAL', 'name' => 'Admission portal', 'status' => 'active']);
    $t->officer = userWithRole('admission_officer');
    $t->approver = userWithRole('central_admin');
}

/** A sample CSV as an upload. */
function sampleCsv(array $options = [], ?string $append = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
    Artisan::call('imports:make-sample', ['path' => $path, '--branches' => 'BR-A,BR-B'] + $options);
    if ($append !== null) {
        file_put_contents($path, $append, FILE_APPEND);
    }

    return new UploadedFile($path, 'portal-export.csv', 'text/csv', null, true);
}

function upload(object $t, UploadedFile $file, ?AdmissionRound $round = null, ?string $courseUid = null): array
{
    return $t->actingAs($t->officer)->post('/api/v1/import-batches', [
        'source_uid' => $t->source->uid, 'round_uid' => ($round ?? $t->round1)->uid, 'course_uid' => $courseUid, 'file' => $file,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');
}

/** Upload, map with the sample mapping and validate (the queue runs synchronously in tests). */
function stageSample(object $t, array $options = [], ?AdmissionRound $round = null, ?string $append = null): ImportBatch
{
    $batch = upload($t, sampleCsv($options, $append), $round);
    $t->actingAs($t->officer)->putJson("/api/v1/import-batches/{$batch['uid']}/mapping", MakeSampleFile::mapping())->assertOk();
    $t->actingAs($t->officer)->postJson("/api/v1/import-batches/{$batch['uid']}/validate")->assertStatus(202);

    return ImportBatch::where('uid', $batch['uid'])->firstOrFail();
}

function approve(object $t, ImportBatch $batch)
{
    return $t->actingAs($t->approver)->postJson("/api/v1/import-batches/{$batch->uid}/approve");
}

function fakePdf(string $text = 'document'): string
{
    return "%PDF-1.4\n% {$text}\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";
}

function eicarPdf(): string
{
    return fakePdf('X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
}

/** @param array<string, string> $files name => content */
function makeZip(array $files, string $name = 'documents.zip'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $n => $content) {
        $zip->addFromString($n, $content);
    }
    $zip->close();

    return new UploadedFile($path, $name, 'application/zip', null, true);
}

/** Import N sample applicants for Round 1 (written). */
function importApplicants(object $t, int $rows = 3): void
{
    approve($t, stageSample($t, ['--rows' => $rows]))->assertStatus(202);
}
