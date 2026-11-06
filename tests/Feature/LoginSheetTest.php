<?php

use App\Modules\Access\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->admin = userWithRole('super_admin');
    $this->panel = userWithRole('faculty', twoFactor: false, attributes: ['name' => 'Panel Member']);
    $this->committee = userWithRole('committee_member', twoFactor: false, attributes: ['name' => 'Committee Member']);
    $this->other = userWithRole('central_admin', attributes: ['name' => 'Another Admin']);
    RateLimiter::clear(md5('login'.strtolower($this->panel->email).'|127.0.0.1'));
});

it('lists panel and committee members for the sheet, not administrators', function () {
    $names = collect($this->actingAs($this->admin)->getJson('/api/v1/users/login-sheet')->assertOk()->json('data'))->pluck('name');
    expect($names)->toContain('Panel Member', 'Committee Member')->not->toContain('Another Admin');
    $this->actingAs($this->panel)->getJson('/api/v1/users/login-sheet')->assertForbidden();
});

it('makes temporary passwords shown once, which must be changed at first sign-in', function () {
    $r = $this->actingAs($this->admin)->postJson('/api/v1/users/login-sheet', ['user_uids' => [$this->panel->uid, $this->committee->uid]])->assertOk();
    $row = collect($r->json('data.people'))->firstWhere('email', $this->panel->email);
    expect($row['password'])->toMatch('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/')
        ->and($this->panel->fresh()->must_change_password)->toBeTrue()
        ->and(AuditLog::where('action', 'user.temporary_password')->count())->toBe(2)
        ->and(AuditLog::where('action', 'user.temporary_password')->get()->toJson())->not->toContain($row['password']);

    auth()->guard('web')->logout();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/login', ['email' => $this->panel->email, 'password' => $row['password']])->assertOk();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    $this->getJson('/api/v1/my-interviews')->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $this->putJson('/api/v1/user/password', ['current_password' => $row['password'], 'password' => 'my-own-long-password', 'password_confirmation' => 'my-own-long-password'])->assertOk();
    expect($this->panel->fresh()->must_change_password)->toBeFalse();
    $this->getJson('/api/v1/my-interviews')->assertOk();
});

it('refuses administrators, suspended people and your own account', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/users/login-sheet', ['user_uids' => [$this->other->uid]])->assertUnprocessable();
    $this->postJson('/api/v1/users/login-sheet', ['user_uids' => [$this->admin->uid]])->assertUnprocessable();
    $this->panel->forceFill(['status' => User::SUSPENDED])->save();
    $this->postJson('/api/v1/users/login-sheet', ['user_uids' => [$this->panel->uid]])->assertUnprocessable();
    $this->postJson('/api/v1/users/login-sheet', ['user_uids' => ['nope']])->assertUnprocessable();
});
