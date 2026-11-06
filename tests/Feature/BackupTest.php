<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organisation\Models\Institution;
use App\Modules\System\Mail\BackupMail;
use App\Modules\System\Models\SystemSetting;
use App\Modules\System\Services\BackupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/backups-'.uniqid());
    $this->app['config']->set('filesystems.disks.private.root', $this->dir.'-docs');
    Storage::fake('private');
    @mkdir(storage_path('app/backups'), 0750, true);
    array_map('unlink', array_merge(glob(storage_path('app/backups').'/trinix-admission-*.zip') ?: [], glob(storage_path('app/backups').'/trinix-admission-*.enc') ?: []));
    $this->admin = userWithRole('super_admin');
    $this->it = userWithRole('it_admin');
    Mail::fake();
});

afterEach(function () {
    array_map('unlink', array_merge(glob(storage_path('app/backups').'/trinix-admission-*.zip') ?: [], glob(storage_path('app/backups').'/trinix-admission-*.enc') ?: []));
});

function backupPassword(object $t, string $pw = 'a-long-backup-password'): void
{
    $t->actingAs($t->admin)->putJson('/api/v1/system/backups/settings', ['password' => $pw, 'password_confirmation' => $pw])->assertOk();
}

it('keeps backups to people with the backup permission', function () {
    $this->actingAs($this->it)->getJson('/api/v1/system/backups')->assertForbidden();
    $this->actingAs($this->it)->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/v1/system/backups')->assertOk()->assertJsonPath('data.settings.password_set', false)
        ->assertJsonPath('data.can_restore', true);
});

it('needs a backup password before making or scheduling backups, and never shows it', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/v1/system/backups/settings', ['enabled' => true])->assertUnprocessable();
    $this->putJson('/api/v1/system/backups/settings', ['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
    backupPassword($this);
    $r = $this->getJson('/api/v1/system/backups')->assertOk();
    expect($r->json('data.settings.password_set'))->toBeTrue()
        ->and(json_encode($r->json()))->not->toContain('a-long-backup-password')
        ->and(json_encode(AuditLog::where('action', 'backup.settings_updated')->first()->new_values))->not->toContain('a-long-backup-password');
    $this->getJson('/api/v1/system/settings')->assertOk()->assertJsonMissing(['key' => 'backups']);
    $this->patchJson('/api/v1/system/settings/backups', ['value' => 'x'])->assertNotFound();
});

it('makes an encrypted backup that cannot be read without the password', function () {
    backupPassword($this);
    $info = $this->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertCreated()->json('data');
    $path = storage_path('app/backups/'.$info['name']);
    expect(is_file($path))->toBeTrue();

    $zip = new ZipArchive;
    $zip->open($path);
    expect($zip->getFromName('database.sql'))->toBeFalse();
    $zip->setPassword('a-long-backup-password');
    $sql = $zip->getFromName('database.sql');
    $zip->close();
    expect($sql)->toStartWith('-- trinix-admission-backup ')->toMatch('/INSERT INTO [`"]users[`"]/')->not->toMatch('/INSERT INTO [`"]sessions[`"]/');

    $this->get('/api/v1/system/backups/'.$info['name'].'/download')->assertOk()->assertDownload($info['name']);
    $this->get('/api/v1/system/backups/../../.env/download')->assertNotFound();
    expect(AuditLog::where('action', 'backup.downloaded')->count())->toBe(1);
});

it('restores a backup after a safety backup, with the word RESTORE and the admin password', function () {
    backupPassword($this);
    Institution::create(['code' => 'KEEP', 'name' => 'Kept institution']);
    $info = $this->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertCreated()->json('data');
    Institution::where('code', 'KEEP')->delete();
    Institution::create(['code' => 'LATER', 'name' => 'Added after the backup']);

    $url = '/api/v1/system/backups/'.$info['name'].'/restore';
    $this->postJson($url, ['confirm' => 'restore', 'password' => 'correct-horse-battery'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->postJson($url, ['confirm' => 'RESTORE', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson($url, ['confirm' => 'RESTORE', 'password' => 'correct-horse-battery', 'backup_password' => 'not-the-password'])->assertUnprocessable();
    $this->actingAs($this->it)->postJson($url, ['confirm' => 'RESTORE', 'password' => 'correct-horse-battery'])->assertForbidden();

    $this->actingAs($this->admin)->postJson($url, ['confirm' => 'RESTORE', 'password' => 'correct-horse-battery'])->assertOk();
    expect(Institution::where('code', 'KEEP')->exists())->toBeTrue()
        ->and(Institution::where('code', 'LATER')->exists())->toBeFalse()
        ->and(collect(app(BackupService::class)->list())->where('safety', true))->toHaveCount(1)
        ->and(AuditLog::where('action', 'backup.restored')->exists())->toBeTrue();
});

it('takes back an uploaded backup only if it opens with the password', function () {
    backupPassword($this);
    $info = $this->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertCreated()->json('data');
    $copy = tempnam(sys_get_temp_dir(), 'up').'.zip';
    copy(storage_path('app/backups/'.$info['name']), $copy);
    $this->post('/api/v1/system/backups/upload', ['file' => new UploadedFile($copy, 'backup.zip', 'application/zip', null, true), 'backup_password' => 'wrong-password-here'], ['Accept' => 'application/json'])
        ->assertUnprocessable();
    $this->post('/api/v1/system/backups/upload', ['file' => new UploadedFile($copy, 'backup.zip', 'application/zip', null, true)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.kind', 'upload');
    $junk = tempnam(sys_get_temp_dir(), 'junk').'.zip';
    file_put_contents($junk, 'not a zip');
    $this->post('/api/v1/system/backups/upload', ['file' => new UploadedFile($junk, 'x.zip', 'application/zip', null, true)], ['Accept' => 'application/json'])->assertUnprocessable();
});

it('makes the daily backup once after the set time and emails it encrypted', function () {
    $this->actingAs($this->admin)->putJson('/api/v1/system/backups/settings', ['password' => 'a-long-backup-password', 'password_confirmation' => 'a-long-backup-password',
        'enabled' => true, 'time' => '02:00', 'emails' => ['it@example.test'], 'full_day' => null])->assertOk();
    $service = app(BackupService::class);

    $this->travelTo(now('Asia/Kolkata')->setTime(1, 30));
    expect($service->runScheduled())->toBeNull();
    $this->travelTo(now('Asia/Kolkata')->setTime(2, 15));
    expect($service->runScheduled())->not->toBeNull()
        ->and($service->runScheduled())->toBeNull();
    Mail::assertSent(BackupMail::class, fn ($m) => $m->hasTo('it@example.test') && count($m->attachments()) === 1);

    $this->travelTo(now('Asia/Kolkata')->addDay()->setTime(3, 0));
    $this->putJson('/api/v1/system/backups/settings', ['email_limit_mb' => 1])->assertOk();
    expect($service->runScheduled())->not->toBeNull();
    Mail::assertSent(BackupMail::class, 2);
});

it('sends a notice instead of the file when the backup is too large for email', function () {
    backupPassword($this);
    $this->putJson('/api/v1/system/backups/settings', ['emails' => ['it@example.test']])->assertOk();
    $info = app(BackupService::class)->create('db');
    $info['size'] = 60 * 1048576;
    app(BackupService::class)->email($info);
    Mail::assertSent(BackupMail::class, fn ($m) => $m->attachments() === []);
});

it('adds the documents to a full backup and keeps only the newest backups', function () {
    backupPassword($this);
    Storage::disk('private')->put('documents/a/b.pdf', fakePdf('x'));
    $this->putJson('/api/v1/system/backups/settings', ['keep' => 2])->assertOk();
    $full = $this->postJson('/api/v1/system/backups', ['kind' => 'full'])->assertCreated()->json('data');
    $zip = new ZipArchive;
    $zip->open(storage_path('app/backups/'.$full['name']));
    $zip->setPassword('a-long-backup-password');
    expect($zip->getFromName('documents/documents/a/b.pdf'))->toStartWith('%PDF');
    $zip->close();

    foreach (range(1, 3) as $i) {
        $this->travel(1)->seconds();
        $this->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertCreated();
    }
    $list = collect(app(BackupService::class)->list());
    expect($list->where('kind', 'db'))->toHaveCount(2)->and($list->where('kind', 'full'))->toHaveCount(1);
});

it('shows backups on System Health', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/system/health')->assertOk()->assertJsonPath('data.checks.backups.ok', false);
    $this->actingAs($this->admin)->putJson('/api/v1/system/backups/settings', ['password' => 'a-long-backup-password', 'password_confirmation' => 'a-long-backup-password', 'enabled' => true])->assertOk();
    app(BackupService::class)->create('db');
    $this->getJson('/api/v1/system/health')->assertOk()->assertJsonPath('data.checks.backups.ok', true);
});

it('keeps the backup and says so when only the email fails', function () {
    backupPassword($this);
    $this->putJson('/api/v1/system/backups/settings', ['emails' => ['it@example.test']])->assertOk();
    Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
    $r = $this->postJson('/api/v1/system/backups', ['kind' => 'db', 'email' => true])->assertCreated();
    expect($r->json('message'))->toContain('Backup made')->toContain('email could not be sent')
        ->and(app(BackupService::class)->list())->toHaveCount(1);
});

it('makes, opens and restores OpenSSL-encrypted backups when the server has no zip extension', function () {
    config(['backups.format' => 'openssl']);
    backupPassword($this);
    Institution::create(['code' => 'KEEP', 'name' => 'Kept institution']);
    $info = $this->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertCreated()->json('data');
    expect($info['name'])->toEndWith('.sql.gz.enc');
    $path = storage_path('app/backups/'.$info['name']);
    $raw = file_get_contents($path);
    expect($raw)->toStartWith('Salted__')->not->toContain('INSERT INTO')
        ->and(BackupService::unseal($raw, 'wrong-password-123'))->toBeNull();

    // The standard openssl tool opens it too (documented for use outside the app).
    $cmd = 'openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -pass pass:a-long-backup-password -in '.escapeshellarg($path).' | gunzip';
    expect((string) shell_exec($cmd))->toStartWith('-- trinix-admission-backup ');

    $this->postJson('/api/v1/system/backups', ['kind' => 'full'])->assertUnprocessable();
    Institution::where('code', 'KEEP')->delete();
    $this->postJson('/api/v1/system/backups/'.$info['name'].'/restore', ['confirm' => 'RESTORE', 'password' => 'correct-horse-battery'])->assertOk();
    expect(Institution::where('code', 'KEEP')->exists())->toBeTrue();

    $copy = tempnam(sys_get_temp_dir(), 'up').'.enc';
    copy($path, $copy);
    $this->post('/api/v1/system/backups/upload', ['file' => new UploadedFile($copy, $info['name'], 'application/octet-stream', null, true)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.kind', 'upload');
    $this->get('/api/v1/system/backups/'.$info['name'].'/download')->assertOk();
});

it('encrypts large files in pieces that the openssl tool still opens, and refuses a wrong password', function () {
    $dir = sys_get_temp_dir();
    $plain = tempnam($dir, 'pl');
    file_put_contents($plain, random_bytes(2 * 1048576 + 1234)); // more than two pieces, not a multiple of 16
    $sealed = tempnam($dir, 'se');
    BackupService::sealFile($plain, $sealed, 'pw-123456789012');
    $out = tempnam($dir, 'ou');
    expect(BackupService::unsealFile($sealed, $out, 'pw-123456789012'))->toBeTrue()
        ->and(md5_file($out))->toBe(md5_file($plain))
        ->and(BackupService::unsealFile($sealed, $out, 'wrong-password-1'))->toBeFalse();
    $cli = tempnam($dir, 'cl');
    shell_exec('openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -pass pass:pw-123456789012 -in '.escapeshellarg($sealed).' -out '.escapeshellarg($cli));
    expect(md5_file($cli))->toBe(md5_file($plain));
    array_map('unlink', [$plain, $sealed, $out, $cli]);
});

it('shows a failed backup on the page and in Health, and retries the daily one at most 3 times', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertUnprocessable(); // no password yet
    expect($this->getJson('/api/v1/system/backups')->json('data.last_error.message'))->toContain('backup password');

    backupPassword($this);
    $this->putJson('/api/v1/system/backups/settings', ['enabled' => true, 'time' => '00:00'])->assertOk();
    $svc = app(BackupService::class);
    $setting = SystemSetting::where('key', BackupService::SETTING)->first();
    $good = $setting->value;
    $setting->update(['value' => ['password' => 'not-decryptable'] + $good]); // e.g. APP_KEY changed since the password was saved
    foreach (range(1, 4) as $i) {
        try {
            $svc->runScheduled();
        } catch (Throwable) {
        }
    }
    $setting->update(['value' => $good]);
    expect((int) cache('backup:tries:'.now(config('app.display_timezone'))->toDateString()))->toBe(3)
        ->and(BackupService::lastError()['trigger'])->toBe('schedule')
        ->and(BackupService::lastError()['message'])->toContain('Set the backup password again');

    cache()->forget('backup:tries:'.now(config('app.display_timezone'))->toDateString());
    expect($svc->runScheduled())->not->toBeNull()
        ->and(BackupService::lastError())->toBeNull()
        ->and($svc->runScheduled())->toBeNull(); // once a day
});

it('makes uncompressed .sql.enc backups when PHP has no zlib, and restores them', function () {
    config(['backups.format' => 'openssl', 'backups.compress' => false]);
    backupPassword($this);
    Institution::create(['code' => 'KEEP', 'name' => 'Kept institution']);
    $info = $this->postJson('/api/v1/system/backups', ['kind' => 'db'])->assertCreated()->json('data');
    expect($info['name'])->toEndWith('.sql.enc')->not->toEndWith('.gz.enc');
    $path = storage_path('app/backups/'.$info['name']);
    $cmd = 'openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -pass pass:a-long-backup-password -in '.escapeshellarg($path);
    expect((string) shell_exec($cmd))->toStartWith('-- trinix-admission-backup ');
    expect(collect($this->getJson('/api/v1/system/backups')->json('data.backups'))->pluck('name'))->toContain($info['name']);

    Institution::where('code', 'KEEP')->delete();
    $this->postJson('/api/v1/system/backups/'.$info['name'].'/restore', ['confirm' => 'RESTORE', 'password' => 'correct-horse-battery'])->assertOk();
    expect(Institution::where('code', 'KEEP')->exists())->toBeTrue();
});
