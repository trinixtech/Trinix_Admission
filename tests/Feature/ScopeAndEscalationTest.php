<?php

use App\Modules\Access\Models\User;
use App\Support\Scope;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('shows a branch admin only their own branch', function () {
    $w = world();
    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));

    $this->actingAs($admin)->getJson('/api/v1/branches')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.uid', $w->a->uid);
    $this->actingAs($admin)->getJson("/api/v1/branches/{$w->a->uid}")->assertOk();
    $this->actingAs($admin)->getJson("/api/v1/branches/{$w->b->uid}")->assertNotFound();
});

it('returns 403 for a permission the role does not have', function () {
    $w = world();
    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));

    $this->actingAs($admin)->patchJson("/api/v1/branches/{$w->a->uid}", ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($admin)->postJson('/api/v1/branches', ['code' => 'X', 'name' => 'X'])->assertForbidden();
});

it('gives faculty no access to administration endpoints', function (string $method, string $url) {
    world();
    $faculty = userWithRole('faculty');
    $this->actingAs($faculty)->json($method, $url)->assertForbidden();
})->with([
    ['GET', '/api/v1/branches'],
    ['GET', '/api/v1/courses'],
    ['GET', '/api/v1/locations'],
    ['GET', '/api/v1/admission-cycles'],
    ['GET', '/api/v1/users'],
    ['GET', '/api/v1/teams'],
    ['GET', '/api/v1/audit-logs'],
    ['GET', '/api/v1/system/health'],
    ['GET', '/api/v1/system/settings'],
    ['PUT', '/api/v1/institution'],
    ['PUT', '/api/v1/branch-courses'],
]);

it('requires sign-in for every API endpoint', function (string $method, string $url) {
    $this->json($method, $url)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/me'],
    ['GET', '/api/v1/branches'],
    ['GET', '/api/v1/users'],
    ['GET', '/api/v1/audit-logs'],
]);

it('lets the IT administrator see health but not applicant-facing administration', function () {
    world();
    $it = userWithRole('it_admin');
    $this->actingAs($it)->getJson('/api/v1/system/health')->assertOk();
    $this->actingAs($it)->getJson('/api/v1/audit-logs')->assertForbidden();
    $this->actingAs($it)->getJson('/api/v1/admission-cycles')->assertForbidden();
    $this->actingAs($it)->getJson('/api/v1/users')->assertOk();
});

it('lets a branch admin give roles only inside their branch', function () {
    $w = world();
    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $target = User::factory()->create();
    userWithRole('admission_staff', Scope::branch($w->a->id), attributes: ['email' => 'x@example.test']);
    // the target becomes visible to the branch admin through an assignment in branch A
    $this->actingAs(userWithRole('central_admin'))->postJson("/api/v1/users/{$target->uid}/assignments", [
        'role' => 'faculty', 'branch_uid' => $w->a->uid,
    ])->assertCreated();
    signOut();

    $this->actingAs($admin)->postJson("/api/v1/users/{$target->uid}/assignments", [
        'role' => 'admission_staff', 'branch_uid' => $w->a->uid,
    ])->assertCreated();

    $this->actingAs($admin)->postJson("/api/v1/users/{$target->uid}/assignments", [
        'role' => 'admission_staff', 'branch_uid' => $w->b->uid,
    ])->assertForbidden();

    $this->actingAs($admin)->postJson("/api/v1/users/{$target->uid}/assignments", [
        'role' => 'admission_staff',
    ])->assertForbidden();

    // Admission Cell Officers can import (Phase 2), which a Branch Administrator cannot, so they cannot give that role.
    $this->actingAs($admin)->postJson("/api/v1/users/{$target->uid}/assignments", [
        'role' => 'admission_officer', 'branch_uid' => $w->a->uid,
    ])->assertForbidden();

    $this->actingAs($admin)->postJson("/api/v1/users/{$target->uid}/assignments", [
        'role' => 'central_admin', 'branch_uid' => $w->a->uid,
    ])->assertForbidden();
});

it('stops a central admin from creating super admins or IT administrators', function (string $role) {
    $central = userWithRole('central_admin');
    $target = User::factory()->create();

    $this->actingAs($central)->postJson("/api/v1/users/{$target->uid}/assignments", ['role' => $role])->assertForbidden();
})->with(['super_admin', 'it_admin']);

it('lets a super admin give any role', function () {
    $super = userWithRole('super_admin');
    $target = User::factory()->create();
    $this->actingAs($super)->postJson("/api/v1/users/{$target->uid}/assignments", ['role' => 'it_admin'])->assertCreated();
});

it('stops anyone changing their own roles', function () {
    $super = userWithRole('super_admin');
    $this->actingAs($super)->postJson("/api/v1/users/{$super->uid}/assignments", ['role' => 'faculty'])->assertForbidden();
    $own = $super->assignments()->first();
    $this->actingAs($super)->postJson("/api/v1/assignments/{$own->uid}/revoke", ['reason' => 'test'])->assertForbidden();
});

it('hides a central admin from a branch admin and stops the branch admin managing them', function () {
    $w = world();
    $branchAdmin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $central = userWithRole('central_admin');

    $this->actingAs($branchAdmin)->getJson("/api/v1/users/{$central->uid}")->assertNotFound();
    $this->actingAs($branchAdmin)->postJson("/api/v1/users/{$central->uid}/suspend", ['reason' => 'x'])->assertNotFound();
});

it('stops a branch admin suspending someone who also holds a role in another branch', function () {
    $w = world();
    $branchAdmin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $shared = userWithRole('admission_staff', Scope::branch($w->a->id));
    $this->actingAs(userWithRole('super_admin'))->postJson("/api/v1/users/{$shared->uid}/assignments", [
        'role' => 'admission_staff', 'branch_uid' => $w->b->uid,
    ])->assertCreated();
    signOut();

    $this->actingAs($branchAdmin)->getJson("/api/v1/users/{$shared->uid}")->assertOk()->assertJsonPath('data.can_manage', false);
    $this->actingAs($branchAdmin)->postJson("/api/v1/users/{$shared->uid}/suspend", ['reason' => 'x'])->assertForbidden();
});

it('lets a branch admin invite users only with a role in their branch', function () {
    $w = world();
    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));

    $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'No Role', 'email' => 'norole@example.test'])
        ->assertStatus(422)->assertJsonValidationErrors('assignment');

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Other Branch', 'email' => 'other@example.test',
        'assignment' => ['role' => 'admission_staff', 'branch_uid' => $w->b->uid],
    ])->assertForbidden();
    expect(User::where('email', 'other@example.test')->exists())->toBeFalse();

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'Own Branch', 'email' => 'own@example.test',
        'assignment' => ['role' => 'admission_staff', 'branch_uid' => $w->a->uid],
    ])->assertCreated();
});

it('lists only users who hold a role in the viewer\'s branch', function () {
    $w = world();
    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $inA = userWithRole('admission_staff', Scope::branch($w->a->id));
    $inB = userWithRole('admission_staff', Scope::branch($w->b->id));

    $uids = collect($this->actingAs($admin)->getJson('/api/v1/users?per_page=100')->assertOk()->json('data'))->pluck('uid');
    expect($uids)->toContain($inA->uid)->not->toContain($inB->uid);
});

it('revokes an assignment with a reason, keeping the record', function () {
    $w = world();
    $central = userWithRole('central_admin');
    $user = userWithRole('admission_staff', Scope::branch($w->a->id));
    $assignment = $user->assignments()->first();

    $this->actingAs($central)->postJson("/api/v1/assignments/{$assignment->uid}/revoke", [])->assertStatus(422);
    $this->actingAs($central)->postJson("/api/v1/assignments/{$assignment->uid}/revoke", ['reason' => 'Moved'])
        ->assertOk()->assertJsonPath('data.active', false);
    expect($assignment->fresh()->revoked_at)->not->toBeNull();
    $this->actingAs($user->fresh())->getJson('/api/v1/branches')->assertForbidden();
});

it('ignores assignments that have not started or have ended', function () {
    $w = world();
    $user = userWithRole('admission_staff', Scope::branch($w->a->id));
    $user->assignments()->update(['valid_from' => today()->addDay()]);
    $this->actingAs($user)->getJson('/api/v1/branches')->assertForbidden();

    $user->assignments()->update(['valid_from' => today()->subMonth(), 'valid_to' => today()->subDay()]);
    $this->actingAs($user)->getJson('/api/v1/branches')->assertForbidden();
});

it('lets only the super admin change role permissions, never for super_admin itself', function () {
    $central = userWithRole('central_admin');
    $super = userWithRole('super_admin');

    $this->actingAs($central)->putJson('/api/v1/roles/faculty/permissions', ['permissions' => ['cycles.view']])->assertForbidden();
    $this->actingAs($super)->putJson('/api/v1/roles/super_admin/permissions', ['permissions' => []])->assertStatus(422);
    $this->actingAs($super)->putJson('/api/v1/roles/faculty/permissions', ['permissions' => ['cycles.view']])->assertOk();

    $faculty = userWithRole('faculty');
    $this->actingAs($faculty)->getJson('/api/v1/admission-cycles')->assertOk();
});

it('unlocks an account (IT administrator) and resends an invitation', function () {
    $it = userWithRole('it_admin');
    $locked = userWithRole('faculty', attributes: ['locked_until' => now()->addMinutes(10), 'failed_logins' => 0]);

    $this->actingAs($it)->postJson("/api/v1/users/{$locked->uid}/unlock")->assertOk()->assertJsonPath('data.locked', false);
    $this->actingAs($it)->postJson("/api/v1/users/{$locked->uid}/resend-invite")->assertOk();
    $this->actingAs($it)->postJson("/api/v1/users/{$locked->uid}/suspend", ['reason' => 'x'])->assertForbidden();
});
