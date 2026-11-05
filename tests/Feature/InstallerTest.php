<?php

use App\Modules\Access\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Installer;

beforeEach(function () {
    $this->lock = tempnam(sys_get_temp_dir(), 'lock');
    unlink($this->lock);
    config(['installer.lock' => $this->lock, 'installer.enforce' => true]);
});
afterEach(fn () => @unlink($this->lock));

it('sends every page to the installer until the first Super Admin exists, then closes it for good', function () {
    $this->get('/')->assertRedirect('/install');
    $this->getJson('/api/v1/me')->assertStatus(503);
    $this->get('/install')->assertOk()->assertSee('Server check')->assertSee('PHP 8.3 or newer');

    $this->post('/install', ['action' => 'admin', 'name' => 'Admin', 'email' => 'root@iift.test', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertOk()->assertSee('at least 12 characters');
    $this->post('/install', ['action' => 'admin', 'name' => 'Admin', 'email' => 'Root@IIFT.test', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
        ->assertOk()->assertSee('The installation is complete');
    $u = User::where('email', 'root@iift.test')->first();
    expect($u->activeAssignments()->with('role')->first()->role->key)->toBe('super_admin')
        ->and(is_file($this->lock))->toBeTrue()->and(AuditLog::where('action', 'install.completed')->count())->toBe(1);

    $this->get('/install')->assertNotFound();
    $this->post('/install', ['action' => 'admin'])->assertNotFound();
    $this->withoutVite()->get('/')->assertOk(); // no longer redirected (the built screens are not needed here)
});

it('tests the database before going on, and checks the site and email answers', function () {
    $this->post('/install', ['action' => 'test_database', 'db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => '1', 'db_database' => 'x', 'db_username' => 'x'])
        ->assertOk()->assertSee('Could not connect');
    $this->post('/install', ['action' => 'test_database', 'db_driver' => 'sqlite', 'db_database' => tempnam(sys_get_temp_dir(), 'db')])
        ->assertOk()->assertSee('Connected to the database.')->assertSee('Site and email');
    $this->post('/install', ['action' => 'services', 'app_url' => 'not a url', 'mail_from_address' => 'x'])->assertOk()->assertSee('site address');
});

it('writes .env by merging, keeping other lines and never replacing an existing key', function () {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_KEY=base64:keepme\nCUSTOM=1\nDB_HOST=old\n");
    Installer::writeEnv(['DB_HOST' => 'db.internal', 'MAIL_FROM_NAME' => 'Admissions Office', 'APP_KEY' => 'base64:new'], $path);
    $env = file_get_contents($path);
    expect($env)->toContain('APP_KEY=base64:keepme')->toContain('CUSTOM=1')->toContain('DB_HOST=db.internal')->toContain('MAIL_FROM_NAME="Admissions Office"')
        ->not->toContain('base64:new');

    $fresh = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($fresh, "APP_KEY=\n");
    Installer::writeEnv(['APP_NAME' => 'Trinix Admission'], $fresh);
    expect(file_get_contents($fresh))->toMatch('/^APP_KEY=base64:\S{40,}$/m');

    $v = Installer::envValues(['app_name' => 'A', 'app_env' => 'production', 'app_url' => 'https://adm.iift.test/', 'timezone' => 'Asia/Kolkata', 'db_driver' => 'mysql',
        'db_host' => 'h', 'db_port' => '3306', 'db_database' => 'd', 'db_username' => 'u', 'db_password' => 'p', 'mail_mailer' => 'smtp', 'mail_scheme' => '', 'mail_host' => 'm',
        'mail_port' => '587', 'mail_username' => '', 'mail_password' => '', 'mail_from_address' => 'a@b.c', 'mail_from_name' => 'X', 'storage' => 'local', 'scanner' => 'clamav',
        'clamav_host' => '', 'clamav_port' => '', 'redis' => '1', 'redis_host' => 'r', 'redis_port' => '', 'redis_password' => '']);
    expect($v)->toMatchArray(['APP_DEBUG' => 'false', 'APP_URL' => 'https://adm.iift.test', 'SANCTUM_STATEFUL_DOMAINS' => 'adm.iift.test', 'SESSION_SECURE_COOKIE' => 'true',
        'SESSION_DRIVER' => 'redis', 'QUEUE_CONNECTION' => 'redis', 'DOCUMENT_SCANNER' => 'clamav']);
});

it('shows pending database updates to the IT Administrator and runs them only with confirmation and password', function () {
    config(['installer.enforce' => false]);
    $it = userWithRole('it_admin', attributes: ['password' => 'it-admin-password-1']);
    $this->actingAs($it)->getJson('/api/v1/system/maintenance')->assertOk()->assertJsonPath('data.pending', []);
    $this->postJson('/api/v1/system/maintenance/migrate', ['confirm' => 'MIGRATE', 'password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/system/maintenance/migrate', ['confirm' => 'MIGRATE', 'password' => 'it-admin-password-1'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    $this->actingAs(userWithRole('admission_officer'))->getJson('/api/v1/system/maintenance')->assertForbidden();
});
