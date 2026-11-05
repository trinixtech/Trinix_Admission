<?php

use App\Modules\Access\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\Institution;
use App\Support\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Mail::fake();
    $i = Institution::create(['code' => 'IIFT', 'name' => 'IIFT']);
    $this->del = Branch::create(['institution_id' => $i->id, 'code' => 'DEL', 'name' => 'Delhi']);
    $this->kol = Branch::create(['institution_id' => $i->id, 'code' => 'KOL', 'name' => 'Kolkata']);
    $this->admin = userWithRole('central_admin');
});

function facultyCsv(string $body): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'fac').'.csv';
    file_put_contents($path, "Name,Email ID,Mobile No,Designation,Department,Campus,Role\n".$body);

    return new UploadedFile($path, 'faculty.csv', 'text/csv', null, true);
}

it('previews faculty rows without saving, then creates, invites and gives roles on confirm', function () {
    $existing = User::factory()->create(['email' => 'old@iift.test', 'name' => 'Old Prof']);
    $csv = facultyCsv(implode("\n", [
        'Asha Rao,asha@iift.test,9000000001,Professor,Economics,DEL,Faculty',
        'Old Prof,OLD@iift.test,,,,KOL,Faculty / Panel Member; Committee Member',
        'No Mail,not-an-email,,,,DEL,Faculty',
        'Bad Campus,bad@iift.test,,,,XYZ,Faculty',
        'Too High,high@iift.test,,,,,Super Admin',
        'Asha Again,asha@iift.test,,,,DEL,Faculty',
    ]));
    $p = $this->actingAs($this->admin)->post('/api/v1/faculty-imports', ['file' => $csv, 'also_committee_member' => '1'], ['Accept' => 'application/json'])
        ->assertOk()->json('data');
    expect(collect($p['rows'])->pluck('status')->all())->toBe(['new', 'existing', 'error', 'error', 'error', 'error'])
        ->and($p['rows'][0]['roles'])->toBe(['faculty', 'committee_member'])
        ->and($p['rows'][5]['errors'][0])->toBe('Same email as row 2.');
    expect(User::where('email', 'asha@iift.test')->exists())->toBeFalse();

    $r = $this->postJson("/api/v1/faculty-imports/{$p['token']}/confirm")->assertOk();
    expect($r->json('message'))->toBe('1 people added and invited, 1 existing people given new roles, 0 unchanged, 4 with errors.');
    $asha = User::where('email', 'asha@iift.test')->first();
    expect($asha->designation)->toBe('Professor')->and($asha->department)->toBe('Economics')
        ->and($asha->activeAssignments()->with('role')->get()->pluck('role.key')->sort()->values()->all())->toBe(['committee_member', 'faculty'])
        ->and($asha->activeAssignments()->first()->branch_id)->toBe($this->del->id)
        ->and($existing->activeAssignments()->with('role')->get()->pluck('role.key')->sort()->values()->all())->toBe(['committee_member', 'faculty']);
    expect(AuditLog::where('action', 'faculty.imported')->count())->toBe(1);

    // The same file again changes nothing for those rows; the token cannot be reused.
    $this->postJson("/api/v1/faculty-imports/{$p['token']}/confirm")->assertStatus(422);
    $again = $this->post('/api/v1/faculty-imports', ['file' => facultyCsv('Asha Rao,asha@iift.test,,,,DEL,Faculty')], ['Accept' => 'application/json'])->json('data');
    expect($again['rows'][0]['status'])->toBe('unchanged');
});

it('lets a branch admin import faculty only for their own campus', function () {
    $ba = userWithRole('branch_admin', new Scope($this->del->id, null, null));
    $p = $this->actingAs($ba)->post('/api/v1/faculty-imports', ['file' => facultyCsv("A,a@iift.test,,,,DEL,Faculty\nB,b@iift.test,,,,KOL,Faculty\nC,c@iift.test,,,,,Faculty")], ['Accept' => 'application/json'])
        ->assertOk()->json('data');
    expect(collect($p['rows'])->pluck('status')->all())->toBe(['new', 'error', 'error']);
    $this->actingAs(userWithRole('faculty'))->post('/api/v1/faculty-imports', ['file' => facultyCsv('A,a@iift.test,,,,DEL,Faculty')], ['Accept' => 'application/json'])->assertForbidden();
});

it('lets only the person who previewed confirm', function () {
    $p = $this->actingAs($this->admin)->post('/api/v1/faculty-imports', ['file' => facultyCsv('A,a@iift.test,,,,DEL,Faculty')], ['Accept' => 'application/json'])->json('data');
    $this->actingAs(userWithRole('central_admin'))->postJson("/api/v1/faculty-imports/{$p['token']}/confirm")->assertStatus(422);
});
