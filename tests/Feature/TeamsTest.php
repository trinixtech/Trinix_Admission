<?php

use App\Modules\Audit\Models\AuditLog;
use App\Support\Scope;

it('creates a committee for one course offering, taking branch and cycle from it', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));

    $team = $this->postJson('/api/v1/teams', [
        'type' => 'admission_committee', 'name' => 'MBA IB Committee',
        'offering_uid' => $w->offerings['a-mba']->uid, 'valid_from' => '2027-01-01', 'valid_to' => '2027-08-31',
        'approval_powers' => 'Approve shortlist and merit list',
    ])->assertCreated()->json('data');

    expect($team['branch']['uid'])->toBe($w->a->uid)->and($team['cycle']['uid'])->toBe($w->cycle->uid);

    $this->postJson('/api/v1/teams', [
        'type' => 'admission_committee', 'name' => 'Mismatch',
        'offering_uid' => $w->offerings['a-mba']->uid, 'branch_uid' => $w->b->uid, 'valid_from' => '2027-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors('branch_uid');
});

it('adds and removes members, keeping history and audit', function () {
    $w = world();
    $central = userWithRole('central_admin');
    $member = userWithRole('committee_member', Scope::branch($w->a->id));
    $this->actingAs($central);

    $team = $this->postJson('/api/v1/teams', ['type' => 'admission_cell', 'name' => 'Cell A', 'branch_uid' => $w->a->uid, 'valid_from' => '2027-01-01'])->json('data');
    $rows = $this->postJson("/api/v1/teams/{$team['uid']}/members", ['user_uid' => $member->uid, 'member_role' => 'member'])->assertCreated()->json('data');
    $this->postJson("/api/v1/teams/{$team['uid']}/members", ['user_uid' => $member->uid, 'member_role' => 'chair'])->assertStatus(422);

    $this->postJson("/api/v1/team-members/{$rows[0]['id']}/remove", ['reason' => 'Left'])->assertOk()
        ->assertJsonPath('data.0.removed_at', fn ($v) => $v !== null);

    expect(AuditLog::where('subject_type', 'Team')->pluck('action')->all())
        ->toContain('team.created', 'team.member_added', 'team.member_removed');

    $this->actingAs($member)->getJson('/api/v1/me')->assertOk()->assertJsonCount(0, 'data.teams');
});

it('lets a branch admin manage teams only in their branch', function () {
    $w = world();
    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $this->actingAs($admin);

    $this->postJson('/api/v1/teams', ['type' => 'admission_cell', 'name' => 'Cell A', 'branch_uid' => $w->a->uid, 'valid_from' => '2027-01-01'])->assertCreated();
    $this->postJson('/api/v1/teams', ['type' => 'admission_cell', 'name' => 'Cell B', 'branch_uid' => $w->b->uid, 'valid_from' => '2027-01-01'])->assertForbidden();
    $this->postJson('/api/v1/teams', ['type' => 'accounts_team', 'name' => 'Central', 'valid_from' => '2027-01-01'])->assertForbidden();
});

it('shows members their own teams on their profile', function () {
    $w = world();
    $member = userWithRole('committee_chair', Scope::branch($w->a->id));
    $this->actingAs(userWithRole('central_admin'));
    $team = $this->postJson('/api/v1/teams', ['type' => 'admission_committee', 'name' => 'Committee A', 'branch_uid' => $w->a->uid, 'valid_from' => '2027-01-01'])->json('data');
    $this->postJson("/api/v1/teams/{$team['uid']}/members", ['user_uid' => $member->uid, 'member_role' => 'chair'])->assertCreated();
    signOut();

    $this->actingAs($member)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.teams.0.name', 'Committee A')->assertJsonPath('data.teams.0.member_role', 'chair');
    $this->actingAs($member)->getJson("/api/v1/teams/{$team['uid']}")->assertOk()->assertJsonPath('data.can_manage', false);
});
