<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Support\Scope;

function openCycle(object $w): void
{
    test()->postJson("/api/v1/admission-cycles/{$w->cycle->uid}/open")->assertOk();
}

function newRound(object $w, string $name = 'Round 1'): array
{
    return test()->postJson("/api/v1/admission-cycles/{$w->cycle->uid}/rounds", ['name' => $name])->assertCreated()->json('data');
}

function setRoundOfferings(string $roundUid, array $rows)
{
    return test()->putJson("/api/v1/rounds/{$roundUid}/offerings", ['offerings' => $rows]);
}

it('creates an academic year and a cycle', function () {
    $central = userWithRole('central_admin');
    $this->actingAs($central);

    $year = $this->postJson('/api/v1/academic-years', ['code' => '2028-29', 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31'])->assertCreated()->json('data');
    $this->postJson('/api/v1/academic-years', ['code' => '2028', 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31'])->assertStatus(422);

    $this->postJson('/api/v1/admission-cycles', ['academic_year_id' => $year['id'], 'code' => 'ADM-2028', 'name' => 'Admissions 2028'])
        ->assertCreated()->assertJsonPath('data.status', 'draft');
});

it('opens a cycle only when it has an active offering', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));

    $empty = AdmissionCycle::create(['academic_year_id' => $w->year->id, 'code' => 'EMPTY', 'name' => 'Empty', 'status' => 'draft']);
    $this->postJson("/api/v1/admission-cycles/{$empty->uid}/open")->assertStatus(422);

    openCycle($w);
    expect($w->cycle->fresh()->status)->toBe('open');
    $this->postJson("/api/v1/admission-cycles/{$w->cycle->uid}/open")->assertStatus(422);
});

it('adds offerings only for courses the branch offers, once per cycle', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));
    $cycle2 = AdmissionCycle::create(['academic_year_id' => $w->year->id, 'code' => 'C2', 'name' => 'C2', 'status' => 'draft']);

    $this->postJson("/api/v1/admission-cycles/{$cycle2->uid}/offerings", [
        'branch_uid' => $w->a->uid, 'course_uid' => $w->mba->uid, 'sanctioned_intake' => 120,
    ])->assertCreated();
    $this->postJson("/api/v1/admission-cycles/{$cycle2->uid}/offerings", [
        'branch_uid' => $w->a->uid, 'course_uid' => $w->mba->uid, 'sanctioned_intake' => 120,
    ])->assertStatus(422);

    $this->putJson('/api/v1/branch-courses', ['links' => [
        ['branch_uid' => $w->b->uid, 'course_uid' => $w->ipm->uid, 'status' => 'inactive'],
    ]])->assertOk();
    $this->postJson("/api/v1/admission-cycles/{$cycle2->uid}/offerings", [
        'branch_uid' => $w->b->uid, 'course_uid' => $w->ipm->uid, 'sanctioned_intake' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors('course_uid');
});

it('makes the first round initial and later rounds fresh', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));

    $r1 = newRound($w);
    $r2 = newRound($w, 'Fresh round');
    expect($r1['number'])->toBe(1)->and($r1['type'])->toBe('initial')
        ->and($r2['number'])->toBe(2)->and($r2['type'])->toBe('fresh');
});

it('sets branch-wise dates (before the academic year is allowed, after its end is not) and refuses overlaps', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));
    $r1 = newRound($w);
    $r2 = newRound($w, 'Round 2');

    setRoundOfferings($r1['uid'], [
        ['offering_uid' => $w->offerings['a-mba']->uid, 'starts_on' => '2027-03-01', 'ends_on' => '2027-04-30'],
        ['offering_uid' => $w->offerings['b-mba']->uid, 'starts_on' => '2027-03-15', 'ends_on' => '2027-05-15'],
    ])->assertOk()->assertJsonCount(2, 'data.offerings');

    // Admissions for 2027-28 start in December 2026, before the academic year (D27).
    setRoundOfferings($r2['uid'], [
        ['offering_uid' => $w->offerings['b-ipm']->uid, 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-15'],
    ])->assertOk();
    setRoundOfferings($r2['uid'], [
        ['offering_uid' => $w->offerings['a-mba']->uid, 'starts_on' => '2027-12-01', 'ends_on' => '2028-01-15'],
    ])->assertStatus(422)->assertJsonValidationErrors('offerings.0.ends_on');

    setRoundOfferings($r2['uid'], [
        ['offering_uid' => $w->offerings['a-mba']->uid, 'starts_on' => '2027-04-15', 'ends_on' => '2027-05-30'],
    ])->assertStatus(422)->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e), 'overlap'));

    setRoundOfferings($r2['uid'], [
        ['offering_uid' => $w->offerings['a-mba']->uid, 'starts_on' => '2027-05-01', 'ends_on' => '2027-05-30'],
    ])->assertOk();

    setRoundOfferings($r2['uid'], [
        ['offering_uid' => $w->offerings['a-mba']->uid, 'starts_on' => '2027-06-10', 'ends_on' => '2027-06-01'],
    ])->assertStatus(422)->assertJsonValidationErrors('offerings.0.ends_on');
});

it('runs a round through its life: activate, close with reason, read-only, reopen', function () {
    $w = world();
    $central = userWithRole('central_admin');
    $this->actingAs($central);
    openCycle($w);
    $round = newRound($w);

    $this->postJson("/api/v1/rounds/{$round['uid']}/activate")->assertStatus(422);
    setRoundOfferings($round['uid'], [
        ['offering_uid' => $w->offerings['a-mba']->uid, 'starts_on' => '2027-03-01', 'ends_on' => '2027-04-30'],
    ])->assertOk();
    $this->postJson("/api/v1/rounds/{$round['uid']}/activate")->assertOk()->assertJsonPath('data.status', 'active');

    // Active rounds cannot drop offerings.
    setRoundOfferings($round['uid'], [])->assertStatus(422);

    $this->postJson("/api/v1/rounds/{$round['uid']}/close", ['confirm' => true])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/rounds/{$round['uid']}/close", ['reason' => 'Done'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    $this->postJson("/api/v1/rounds/{$round['uid']}/close", ['reason' => 'Round complete', 'confirm' => true])
        ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closer.uid', $central->uid);

    $this->patchJson("/api/v1/rounds/{$round['uid']}", ['name' => 'Changed'])->assertStatus(409);
    setRoundOfferings($round['uid'], [
        ['offering_uid' => $w->offerings['a-mba']->uid, 'starts_on' => '2027-03-01', 'ends_on' => '2027-05-30'],
    ])->assertStatus(409);

    $this->postJson("/api/v1/rounds/{$round['uid']}/reopen", [])->assertStatus(422);
    $this->postJson("/api/v1/rounds/{$round['uid']}/reopen", ['reason' => 'Correction needed'])
        ->assertOk()->assertJsonPath('data.status', 'active');

    $actions = AuditLog::where('subject_type', 'AdmissionRound')->orderBy('id')->pluck('action')->all();
    expect($actions)->toContain('round.created', 'round.activated', 'round.closed', 'round.reopened');
    $closed = AuditLog::where('action', 'round.closed')->first();
    expect($closed->reason)->toBe('Round complete')
        ->and($closed->old_values['status'])->toBe('active')
        ->and($closed->new_values['status'])->toBe('closed')
        ->and($closed->admission_cycle_id)->toBe($w->cycle->id);
});

it('lets only roles with rounds.reopen reopen a round', function () {
    $w = world();
    $round = AdmissionRound::create(['admission_cycle_id' => $w->cycle->id, 'number' => 1, 'type' => 'initial', 'name' => 'R1', 'status' => 'closed', 'closed_at' => now()]);

    $branchAdmin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $this->actingAs($branchAdmin)->postJson("/api/v1/rounds/{$round->uid}/reopen", ['reason' => 'x'])->assertForbidden();

    $staff = userWithRole('admission_officer', Scope::cycle($w->cycle->id));
    $this->actingAs($staff)->postJson("/api/v1/rounds/{$round->uid}/reopen", ['reason' => 'x'])->assertForbidden();
});

it('closes a cycle only when every round is closed, then keeps it read-only', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));
    openCycle($w);
    $round = newRound($w);

    $this->postJson("/api/v1/admission-cycles/{$w->cycle->uid}/close", ['reason' => 'End', 'confirm' => true])->assertStatus(422);
    $this->postJson("/api/v1/rounds/{$round['uid']}/close", ['reason' => 'Done', 'confirm' => true])->assertOk();
    $this->postJson("/api/v1/admission-cycles/{$w->cycle->uid}/close", ['reason' => 'End', 'confirm' => true])->assertOk();

    $this->patchJson("/api/v1/admission-cycles/{$w->cycle->uid}", ['name' => 'X'])->assertStatus(409);
    $this->postJson("/api/v1/admission-cycles/{$w->cycle->uid}/rounds", ['name' => 'Late'])->assertStatus(409);
    $this->patchJson("/api/v1/offerings/{$w->offerings['a-mba']->uid}", ['sanctioned_intake' => 1])->assertStatus(409);
    $this->postJson("/api/v1/rounds/{$round['uid']}/reopen", ['reason' => 'x'])->assertStatus(409);
});

it('keeps a cycle code fixed once it has offerings', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));
    $this->patchJson("/api/v1/admission-cycles/{$w->cycle->uid}", ['code' => 'NEW-CODE'])->assertStatus(422);
    $this->patchJson("/api/v1/admission-cycles/{$w->cycle->uid}", ['name' => 'New name'])->assertOk();
});

it('shows scoped staff only the offerings of their branch', function () {
    $w = world();
    $officer = userWithRole('admission_officer', new Scope($w->a->id, null, $w->cycle->id));

    $rows = $this->actingAs($officer)->getJson("/api/v1/admission-cycles/{$w->cycle->uid}/offerings")->assertOk()->json('data');
    expect(collect($rows)->pluck('branch_uid')->unique()->all())->toBe([$w->a->uid]);

    $other = AdmissionCycle::create(['academic_year_id' => $w->year->id, 'code' => 'OTHER', 'name' => 'Other', 'status' => 'draft']);
    $this->actingAs($officer)->getJson("/api/v1/admission-cycles/{$other->uid}")->assertNotFound();
});

it('lets a branch-scoped manager change only their own offerings', function () {
    $w = world();
    // Give branch admins cycles.manage for this test to check scope on writes.
    $this->actingAs(userWithRole('super_admin'))->putJson('/api/v1/roles/branch_admin/permissions', [
        'permissions' => ['organisation.view', 'cycles.view', 'cycles.manage', 'users.view', 'users.manage', 'teams.view', 'teams.manage', 'audit.view'],
    ])->assertOk();
    signOut();

    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $this->actingAs($admin)->patchJson("/api/v1/offerings/{$w->offerings['a-mba']->uid}", ['sanctioned_intake' => 70])->assertOk();
    // Another branch's offering is outside what this admin can see, so it is not found.
    $this->actingAs($admin)->patchJson("/api/v1/offerings/{$w->offerings['b-mba']->uid}", ['sanctioned_intake' => 70])->assertNotFound();
    $this->actingAs($admin)->postJson("/api/v1/admission-cycles/{$w->cycle->uid}/rounds", ['name' => 'R'])->assertForbidden();
});
