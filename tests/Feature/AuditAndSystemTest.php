<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\System\Models\SystemSetting;
use App\Support\Scope;
use Illuminate\Support\Facades\Artisan;

it('lets a branch admin see only audit rows from their branch', function () {
    $w = world();
    $central = userWithRole('central_admin');
    $this->actingAs($central);
    $this->patchJson("/api/v1/branches/{$w->a->uid}", ['phone' => '1'])->assertOk();
    $this->patchJson("/api/v1/branches/{$w->b->uid}", ['phone' => '2'])->assertOk();
    signOut();

    $admin = userWithRole('branch_admin', Scope::branch($w->a->id));
    $rows = $this->actingAs($admin)->getJson('/api/v1/audit-logs?per_page=100')->assertOk()->json('data');
    expect(collect($rows)->pluck('branch_id')->unique()->all())->toBe([$w->a->id]);

    $other = AuditLog::where('branch_id', $w->b->id)->first();
    $this->actingAs($admin)->getJson("/api/v1/audit-logs/{$other->id}")->assertNotFound();
    $own = AuditLog::where('branch_id', $w->a->id)->first();
    $this->actingAs($admin)->getJson("/api/v1/audit-logs/{$own->id}")->assertOk()->assertJsonStructure(['data' => ['old_values', 'new_values']]);
});

it('filters the audit log by action and date', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));
    $this->patchJson("/api/v1/branches/{$w->a->uid}", ['phone' => '1'])->assertOk();

    $this->getJson('/api/v1/audit-logs?action=branch.')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/audit-logs?action=branch.&from='.today()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
});

it('adds a request id to responses and audit rows', function () {
    $w = world();
    $this->actingAs(userWithRole('central_admin'));
    $response = $this->withHeader('X-Request-Id', 'test-request-0001')->patchJson("/api/v1/branches/{$w->a->uid}", ['phone' => '9']);
    $response->assertOk()->assertHeader('X-Request-Id', 'test-request-0001');
    expect(AuditLog::where('action', 'branch.updated')->value('request_id'))->toBe('test-request-0001');
});

it('reports healthy on /up when the database and storage work', function () {
    $this->getJson('/up')->assertOk()->assertJsonPath('ok', true);
});

it('reports unhealthy on /up when the database is down', function () {
    config(['database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']]);
    $original = config('database.default');
    config(['database.default' => 'broken']);
    try {
        $this->getJson('/up')->assertStatus(503)->assertJsonPath('checks.database', false);
    } finally {
        config(['database.default' => $original]);
    }
});

it('reports unhealthy on /up when Redis is down', function () {
    config(['cache.default' => 'redis', 'database.redis.default.port' => 1, 'database.redis.default.host' => '127.0.0.1']);
    $this->getJson('/up')->assertStatus(503)->assertJsonPath('checks.redis', false);
});

it('shows detailed health to the IT administrator', function () {
    $this->actingAs(userWithRole('it_admin'))->getJson('/api/v1/system/health')->assertOk()
        ->assertJsonStructure(['data' => ['ok', 'checks' => ['database', 'redis', 'storage', 'scheduler', 'queue'], 'app']]);
});

it('lets the IT administrator change technical settings only', function () {
    $it = userWithRole('it_admin');
    $this->actingAs($it);

    $this->patchJson('/api/v1/system/settings/login_lock_minutes', ['value' => 20])->assertOk();
    expect(SystemSetting::where('key', 'login_lock_minutes')->value('value'))->toBe(20);
    $this->patchJson('/api/v1/system/settings/support_email', ['value' => 'help@example.test'])->assertForbidden();

    $this->actingAs(userWithRole('super_admin'))->patchJson('/api/v1/system/settings/support_email', ['value' => 'help@example.test'])->assertOk();
    $this->patchJson('/api/v1/system/settings/login_lock_minutes', ['value' => 'abc'])->assertStatus(422);
});

it('describes the current user with permissions and roles', function () {
    $w = world();
    $user = userWithRole('admission_officer', Scope::branch($w->a->id));
    $this->actingAs($user)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.assignments.0.role.key', 'admission_officer')
        ->assertJsonPath('data.assignments.0.branch.uid', $w->a->uid)
        ->assertJsonPath('data.permissions', fn ($p) => in_array('cycles.view', $p) && ! in_array('cycles.manage', $p));
    $this->actingAs($user)->patchJson('/api/v1/me', ['name' => 'New Name'])->assertOk()->assertJsonPath('data.name', 'New Name');
});

it('serves the single-page app for screen URLs', function () {
    $this->withoutVite()->get('/branches')->assertOk()->assertSee('<div id="app">', false);
});

it('schedules only commands that exist', function () {
    preg_match_all('/\$task\(\'[^\']+\', \'([^\']+)\'/', file_get_contents(base_path('routes/console.php')), $m);
    expect($m[1])->not->toBeEmpty()
        ->and(array_diff($m[1], array_keys(Artisan::all())))->toBe([]);
});
