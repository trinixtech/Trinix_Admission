<?php

use App\Modules\Access\Models\User;
use App\Modules\Access\Notifications\InvitationNotification;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Scope;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

function clearLoginLimits(string $email): void
{
    $key = strtolower($email).'|127.0.0.1';
    RateLimiter::clear(md5('login'.$key));
    RateLimiter::clear($key);
}

function login(string $email, string $password)
{
    return test()->postJson('/api/v1/login', ['email' => $email, 'password' => $password]);
}

it('signs in with the right password and audits it', function () {
    $user = userWithRole('faculty', twoFactor: false);

    login($user->email, 'correct-horse-battery')->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(AuditLog::where('action', 'auth.login')->where('actor_id', $user->id)->exists())->toBeTrue()
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('asks for the two-factor code when the user has two-factor', function () {
    $user = userWithRole('central_admin');

    login($user->email, 'correct-horse-battery')->assertOk()->assertJson(['two_factor' => true]);
    $this->assertGuest();
});

it('refuses a wrong password and locks the account after five failures', function () {
    $user = userWithRole('faculty', twoFactor: false);

    foreach (range(1, 4) as $i) {
        login($user->email, 'wrong-password-123')->assertStatus(422);
    }
    expect($user->fresh()->failed_logins)->toBe(4);

    login($user->email, 'wrong-password-123')->assertStatus(422);
    $user->refresh();
    expect($user->isLocked())->toBeTrue()
        ->and(AuditLog::where('action', 'auth.locked')->count())->toBe(1);

    // Even the right password is refused while locked (rate limiter cleared to isolate the lock).
    clearLoginLimits($user->email);
    login($user->email, 'correct-horse-battery')->assertStatus(422)->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'locked'));
    $this->assertGuest();

    // After the lock time it works again.
    $this->travel(16)->minutes();
    clearLoginLimits($user->email);
    login($user->email, 'correct-horse-battery')->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('throttles sign-in attempts per account and address', function () {
    $user = userWithRole('faculty', twoFactor: false);
    foreach (range(1, 5) as $i) {
        login($user->email, 'wrong-password-123');
    }
    login($user->email, 'wrong-password-123')->assertStatus(429);
});

it('refuses suspended accounts', function () {
    $user = userWithRole('faculty', twoFactor: false, attributes: ['status' => User::SUSPENDED]);

    login($user->email, 'correct-horse-battery')->assertStatus(422)->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'suspended'));
    $this->assertGuest();
});

it('signs out a user who is suspended while signed in', function () {
    $user = userWithRole('central_admin');
    $this->actingAs($user)->getJson('/api/v1/branches')->assertOk();

    $user->forceFill(['status' => User::SUSPENDED])->save();
    $this->actingAs($user->fresh())->getJson('/api/v1/branches')->assertStatus(401)->assertJsonPath('code', 'account_suspended');
});

it('blocks roles that need two-factor until it is set up', function () {
    $user = userWithRole('central_admin', twoFactor: false);

    $this->actingAs($user)->getJson('/api/v1/branches')->assertStatus(403)->assertJsonPath('code', 'two_factor_setup_required');
    $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.requires_two_factor', true);
});

it('does not require two-factor for roles that do not need it', function () {
    $user = userWithRole('admission_staff', twoFactor: false);
    $this->actingAs($user)->getJson('/api/v1/branches')->assertOk();
});

it('invites a user who then sets their own password', function () {
    Notification::fake();
    $admin = userWithRole('central_admin');

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'New Officer', 'email' => 'officer@example.test',
        'assignment' => ['role' => 'admission_staff'],
    ])->assertCreated();

    $user = User::where('email', 'officer@example.test')->firstOrFail();
    $token = null;
    Notification::assertSentTo($user, InvitationNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    signOut();
    $this->postJson('/api/v1/set-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    login($user->email, 'a-brand-new-password')->assertOk();
    $this->assertAuthenticatedAs($user);
    expect(AuditLog::where('action', 'auth.invitation_accepted')->exists())->toBeTrue();
});

it('rejects an expired invitation link', function () {
    Notification::fake();
    $admin = userWithRole('central_admin');
    $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Late', 'email' => 'late@example.test'])->assertCreated();
    $user = User::where('email', 'late@example.test')->first();
    $token = null;
    Notification::assertSentTo($user, InvitationNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->travel(73)->hours();
    signOut();
    $this->postJson('/api/v1/set-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertStatus(422);
});

it('requires passwords of at least 12 characters', function () {
    Notification::fake();
    $admin = userWithRole('central_admin');
    $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Short', 'email' => 'short@example.test'])->assertCreated();
    $user = User::where('email', 'short@example.test')->first();
    $token = null;
    Notification::assertSentTo($user, InvitationNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });
    signOut();

    $this->postJson('/api/v1/set-password', [
        'token' => $token, 'email' => $user->email, 'password' => 'short-pass', 'password_confirmation' => 'short-pass',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('never writes passwords or two-factor secrets to the audit log', function () {
    Notification::fake();
    $admin = userWithRole('central_admin');
    $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Someone', 'email' => 'someone@example.test'])->assertCreated();
    login($admin->email, 'correct-horse-battery');

    $all = AuditLog::all()->map(fn ($l) => json_encode([$l->old_values, $l->new_values]))->implode(' ');
    expect($all)->not->toContain('password')
        ->not->toContain('two_factor_secret')
        ->not->toContain('remember_token');
});

it('limits idle sessions to 30 minutes', function () {
    expect(config('session.lifetime'))->toBe(30);
});

it('scope helper: a branch grant covers only its branch', function () {
    $grant = Scope::branch(1);
    expect($grant->covers(new Scope(1, 2, 3)))->toBeTrue()
        ->and($grant->covers(new Scope(2)))->toBeFalse()
        ->and($grant->covers(Scope::global()))->toBeFalse()
        ->and($grant->overlaps(Scope::global()))->toBeTrue()
        ->and(Scope::global()->covers(new Scope(5, 6, 7)))->toBeTrue();
});
