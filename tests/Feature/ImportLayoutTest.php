<?php

use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    Storage::fake('private');
    importSetup($this);
});

/** The admin's layout: only the required columns, the application number renamed, plus three columns of their own. */
function saveStudentLayout(object $t): array
{
    $cols = $t->actingAs($t->approver)->getJson('/api/v1/import-layouts/students')->assertOk()->json('data.columns');
    $cols = array_map(function ($c) {
        $c['include'] = $c['required'];
        if (($c['field'] ?? '') === 'portal_application_no') {
            $c['header'] = 'Form No.';
        }

        return $c;
    }, $cols);
    $cols[] = ['custom' => null, 'header' => 'Hostel required', 'type' => 'yesno', 'required' => true, 'include' => true, 'help' => 'Asked on the form.'];
    $cols[] = ['custom' => null, 'header' => 'Shirt size', 'type' => 'choice', 'options' => ['S', 'M', 'L'], 'include' => true];
    $cols[] = ['custom' => null, 'header' => 'Joining date', 'type' => 'date', 'include' => true];

    return $t->putJson('/api/v1/import-layouts/students', ['columns' => $cols])->assertOk()->json('data.columns');
}

function csvUpload(string $body, string $name = 'filled.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.csv';
    file_put_contents($path, $body);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

it('lets the admin shape the student template, downloads it with instructions, and imports a filled copy', function () {
    $saved = saveStudentLayout($this);
    expect(collect($saved)->where('include', true)->pluck('header')->all())->toContain('Form No.', 'Full name', 'Hostel required', 'Shirt size')
        ->and(collect($saved)->firstWhere('header', 'Hostel required')['custom'])->toBe('hostel_required');
    expect(AuditLog::where('action', 'import_layout.updated')->count())->toBe(1);

    // The template: the headings in the first sheet, the instructions in the second.
    $res = $this->get('/api/v1/import-layouts/students/template')->assertOk();
    $file = $res->baseResponse->getFile()->getPathname();
    $reader = new Reader;
    $reader->open($file);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[$sheet->getName()] = $rows;
    }
    $reader->close();
    expect(array_keys($sheets))->toBe(['Template', 'Instructions'])
        ->and($sheets['Template'][0])->toBe(['Form No.', 'Full name', 'CAT/IPMAT Application No', 'Email', 'Mobile', 'Date of birth', 'Gender', 'Category',
            'Class 10 marks', 'Class 12 marks', 'Hostel required', 'Shirt size', 'Joining date'])
        ->and(count($sheets['Template']))->toBe(1)
        ->and($sheets['Instructions'][0])->toBe(['Column', 'Required', 'What to enter', 'Example'])
        ->and(collect($sheets['Instructions'])->firstWhere(0, 'Shirt size')[2])->toContain('One of: S, M, L');
    $this->get('/api/v1/import-layouts/students/instructions')->assertOk();

    // A filled copy (headings in another case and spacing still match).
    $csv = "FORM NO.,Full Name,CAT/IPMAT Application No,Email,Mobile,Date of birth,Gender,Category,Class 10 marks,Class 12 marks,Hostel  required,Shirt size,Joining date\n"
        ."IP0001,Asha Rao,IPM24001,asha@x.test,9876543210,22-11-2006,Female,GEN,91,88,yes,m,01-07-2027\n"
        ."IP0002,Ravi Kumar,IPM24002,ravi@x.test,9876543211,02-01-2006,Male,SC,80,79,,XL,soon\n";
    $batch = upload($this, csvUpload($csv), null, $this->w->ipm->uid);
    $map = ImportBatch::where('uid', $batch['uid'])->value('column_map');
    expect($map)->toMatchArray(['portal_application_no' => 'FORM NO.', 'full_name' => 'Full Name', 'class12_marks' => 'Class 12 marks'])
        ->and($map)->not->toHaveKey('course');

    $this->actingAs($this->officer)->putJson("/api/v1/import-batches/{$batch['uid']}/mapping", ['column_map' => $map, 'value_map' => []])->assertOk();
    $this->postJson("/api/v1/import-batches/{$batch['uid']}/validate")->assertStatus(202);
    $b = ImportBatch::where('uid', $batch['uid'])->first();
    expect($b->rows_new)->toBe(1)->and($b->rows_error)->toBe(1);
    $errors = $this->getJson("/api/v1/import-batches/{$batch['uid']}/records?classification=error")->json('data.0.errors');
    expect(collect($errors)->pluck('message')->all())->toEqualCanonicalizing([
        'Hostel required is required.',
        'Shirt size: "XL" is not one of: S, M, L.',
        'Joining date: Not a valid date (use e.g. 22-11-2000, 22-Nov-2000 or 2000-11-22).',
    ]);

    approve($this, $b)->assertStatus(202);
    $app = Application::where('portal_application_no', 'IP0001')->first();
    expect($app->extra)->toEqualCanonicalizing(['Hostel required' => 'Yes', 'Shirt size' => 'M', 'Joining date' => '2027-07-01']);

    // The applications export carries the admin's columns.
    $rows = $this->actingAs($this->approver)->get('/api/v1/applications/export?format=xlsx')->assertOk();
    $reader = new Reader;
    $reader->open($rows->baseResponse->getFile()->getPathname());
    $all = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $all[] = $row->toArray();
        }
    }
    $reader->close();
    expect(array_slice($all[0], -3))->toBe(['Hostel required', 'Shirt size', 'Joining date'])
        ->and(array_slice($all[1], -3))->toBe(['Yes', 'M', '2027-07-01']);
});

it('checks the layout: required columns stay in, headings are unique, choices are listed', function () {
    $cols = $this->actingAs($this->approver)->getJson('/api/v1/import-layouts/students')->json('data.columns');
    $cols = array_map(fn ($c) => ['include' => false] + $c, $cols);
    $cols[0]['include'] = false; // portal application number is required: kept in anyway
    $cols[] = ['header' => 'Full name', 'type' => 'text'];
    $cols[] = ['header' => 'Size', 'type' => 'choice', 'options' => ['Only one']];
    $cols[] = ['header' => 'Odd', 'type' => 'formula'];
    $this->putJson('/api/v1/import-layouts/students', ['columns' => $cols])->assertStatus(422)
        ->assertJsonValidationErrors(['columns.'.(count($cols) - 3).'.header', 'columns.'.(count($cols) - 2).'.options', 'columns.'.(count($cols) - 1).'.type']);

    array_splice($cols, -3);
    $saved = $this->putJson('/api/v1/import-layouts/students', ['columns' => $cols])->assertOk()->json('data.columns');
    expect(collect($saved)->firstWhere('field', 'portal_application_no')['include'])->toBeTrue()
        ->and(collect($saved)->firstWhere('field', 'address')['include'])->toBeFalse();

    // Faculty templates have no columns of their own.
    $fac = $this->getJson('/api/v1/import-layouts/faculty')->json('data.columns');
    $fac[] = ['header' => 'Room', 'type' => 'text'];
    $this->putJson('/api/v1/import-layouts/faculty', ['columns' => $fac])->assertStatus(422);
    $this->getJson('/api/v1/import-layouts/staff')->assertNotFound();

    // Hidden from the general settings list.
    expect(collect($this->getJson('/api/v1/settings')->json('data'))->pluck('key')->filter(fn ($k) => str_starts_with($k, 'import_layout.'))->all())->toBe([]);
});

it('needs the right permission for each template', function () {
    $this->actingAs($this->officer)->getJson('/api/v1/import-layouts/students')->assertOk(); // admission officer: imports.manage
    $this->actingAs($this->officer)->getJson('/api/v1/import-layouts/faculty')->assertForbidden();
    $viewer = userWithRole('faculty');
    $this->actingAs($viewer)->getJson('/api/v1/import-layouts/students')->assertForbidden();
    $this->actingAs($viewer)->putJson('/api/v1/import-layouts/students', ['columns' => []])->assertForbidden();
});

it('reads faculty files by the headings of the admin\'s faculty template', function () {
    Mail::fake();
    $cols = $this->actingAs($this->approver)->getJson('/api/v1/import-layouts/faculty')->json('data.columns');
    $rename = ['name' => 'Faculty Full Name', 'email' => 'Official Mail', 'designation' => 'Post', 'campus' => 'Centre'];
    $cols = array_map(fn ($c) => isset($rename[$c['field']]) ? ['header' => $rename[$c['field']]] + $c : $c, $cols);
    $this->putJson('/api/v1/import-layouts/faculty', ['columns' => $cols])->assertOk();

    $this->get('/api/v1/import-layouts/faculty/template')->assertOk();
    $p = $this->post('/api/v1/faculty-imports', ['file' => csvUpload("Faculty Full Name,Official Mail,Post,Centre\nAsha Rao,asha@iift.test,Professor,BR-A\n")],
        ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($p['rows'][0])->toMatchArray(['status' => 'new', 'name' => 'Asha Rao', 'email' => 'asha@iift.test', 'designation' => 'Professor', 'campus' => 'BR-A']);
    expect(User::where('email', 'asha@iift.test')->exists())->toBeFalse();
});
