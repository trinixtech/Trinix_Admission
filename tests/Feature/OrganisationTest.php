<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\Auditor;
use App\Modules\Organisation\Models\Institution;
use App\Support\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('sets up the institution, a location, a branch and a course', function () {
    $this->actingAs(userWithRole('central_admin'));

    $this->putJson('/api/v1/institution', ['code' => 'IIFT', 'name' => 'Indian Institute of Foreign Trade'])->assertOk();
    $loc = $this->postJson('/api/v1/locations', ['code' => 'DEL', 'name' => 'Delhi campus', 'city' => 'New Delhi', 'pincode' => '110016'])
        ->assertCreated()->json('data');
    $this->postJson('/api/v1/branches', ['code' => 'DEL', 'name' => 'Delhi', 'location_uid' => $loc['uid']])
        ->assertCreated()->assertJsonPath('data.location.uid', $loc['uid']);
    $this->postJson('/api/v1/courses', ['code' => 'MBA-BA', 'name' => 'MBA (Business Analytics)', 'level' => 'PG', 'duration_months' => 24, 'entrance_exam' => 'CAT'])
        ->assertCreated();

    expect(AuditLog::pluck('action')->all())->toContain('institution.created', 'location.created', 'branch.created', 'course.created');
});

it('validates organisation input', function () {
    world();
    $this->actingAs(userWithRole('central_admin'));
    $this->postJson('/api/v1/branches', ['code' => 'BR-A', 'name' => 'Dup'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/courses', ['code' => 'X', 'name' => 'X', 'level' => 'PhD', 'duration_months' => 0])
        ->assertStatus(422)->assertJsonValidationErrors(['level', 'duration_months']);
    $this->postJson('/api/v1/locations', ['code' => 'L', 'name' => 'L', 'pincode' => '12'])->assertStatus(422)->assertJsonValidationErrors('pincode');
});

it('keeps a branch or course code fixed once it is in use', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));

    $this->patchJson("/api/v1/branches/{$w->a->uid}", ['code' => 'NEW'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->patchJson("/api/v1/courses/{$w->mba->uid}", ['code' => 'NEW'])->assertStatus(422);
    $this->patchJson("/api/v1/branches/{$w->a->uid}", ['name' => 'Renamed', 'status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');

    $free = $this->postJson('/api/v1/branches', ['code' => 'FREE', 'name' => 'Free'])->json('data');
    $this->patchJson("/api/v1/branches/{$free['uid']}", ['code' => 'FREE-2'])->assertOk();
});

it('never deletes organisation records', function (string $url) {
    $w = world();
    $this->actingAs(userWithRole('super_admin'));
    $this->deleteJson(str_replace('{a}', $w->a->uid, str_replace('{mba}', $w->mba->uid, $url)))->assertStatus(405);
})->with(['/api/v1/branches/{a}', '/api/v1/courses/{mba}']);

it('refuses to delete referenced rows at database level too', function () {
    $w = world();
    expect(fn () => $w->a->delete())->toThrow(QueryException::class);
});

it('switches branch-course links on and off without deleting them', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));

    $rows = $this->putJson('/api/v1/branch-courses', ['links' => [
        ['branch_uid' => $w->a->uid, 'course_uid' => $w->ipm->uid, 'status' => 'inactive'],
    ]])->assertOk()->json('data');

    $row = collect($rows)->first(fn ($r) => $r['branch_uid'] === $w->a->uid && $r['course_uid'] === $w->ipm->uid);
    expect($row['status'])->toBe('inactive')->and(count($rows))->toBe(4);
});

it('records only changed fields in the audit log', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));
    $this->patchJson("/api/v1/branches/{$w->a->uid}", ['name' => 'Branch A', 'phone' => '011-1234'])->assertOk();

    $log = AuditLog::where('action', 'branch.updated')->sole();
    expect($log->old_values)->toBe(['phone' => null])
        ->and($log->new_values)->toBe(['phone' => '011-1234'])
        ->and($log->branch_id)->toBe($w->a->id);

    // No change, no row.
    $this->patchJson("/api/v1/branches/{$w->a->uid}", ['phone' => '011-1234'])->assertOk();
    expect(AuditLog::where('action', 'branch.updated')->count())->toBe(1);
});

it('writes no audit row when a change fails', function () {
    $this->actingAs(userWithRole('central_admin'));
    Institution::create(['code' => 'IIFT', 'name' => 'IIFT']);
    $before = AuditLog::count();
    $this->postJson('/api/v1/branches', ['name' => 'No code'])->assertStatus(422);
    expect(AuditLog::count())->toBe($before);
});

it('rolls back the audit row when the change itself fails', function () {
    $w = world();
    $before = AuditLog::count();
    try {
        DB::transaction(function () use ($w) {
            app(Auditor::class)->update('branch.updated', $w->a, ['name' => 'Changed'], Scope::branch($w->a->id));
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    expect(AuditLog::count())->toBe($before)->and($w->a->fresh()->name)->toBe('Branch A');
});

it('keeps the audit log append-only', function () {
    $log = AuditLog::create(['occurred_at' => now(), 'actor_type' => 'system', 'action' => 'test']);
    expect(fn () => $log->update(['action' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});
