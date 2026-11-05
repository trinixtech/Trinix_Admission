<?php

namespace App\Http\Controllers;

use App\Support\Installer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * /install: check → database → site and email → storage and services → save → tables → first Super Admin → finish.
 * Runs without sessions or CSRF (there may be no key or database yet); every step posts the answers so far,
 * and nothing is written before "Save". Returns 404 once the first Super Admin exists.
 */
class InstallController
{
    private const DEFAULTS = [
        'db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_database' => 'admission', 'db_username' => '', 'db_password' => '',
        'app_name' => 'Trinix Admission', 'app_url' => '', 'app_env' => 'production', 'timezone' => 'Asia/Kolkata',
        'mail_mailer' => 'smtp', 'mail_scheme' => '', 'mail_host' => '', 'mail_port' => '587', 'mail_username' => '', 'mail_password' => '',
        'mail_from_address' => '', 'mail_from_name' => 'Admissions Office', 'mail_test_to' => '',
        'storage' => 'local', 's3_key' => '', 's3_secret' => '', 's3_region' => 'ap-south-1', 's3_bucket' => '', 's3_endpoint' => '',
        'redis' => '', 'redis_host' => '127.0.0.1', 'redis_port' => '6379', 'redis_password' => '',
        'scanner' => 'clamav', 'clamav_host' => '127.0.0.1', 'clamav_port' => '3310',
    ];

    public function show(Request $request): View
    {
        $this->ensureOpen();

        return $this->page('check', $this->answers($request));
    }

    public function step(Request $request): View
    {
        $this->ensureOpen();
        $a = $this->answers($request);
        $action = (string) $request->input('action');

        return match ($action) {
            'database' => $this->page('database', $a),
            'test_database' => $this->testDatabase($a),
            'site' => $this->page('site', $a),
            'test_mail' => $this->testMail($a),
            'services' => $this->validateSite($a) ?? $this->page('services', $a),
            'review' => $this->validateServices($a) ?? $this->page('review', $a),
            'save' => $this->save($a),
            'tables' => $this->tables(),
            'admin' => $this->admin($request),
            default => $this->page('check', $a),
        };
    }

    private function testDatabase(array $a): View
    {
        if ($error = Installer::testDatabase(['driver' => $a['db_driver'], 'host' => $a['db_host'], 'port' => $a['db_port'], 'database' => $a['db_database'],
            'username' => $a['db_username'], 'password' => $a['db_password']])) {
            return $this->page('database', $a, ['db' => $error]);
        }
        if ($a['app_url'] === '') {
            $a['app_url'] = request()->getSchemeAndHttpHost();
        }

        return $this->page('site', $a, [], 'Connected to the database.');
    }

    private function testMail(array $a): View
    {
        if ($error = $this->validateSite($a, true)) {
            return $error;
        }
        $error = Installer::testMail(['scheme' => $a['mail_scheme'], 'host' => $a['mail_host'], 'port' => $a['mail_port'], 'username' => $a['mail_username'],
            'password' => $a['mail_password'], 'from_address' => $a['mail_from_address'], 'from_name' => $a['mail_from_name']], $a['mail_test_to']);

        return $this->page('site', $a, $error ? ['mail' => $error] : [], $error ? null : "Test email sent to {$a['mail_test_to']}. Check that it arrived.");
    }

    private function validateSite(array $a, bool $needTestAddress = false): ?View
    {
        $v = Validator::make($a, [
            'app_name' => ['required', 'max:120'], 'app_url' => ['required', 'url'], 'app_env' => ['in:production,staging,local'], 'timezone' => ['required', 'timezone'],
            'mail_mailer' => ['in:smtp,log'], 'mail_host' => ['required_if:mail_mailer,smtp'], 'mail_port' => ['required_if:mail_mailer,smtp', 'nullable', 'integer'],
            'mail_from_address' => ['required', 'email'], 'mail_from_name' => ['required', 'max:120'],
            'mail_test_to' => [$needTestAddress ? 'required' : 'nullable', 'nullable', 'email'],
        ], [], ['app_url' => 'site address', 'mail_from_address' => 'sender address', 'mail_test_to' => 'test address']);

        return $v->fails() ? $this->page('site', $a, $v->errors()->all()) : null;
    }

    private function validateServices(array $a): ?View
    {
        $v = Validator::make($a, [
            'storage' => ['in:local,s3'], 's3_bucket' => ['required_if:storage,s3'], 's3_key' => ['required_if:storage,s3'], 's3_secret' => ['required_if:storage,s3'],
            'scanner' => ['in:clamav,eicar'], 'redis_host' => ['required_with:redis'],
        ], [], ['s3_bucket' => 'bucket', 's3_key' => 'access key', 's3_secret' => 'secret key']);

        return $v->fails() ? $this->page('services', $a, $v->errors()->all()) : null;
    }

    private function save(array $a): View
    {
        if (($site = $this->validateSite($a)) || ($svc = $this->validateServices($a))) {
            return $site ?? $svc;
        }
        if ($error = Installer::testDatabase(['driver' => $a['db_driver'], 'host' => $a['db_host'], 'port' => $a['db_port'], 'database' => $a['db_database'],
            'username' => $a['db_username'], 'password' => $a['db_password']])) {
            return $this->page('database', $a, ['db' => $error]);
        }
        try {
            Installer::writeEnv(Installer::envValues($a));
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            return $this->page('review', $a, ['Could not write the .env file: '.$e->getMessage()]);
        }

        return $this->page('tables', [], [], 'Settings saved to .env. A second app server uses a copy of the same file.');
    }

    private function tables(): View
    {
        try {
            $out = Installer::migrate();
        } catch (Throwable $e) {
            return $this->page('tables', [], ['Creating the tables failed: '.$e->getMessage()]);
        }

        return $this->page('admin', [], [], 'Tables created, roles and permissions loaded.', ['output' => $out]);
    }

    private function admin(Request $request): View
    {
        $v = Validator::make($request->only('name', 'email', 'password', 'password_confirmation'), [
            'name' => ['required', 'max:255'], 'email' => ['required', 'email'], 'password' => ['required', 'min:12', 'confirmed'],
        ]);
        if ($v->fails()) {
            return $this->page('admin', [], $v->errors()->all(), null, ['name' => $request->input('name'), 'email' => $request->input('email')]);
        }
        Installer::createAdmin($request->input('name'), $request->input('email'), $request->input('password'));

        return $this->page('finish', []);
    }

    private function answers(Request $request): array
    {
        $a = [];
        foreach (self::DEFAULTS as $k => $default) {
            $a[$k] = trim((string) $request->input($k, $default));
        }

        return $a;
    }

    private function page(string $step, array $a, array $errors = [], ?string $info = null, array $extra = []): View
    {
        return view('install', ['step' => $step, 'a' => $a, 'problems' => array_values($errors), 'info' => $info, 'checks' => $step === 'check' ? Installer::checks() : [],
            'canContinue' => $step !== 'check' || collect(Installer::checks())->where('required', true)->every('ok')] + $extra);
    }

    private function ensureOpen(): void
    {
        if (Installer::installed()) {
            throw new NotFoundHttpException;
        }
    }
}
