<?php

namespace App\Support;

use App\Modules\Access\Models\Role;
use App\Modules\Access\Models\User;
use App\Modules\Access\Models\UserRoleAssignment;
use App\Modules\Access\Services\AccessCatalog;
use App\Modules\Audit\Services\Auditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * The browser installation wizard (Stage A, D26). Nothing is written before the "save" step; the wizard is open
 * only until the first Super Admin exists. A second app server copies the same .env and is "installed" as soon as
 * it can see that admin in the shared database.
 */
class Installer
{
    public static function lockPath(): string
    {
        return config('installer.lock') ?: storage_path('app/installed.lock');
    }

    public static function installed(): bool
    {
        if (is_file(self::lockPath())) {
            return true;
        }
        if (! config('app.key')) {
            return false;
        }
        try {
            $done = DB::table('user_role_assignments')->join('roles', 'roles.id', '=', 'user_role_assignments.role_id')
                ->where('roles.key', 'super_admin')->whereNull('user_role_assignments.revoked_at')->exists();
        } catch (Throwable) {
            return false;
        }
        if ($done) {
            @file_put_contents(self::lockPath(), now()->toIso8601String());
        }

        return $done;
    }

    /** @return list<array{name: string, ok: bool, required: bool, note: string}> */
    public static function checks(): array
    {
        $ext = fn (string $e) => extension_loaded($e);
        $writable = fn (string $p) => is_dir($p) && is_writable($p);
        $c = [
            ['PHP 8.3 or newer', version_compare(PHP_VERSION, '8.3.0', '>='), true, 'This server has PHP '.PHP_VERSION],
            ['MySQL driver (pdo_mysql)', $ext('pdo_mysql'), true, 'Needed for MySQL 8'],
        ];
        $c[] = ['PHP extension openssl', $ext('openssl'), true, 'Needed for sign-in security and encrypted backups'];
        // Recommended: without them the app runs in a limited mode (see App\Support\Compat).
        foreach ([
            'zip' => 'Without it: exports are CSV, backups are encrypted with OpenSSL, Excel and ZIP uploads are not read',
            'xmlreader' => 'Without it: student and faculty files must be CSV, not Excel',
            'dom' => 'Without it: printouts open as printable pages instead of PDF; Excel uploads are not read',
            'fileinfo' => 'Without it: file types are told by their names',
            'mbstring' => 'A built-in replacement is used if missing',
            'intl' => 'A built-in replacement is used if missing',
            'curl' => 'Needed only for importing documents by web link',
        ] as $e => $note) {
            $c[] = ["PHP extension $e", $ext($e), false, $note];
        }
        $c[] = ['storage/ folder writable', $writable(storage_path()), true, storage_path()];
        $c[] = ['bootstrap/cache/ folder writable', $writable(base_path('bootstrap/cache')), true, base_path('bootstrap/cache')];
        $c[] = ['.env can be written', is_writable(base_path()) || is_writable(base_path('.env')), true, base_path('.env')];
        $c[] = ['Built screens (public/build)', is_file(public_path('build/manifest.json')), true, 'Run the release build (npm run build) before uploading'];
        $c[] = ['Redis extension (phpredis)', $ext('redis'), false, 'Needed for production queues and sessions; can be added later'];
        $c[] = ['ClamAV virus scanner', self::clamav(), false, 'Needed before real documents are uploaded; until then use test mode'];

        return array_map(fn ($r) => ['name' => $r[0], 'ok' => (bool) $r[1], 'required' => $r[2], 'note' => $r[3]], $c);
    }

    private static function clamav(): bool
    {
        $s = @fsockopen((string) config('documents.clamav.host', '127.0.0.1'), (int) config('documents.clamav.port', 3310), $no, $err, 1);
        if ($s) {
            fclose($s);
        }

        return (bool) $s;
    }

    /** @return string|null error message */
    public static function testDatabase(array $db): ?string
    {
        try {
            if ($db['driver'] === 'sqlite') {
                $path = $db['database'] ?: database_path('database.sqlite');
                if (! is_file($path)) {
                    @touch($path);
                }
                new PDO("sqlite:$path");

                return null;
            }
            $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['database']}", $db['username'], $db['password'], [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $version = (string) $pdo->query('select version()')->fetchColumn();
            if (version_compare(preg_replace('/[^0-9.].*$/', '', $version), '8.0.0', '<')) {
                return "MySQL 8 or newer is needed (this server has $version).";
            }

            return null;
        } catch (Throwable $e) {
            return self::explainDatabaseError($e->getMessage(), $db);
        }
    }

    /** A database error in plain words, with the server's own message after it. */
    public static function explainDatabaseError(string $message, array $db): string
    {
        $detail = Str::limit(preg_replace('/\s+/', ' ', $message), 200);
        $hint = match (true) {
            str_contains($message, '[1045]') => 'The user name or password is wrong, or this user may not connect from this server. Check them in the hosting panel (MySQL Databases).',
            str_contains($message, '[1044]'), str_contains($message, '[1142]') => 'The user has no rights on this database. In the hosting panel, add the user to the database with all privileges.',
            str_contains($message, '[1049]') => "There is no database named \"{$db['database']}\". Create it in the hosting panel; on cPanel its name usually starts with your account name and an underscore.",
            str_contains($message, '[2002]'), str_contains($message, '[2006]'), str_contains($message, 'timed out') => "No database server answers at {$db['host']}:{$db['port']}. On shared hosting the host is usually localhost.",
            default => 'Could not connect to the database.',
        };

        return "$hint ($detail)";
    }

    /**
     * The command-line PHP for the cron job. Under a web server PHP_BINARY can be php-fpm, lsphp or php-cgi,
     * which cannot run artisan; look for the matching command-line php next to it.
     */
    public static function cliPhp(?string $binary = null): string
    {
        $binary ??= PHP_BINARY;
        $name = basename((string) $binary);
        if ($binary && preg_match('/^php(\d+(\.\d+)?)?$/', $name)) {
            return $binary;
        }
        if ($binary) {
            $dir = dirname($binary);
            foreach ([$dir.'/php', dirname($dir).'/bin/php', $dir.'/../../bin/php'] as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }
        }
        $v = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;
        foreach (["/opt/cpanel/ea-php$v/root/usr/bin/php", "/opt/alt/php$v/usr/bin/php", '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return '/usr/local/bin/php';
    }

    /** Send a test email with the given settings, without saving them. @return string|null error message */
    public static function testMail(array $m, string $to): ?string
    {
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', ['transport' => 'smtp', 'scheme' => $m['scheme'] ?: null, 'host' => $m['host'], 'port' => (int) $m['port'],
            'username' => $m['username'] ?: null, 'password' => $m['password'] ?: null, 'timeout' => 10]);
        Config::set('mail.from', ['address' => $m['from_address'], 'name' => $m['from_name']]);
        try {
            app('mail.manager')->forgetMailers();
            Mail::raw('This is a test email from the Trinix Admission installation wizard.', fn ($msg) => $msg->to($to)->subject('Trinix Admission: test email'));

            return null;
        } catch (Throwable $e) {
            return 'The email could not be sent: '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 200);
        }
    }

    /** The .env lines the answers produce. */
    public static function envValues(array $a): array
    {
        $v = [
            'APP_NAME' => $a['app_name'], 'APP_ENV' => $a['app_env'], 'APP_DEBUG' => $a['app_env'] === 'production' ? 'false' : 'true',
            'APP_URL' => rtrim($a['app_url'], '/'), 'APP_DISPLAY_TIMEZONE' => $a['timezone'],
            'SANCTUM_STATEFUL_DOMAINS' => parse_url($a['app_url'], PHP_URL_HOST).(parse_url($a['app_url'], PHP_URL_PORT) ? ':'.parse_url($a['app_url'], PHP_URL_PORT) : ''),
            'SESSION_SECURE_COOKIE' => str_starts_with($a['app_url'], 'https://') ? 'true' : 'false',
            'DB_CONNECTION' => $a['db_driver'],
            'MAIL_MAILER' => $a['mail_mailer'], 'MAIL_SCHEME' => $a['mail_scheme'] ?: 'null', 'MAIL_HOST' => $a['mail_host'], 'MAIL_PORT' => $a['mail_port'],
            'MAIL_USERNAME' => $a['mail_username'] ?: 'null', 'MAIL_PASSWORD' => $a['mail_password'] ?: 'null',
            'MAIL_FROM_ADDRESS' => $a['mail_from_address'], 'MAIL_FROM_NAME' => $a['mail_from_name'],
            'PRIVATE_DISK_DRIVER' => $a['storage'] === 's3' ? 's3' : 'local',
            'DOCUMENT_SCANNER' => $a['scanner'] === 'clamav' ? 'clamav' : 'eicar',
            'CLAMAV_HOST' => $a['clamav_host'] ?: '127.0.0.1', 'CLAMAV_PORT' => $a['clamav_port'] ?: '3310',
        ];
        if ($a['db_driver'] === 'mysql') {
            $v += ['DB_HOST' => $a['db_host'], 'DB_PORT' => $a['db_port'], 'DB_DATABASE' => $a['db_database'], 'DB_USERNAME' => $a['db_username'], 'DB_PASSWORD' => $a['db_password']];
        } else {
            $v['DB_DATABASE'] = $a['db_database'] ?: database_path('database.sqlite');
        }
        if ($a['storage'] === 's3') {
            $v += ['AWS_ACCESS_KEY_ID' => $a['s3_key'], 'AWS_SECRET_ACCESS_KEY' => $a['s3_secret'], 'AWS_DEFAULT_REGION' => $a['s3_region'],
                'AWS_BUCKET' => $a['s3_bucket'], 'AWS_ENDPOINT' => $a['s3_endpoint'], 'AWS_USE_PATH_STYLE_ENDPOINT' => $a['s3_endpoint'] ? 'true' : 'false'];
        }
        if ($a['redis']) {
            $v += ['REDIS_HOST' => $a['redis_host'], 'REDIS_PORT' => $a['redis_port'] ?: '6379', 'REDIS_PASSWORD' => $a['redis_password'] ?: 'null',
                'SESSION_DRIVER' => 'redis', 'CACHE_STORE' => 'redis', 'QUEUE_CONNECTION' => 'redis', 'QUEUE_FROM_SCHEDULER' => 'false'];
        } else {
            // No Redis (e.g. shared hosting): database for sessions, cache and queue; the cron's schedule:run works the queue.
            $v += ['SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'QUEUE_FROM_SCHEDULER' => 'true'];
        }

        return $v;
    }

    /** Merge values into .env: other lines are kept; an existing APP_KEY is never replaced. */
    public static function writeEnv(array $values, ?string $path = null): void
    {
        $path ??= base_path('.env');
        $text = is_file($path) ? file_get_contents($path) : (is_file(base_path('.env.example')) ? file_get_contents(base_path('.env.example')) : '');
        if (! preg_match('/^APP_KEY=\S+/m', $text)) {
            $values['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        } else {
            unset($values['APP_KEY']);
        }
        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote((string) $value);
            $text = preg_match("/^{$key}=.*$/m", $text) ? preg_replace("/^{$key}=.*$/m", str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $text) : rtrim($text)."\n".$line."\n";
        }
        file_put_contents($path, $text);
        @chmod($path, 0640);
    }

    private static function quote(string $v): string
    {
        return ($v === '' || preg_match('/[\s#"\'$\\\\]/', $v)) && ! in_array($v, ['null', 'true', 'false'], true)
            ? '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"' : $v;
    }

    /** Create the tables and load roles, permissions and settings. @return string the command output */
    public static function migrate(): string
    {
        Artisan::call('migrate', ['--force' => true]);
        $out = Artisan::output();
        app(AccessCatalog::class)->sync(); // what `php artisan access:sync` does (that command exists only on the command line)

        return $out."Roles, permissions and default settings loaded.\n";
    }

    public static function createAdmin(string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password) {
            $user = User::create(['name' => $name, 'email' => mb_strtolower($email), 'password' => $password, 'status' => User::ACTIVE]);
            UserRoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', 'super_admin')->value('id'), 'valid_from' => today()]);
            app(Auditor::class)->record('install.completed', $user, null, ['admin' => $user->email], actorId: $user->id);
            @file_put_contents(self::lockPath(), now()->toIso8601String());

            return $user;
        });
    }
}
