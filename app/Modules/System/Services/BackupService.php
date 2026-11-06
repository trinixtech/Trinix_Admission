<?php

namespace App\Modules\System\Services;

use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
use App\Modules\System\Mail\BackupMail;
use App\Modules\System\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backups without command-line tools: the database is written as SQL by PHP (shared hosting has no mysqldump),
 * packed in a ZIP encrypted with AES-256 and the admin's backup password, and kept in storage/app/backups
 * (outside the web root). A daily database backup can be emailed; a weekly full backup adds the documents.
 * Restore takes a safety backup first. Sessions, cache and queue tables are never backed up or restored.
 */
class BackupService
{
    public const SKIP = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    public const SETTING = 'backups';

    private const HEADER = '-- trinix-admission-backup ';

    private const DEFAULTS = ['enabled' => false, 'time' => '02:00', 'keep' => 7, 'keep_full' => 2, 'full_day' => 0,
        'emails' => [], 'email_limit_mb' => 20, 'password' => null];

    public const ERROR_KEY = 'backup:last_error';

    /** Encryption piece size: a multiple of the AES block (16 bytes). */
    private const PIECE = 1048576;

    public function __construct(private Auditor $auditor) {}

    public static function dir(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
            @file_put_contents("$dir/.htaccess", "Require all denied\n");
        }

        return $dir;
    }

    // ----- Settings ---------------------------------------------------------------------------------------------

    /** The backup settings, without the password (only whether one is set). */
    public function settings(): array
    {
        $raw = $this->raw();
        $out = array_diff_key($raw, ['password' => 1]);
        $out['password_set'] = ! empty($raw['password']);

        return $out;
    }

    public function updateSettings(array $data, User $actor): array
    {
        $raw = $this->raw();
        $new = array_merge($raw, array_intersect_key($data, self::DEFAULTS));
        if (array_key_exists('password', $data)) {
            $new['password'] = $data['password'] === null ? null : Crypt::encryptString($data['password']);
        }
        if ($new['enabled'] && empty($new['password'])) {
            throw new RuntimeException('Set a backup password before switching on daily backups.');
        }
        DB::transaction(function () use ($new, $raw, $actor) {
            SystemSetting::updateOrCreate(['key' => self::SETTING], ['value' => $new, 'description' => 'Backup schedule and email', 'is_technical' => false, 'updated_by' => $actor->id]);
            $hide = fn ($a) => ['password' => ! empty($a['password']) ? 'set' : 'not set'] + $a;
            $this->auditor->record('backup.settings_updated', null, $hide($raw), $hide($new), actorId: $actor->id);
        });

        return $this->settings();
    }

    private function raw(): array
    {
        $value = SystemSetting::where('key', self::SETTING)->value('value');

        return array_merge(self::DEFAULTS, is_array($value) ? $value : []);
    }

    private function password(?string $given = null): string
    {
        if ($given !== null && $given !== '') {
            return $given;
        }
        $enc = $this->raw()['password'];
        if (! $enc) {
            throw new RuntimeException('Set a backup password first (System → Backups).');
        }

        try {
            return Crypt::decryptString($enc);
        } catch (Throwable) {
            throw new RuntimeException('The saved backup password cannot be read (the app key changed since it was saved). Set the backup password again in System → Backups.');
        }
    }

    // ----- Making backups ---------------------------------------------------------------------------------------

    /**
     * @param  'db'|'full'  $kind
     * @return array{name: string, kind: string, size: int, created_at: string}
     */
    public function create(string $kind = 'db', ?User $actor = null, string $trigger = 'manual'): array
    {
        try {
            $info = $this->make($kind, $actor, $trigger);
            Cache::forget(self::ERROR_KEY);

            return $info;
        } catch (Throwable $e) {
            // Kept for System → Backups and Health, so a failed night-time backup is not silent. No secrets in it.
            Cache::forever(self::ERROR_KEY, ['at' => now()->toIso8601String(), 'trigger' => $trigger, 'kind' => $kind,
                'message' => $e instanceof RuntimeException ? $e->getMessage() : class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }

    /** The last failed backup, if the latest attempt failed. */
    public static function lastError(): ?array
    {
        return Cache::get(self::ERROR_KEY);
    }

    /** A temporary file in the app's own storage (the server's temporary folder may be shared or too small on hosting). */
    private function tempFile(string $prefix): string
    {
        $dir = self::dir();
        $path = @tempnam($dir, $prefix);
        if ($path === false || ! str_starts_with($path, $dir)) {
            $path && @unlink($path);
            $path = $dir.'/'.$prefix.bin2hex(random_bytes(8)).'.tmp';
            if (@file_put_contents($path, '') === false) {
                throw new RuntimeException("The backup folder cannot be written: $dir. In File Manager, give storage/app and storage/app/backups the permission 755 (or 775).");
            }
        }

        return $path;
    }

    private function make(string $kind, ?User $actor, string $trigger): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $sealed = self::sealed();
        if ($sealed && $kind === 'full') {
            throw new RuntimeException('Full backups with documents need the PHP extension zip, which this server does not have. Database backups work: use "Back up now".');
        }
        $password = $this->password();
        $dir = self::dir();
        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException("The backup folder cannot be written: $dir. In File Manager, give storage/app the permission 755 (or 775).");
        }
        $stamp = now(config('app.display_timezone', 'Asia/Kolkata'))->format('Ymd-His');
        $name = "trinix-admission-$kind-$stamp".($trigger === 'safety' ? '-before-restore' : '').($sealed ? (self::canGzip() ? '.sql.gz.enc' : '.sql.enc') : '.zip');
        $path = self::dir()."/$name";
        // The server's temporary folder may be shared or too small on hosting; use the app's own storage.
        $sql = $this->tempFile('bk');

        try {
            $fh = fopen($sql, 'wb');
            $this->dump($fh);
            fclose($fh);

            if ($sealed) {
                // Compressed when the server has zlib; encrypted in 1 MB pieces, so memory use stays small.
                $src = $sql;
                if (self::canGzip()) {
                    $src = $this->tempFile('gz');
                    self::gzipFile($sql, $src);
                }
                try {
                    self::sealFile($src, $path, $password);
                } finally {
                    $src !== $sql && @unlink($src);
                }
            } else {
                $zip = new ZipArchive;
                if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Could not create the backup file.');
                }
                $zip->setPassword($password);
                $zip->addFile($sql, 'database.sql');
                if (! $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256)) {
                    $zip->close();
                    @unlink($path);
                    throw new RuntimeException('This server\'s ZIP library cannot encrypt files (libzip without AES), so no backup was made: backups are never stored unencrypted. Ask the hosting company to enable AES encryption in PHP zip (libzip 1.2 or newer with crypto).');
                }
                $zip->addFromString('README.txt', "Trinix Admission backup ($kind), ".now()->toIso8601String().".\nOpen with the backup password. Restore from System → Backups, or import database.sql with phpMyAdmin.\n");
                if ($kind === 'full') {
                    $disk = Storage::disk('private');
                    foreach ($disk->allFiles() as $file) {
                        $entry = "documents/$file";
                        $local = config('filesystems.disks.private.driver') === 'local' ? $disk->path($file) : null;
                        $local && is_file($local) ? $zip->addFile($local, $entry) : $zip->addFromString($entry, (string) $disk->get($file));
                        $zip->setEncryptionName($entry, ZipArchive::EM_AES_256);
                    }
                }
                if (! $zip->close()) {
                    @unlink($path);
                    throw new RuntimeException('Could not write the backup file ('.$zip->getStatusString().'). Check the free disk space in cPanel.');
                }
            }
        } finally {
            @unlink($sql);
        }

        $info = $this->info($path);
        $this->auditor->record('backup.created', null, null, ['name' => $name, 'kind' => $kind, 'trigger' => $trigger, 'size' => $info['size']], actorId: $actor?->id);
        Cache::forever('health:last_backup', $info['created_at']);
        $this->prune();

        return $info;
    }

    /** Writes the database as SQL, one statement per line. */
    public function dump($out): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();
        $mysql = in_array($driver, ['mysql', 'mariadb'], true);
        $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        fwrite($out, self::HEADER.json_encode(['app' => 'trinix-admission', 'version' => config('app.version'), 'driver' => $mysql ? 'mysql' : $driver,
            'created_at' => now()->toIso8601String(), 'migrations' => $migrations])."\n");
        fwrite($out, $mysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" : "PRAGMA foreign_keys=OFF;\n");

        foreach ($this->tables() as $table) {
            $q = $mysql ? "`$table`" : "\"$table\"";
            $create = $mysql
                ? (array) DB::selectOne("SHOW CREATE TABLE $q")
                : ['sql' => DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql')];
            $ddl = preg_replace('/\s*\R\s*/', ' ', (string) ($mysql ? $create['Create Table'] : $create['sql']));
            fwrite($out, "DROP TABLE IF EXISTS $q;\n$ddl;\n");
            if (! $mysql) {
                foreach (DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', $table)->whereNotNull('sql')->pluck('sql') as $idx) {
                    fwrite($out, preg_replace('/\s*\R\s*/', ' ', $idx).";\n");
                }
            }
        }
        // All tables exist before any row goes in, so references between tables resolve in any order.
        foreach ($this->tables() as $table) {
            $q = $mysql ? "`$table`" : "\"$table\"";
            $columns = null;
            $rows = [];
            $stmt = $pdo->query("SELECT * FROM $q");
            $stmt->setFetchMode(\PDO::FETCH_ASSOC);
            foreach ($stmt as $row) {
                $columns ??= implode(',', array_map(fn ($c) => $mysql ? "`$c`" : "\"$c\"", array_keys($row)));
                $rows[] = '('.implode(',', array_map(fn ($v) => $this->quote($pdo, $v, $mysql), $row)).')';
                if (count($rows) >= 200) {
                    fwrite($out, "INSERT INTO $q ($columns) VALUES ".implode(',', $rows).";\n");
                    $rows = [];
                }
            }
            if ($rows) {
                fwrite($out, "INSERT INTO $q ($columns) VALUES ".implode(',', $rows).";\n");
            }
        }
        fwrite($out, $mysql ? "SET FOREIGN_KEY_CHECKS=1;\n" : "PRAGMA foreign_keys=ON;\n");
    }

    /** Values on one line: MySQL's quoting already escapes line breaks; SQLite's does not, so they are spliced in. */
    private function quote(\PDO $pdo, mixed $v, bool $mysql): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if ($mysql) {
            return $pdo->quote((string) $v);
        }

        return "'".str_replace(["'", "\r", "\n"], ["''", "'||char(13)||'", "'||char(10)||'"], (string) $v)."'";
    }

    /** @return list<string> */
    private function tables(): array
    {
        // Only this app's own database: on a shared MySQL server other databases must never be read or touched.
        $names = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? array_map(fn ($r) => array_values((array) $r)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            : DB::table('sqlite_master')->where('type', 'table')->orderBy('name')->pluck('name')->all();

        return array_values(array_filter($names, fn ($t) => ! in_array($t, self::SKIP, true) && ! str_starts_with($t, 'sqlite_')));
    }

    // ----- Listing, downloading, pruning ------------------------------------------------------------------------

    /** @return list<array{name: string, kind: string, size: int, created_at: string}> */
    public function list(): array
    {
        $files = array_merge(glob(self::dir().'/trinix-admission-*.zip') ?: [], glob(self::dir().'/trinix-admission-*.enc') ?: []);
        $out = array_map(fn ($f) => $this->info($f), $files);
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    public function path(string $name): string
    {
        if (! preg_match('/^trinix-admission-(db|full|upload)-[0-9A-Za-z-]+\.(zip|sql\.gz\.enc|sql\.enc)$/', $name) || ! is_file(self::dir()."/$name")) {
            throw new RuntimeException('There is no backup with that name.');
        }

        return self::dir()."/$name";
    }

    private function info(string $path): array
    {
        preg_match('/^trinix-admission-(db|full|upload)-/', basename($path), $m);

        return ['name' => basename($path), 'kind' => $m[1] ?? 'db', 'size' => (int) filesize($path),
            'created_at' => CarbonImmutable::createFromTimestamp(filemtime($path))->toIso8601String(), 'safety' => str_contains(basename($path), 'before-restore')];
    }

    /** Keeps the newest N database and N full backups; uploaded and safety backups count as database backups. */
    public function prune(): void
    {
        $s = $this->raw();
        foreach (['db' => (int) $s['keep'], 'full' => (int) $s['keep_full']] as $kind => $keep) {
            $mine = array_values(array_filter($this->list(), fn ($b) => ($kind === 'full') === ($b['kind'] === 'full')));
            foreach (array_slice($mine, max(1, $keep)) as $old) {
                @unlink(self::dir().'/'.$old['name']);
            }
        }
    }

    /** Stores a backup ZIP uploaded by the admin (e.g. the one from the email), after checking it opens. */
    public function upload(string $tmpPath, ?string $password, User $actor): array
    {
        $this->extractSql($tmpPath, $password, fn () => null);
        $head = (string) file_get_contents($tmpPath, false, null, 0, 8);
        $name = 'trinix-admission-upload-'.now(config('app.display_timezone', 'Asia/Kolkata'))->format('Ymd-His').($head === 'Salted__' ? '.sql.gz.enc' : '.zip'); // a sealed file may or may not be compressed inside; both open
        copy($tmpPath, self::dir()."/$name");
        $this->auditor->record('backup.uploaded', null, null, ['name' => $name], actorId: $actor->id);

        return $this->info(self::dir()."/$name");
    }

    // ----- Email ------------------------------------------------------------------------------------------------

    /** Emails a backup to the addresses in the settings: attached if small enough, otherwise a notice. */
    public function email(array $info, ?User $actor = null): int
    {
        $s = $this->raw();
        $to = array_values(array_filter((array) $s['emails']));
        if (! $to) {
            return 0;
        }
        $attach = $info['size'] <= ((int) $s['email_limit_mb']) * 1024 * 1024;
        Mail::to($to)->send(new BackupMail($info, $attach ? $this->path($info['name']) : null, (string) config('app.url')));
        $this->auditor->record('backup.emailed', null, null, ['name' => $info['name'], 'recipients' => count($to), 'attached' => $attach], actorId: $actor?->id);

        return count($to);
    }

    // ----- Schedule ---------------------------------------------------------------------------------------------

    /** Called by the cron every few minutes: makes the day's backup once the set time has passed. */
    public function runScheduled(): ?array
    {
        $s = $this->raw();
        if (! $s['enabled'] || empty($s['password'])) {
            return null;
        }
        $now = now(config('app.display_timezone', 'Asia/Kolkata'));
        if ($now->format('H:i') < $s['time'] || Cache::get('backup:scheduled_on') === $now->toDateString()) {
            return null;
        }
        if (! Cache::add('backup:running', 1, 3600)) {
            return null;
        }
        try {
            // A failed attempt is tried again at the next run (every 15 minutes), at most 3 times a day.
            $tries = (int) Cache::get('backup:tries:'.$now->toDateString(), 0);
            if ($tries >= 3) {
                return null;
            }
            Cache::put('backup:tries:'.$now->toDateString(), $tries + 1, 86400 * 2);
            $info = $this->create('db', null, 'schedule');
            Cache::forever('backup:scheduled_on', $now->toDateString());
            try {
                $this->email($info);
            } catch (Throwable $e) {
                $this->auditor->record('backup.email_failed', null, null, ['name' => $info['name'], 'error' => class_basename($e)]);
            }
            if ($s['full_day'] !== null && (int) $s['full_day'] === (int) $now->dayOfWeek) {
                $this->create('full', null, 'schedule');
            }

            return $info;
        } finally {
            Cache::forget('backup:running');
        }
    }

    // ----- Restore ----------------------------------------------------------------------------------------------

    /**
     * Restores a backup: checks it opens and fits this version, takes a safety backup, replaces every table
     * (except sessions, cache and queues) and, for a full backup, puts the documents back.
     *
     * @return array{safety: string, tables: int, documents: int}
     */
    public function restore(string $name, ?string $password, User $actor): array
    {
        @set_time_limit(0);
        $path = $this->path($name);
        $tables = 0;
        $docs = 0;
        $safety = null;

        $this->extractSql($path, $password, function (string $sql, array $header, ?ZipArchive $zip) use (&$tables, &$docs, &$safety, $actor, $name) {
            $safety = $this->create('db', $actor, 'safety')['name'];
            $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
            $conn = DB::connection();
            $conn->statement($mysql ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF');
            if (! $mysql) {
                $conn->statement('PRAGMA defer_foreign_keys=ON'); // foreign_keys=OFF has no effect inside a transaction
            }
            try {
                $this->replaceTables($conn, $mysql, $sql, $tables);
            } catch (Throwable $e) {
                throw new RuntimeException("The restore stopped ({$e->getMessage()}). The data from just before is in the safety backup $safety: restore that one.", 0, $e);
            }
            $conn->statement($mysql ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');

            $disk = Storage::disk('private');
            for ($i = 0; $zip && $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (str_starts_with($entry, 'documents/') && ! str_ends_with($entry, '/') && ! str_contains($entry, '..')) {
                    $disk->put(substr($entry, 10), (string) $zip->getFromIndex($i));
                    $docs++;
                }
            }
            Cache::flush();
            $this->auditor->record('backup.restored', null, null, ['name' => $name, 'safety_backup' => $safety, 'tables' => $tables, 'documents' => $docs, 'backup_made' => $header['created_at'] ?? null], actorId: $actor->id);
        });

        return ['safety' => $safety, 'tables' => $tables, 'documents' => $docs];
    }

    /** Drops every backed-up table, then runs the dump one line (one statement) at a time. */
    private function replaceTables(Connection $conn, bool $mysql, string $sql, int &$tables): void
    {
        foreach ($this->tables() as $t) {
            $conn->statement('DROP TABLE IF EXISTS '.($mysql ? "`$t`" : "\"$t\""));
        }
        $fh = fopen($sql, 'rb');
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                if (str_starts_with($line, 'CREATE TABLE')) {
                    $tables++;
                }
                $conn->unprepared(rtrim($line, ';'));
            }
        } finally {
            fclose($fh);
        }
    }

    /** Opens the ZIP, checks the SQL header against this installation, and hands the extracted SQL to $then. */
    private function extractSql(string $zipPath, ?string $password, callable $then): mixed
    {
        if ((string) file_get_contents($zipPath, false, null, 0, 8) === 'Salted__') {
            $tmp = $this->tempFile('rs');
            if (! self::unsealFile($zipPath, $tmp, $this->password($password))) {
                @unlink($tmp);
                throw new RuntimeException('The backup password is wrong, or the file is damaged.');
            }
            if ((string) file_get_contents($tmp, false, null, 0, 2) === "\x1f\x8b") {
                if (! self::canGzip()) {
                    @unlink($tmp);
                    throw new RuntimeException('This backup is compressed (.gz), and this server\'s PHP has no zlib to open it. Open it on a computer with the openssl command shown on this page and import database.sql with phpMyAdmin.');
                }
                $plain = $this->tempFile('rs');
                try {
                    self::gunzipFile($tmp, $plain);
                } finally {
                    @unlink($tmp);
                }
                $tmp = $plain;
            }
            try {
                return $then($tmp, $this->checkHeader($tmp), null);
            } finally {
                @unlink($tmp);
            }
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('This backup is a ZIP, and this server has no PHP zip extension to open it.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('This is not a backup file (it does not open as a ZIP).');
        }
        try {
            $zip->setPassword($this->password($password));
            $tmp = $this->tempFile('rs');
            if ($zip->locateName('database.sql') === false) {
                throw new RuntimeException('This ZIP is not a Trinix Admission backup (it has no database.sql).');
            }
            $in = $zip->getStream('database.sql');
            if (! $in) {
                throw new RuntimeException('The backup password is wrong.');
            }
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            try {
                $header = $this->checkHeader($tmp);
            } catch (RuntimeException $e) {
                @unlink($tmp);
                throw $e;
            }
            try {
                return $then($tmp, $header, $zip);
            } finally {
                @unlink($tmp);
            }
        } finally {
            $zip->close();
        }
    }

    /** Checks the dump's first line: a Trinix Admission backup, the same database kind, not from a newer version. */
    private function checkHeader(string $sqlPath): array
    {
        $first = (string) fgets($fh = fopen($sqlPath, 'rb'));
        fclose($fh);
        if (! str_starts_with($first, self::HEADER)) {
            throw new RuntimeException('The backup password is wrong, or this is not a Trinix Admission backup.');
        }
        $header = json_decode(substr($first, strlen(self::HEADER)), true) ?: [];
        $driver = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'mysql' : DB::connection()->getDriverName();
        if (($header['driver'] ?? null) !== $driver) {
            throw new RuntimeException("This backup is from a {$header['driver']} database; this installation uses $driver.");
        }
        $known = array_keys(app(Migrator::class)->getMigrationFiles([database_path('migrations')]));
        if (array_diff($header['migrations'] ?? [], $known)) {
            throw new RuntimeException('This backup is from a newer version of Trinix Admission. Update the app first, then restore.');
        }

        return $header;
    }

    /** Backups as OpenSSL-encrypted files (no ZIP) when the zip extension is missing, or when set for tests. */
    public static function sealed(): bool
    {
        return config('backups.format') === 'openssl' || ! class_exists(ZipArchive::class);
    }

    public static function canGzip(): bool
    {
        return config('backups.compress', true) && function_exists('gzopen'); // backups.compress: tests only
    }

    private static function gzipFile(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb6');
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }
        fclose($in);
        gzclose($out) ?: throw new RuntimeException('Could not write the backup file. Check the free disk space in cPanel.');
    }

    private static function gunzipFile(string $from, string $to): void
    {
        $in = gzopen($from, 'rb');
        $out = fopen($to, 'wb');
        while (! gzeof($in)) {
            $piece = gzread($in, 1048576);
            if ($piece === false) {
                throw new RuntimeException('The backup file is damaged (it does not unpack).');
            }
            fwrite($out, $piece);
        }
        gzclose($in);
        fclose($out);
    }

    /**
     * AES-256-CBC in OpenSSL's "Salted__" format with a PBKDF2-SHA256 key (100,000 rounds), so the file can also be
     * opened outside the app: openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in FILE | gunzip > database.sql
     * (without "| gunzip" for a .sql.enc file). Encrypted in 1 MB pieces, chaining the CBC state, so memory stays small.
     */
    public static function sealFile(string $from, string $to, string $password): void
    {
        $salt = random_bytes(8);
        $km = hash_pbkdf2('sha256', $password, $salt, 100000, 48, true);
        [$key, $iv] = [substr($km, 0, 32), substr($km, 32, 16)];
        $in = fopen($from, 'rb');
        $out = fopen($to, 'wb');
        if (! $in || ! $out || fwrite($out, 'Salted__'.$salt) !== 16) {
            throw new RuntimeException('Could not write the backup file. Check the free disk space in cPanel.');
        }
        $piece = (string) stream_get_contents($in, self::PIECE);
        while (true) {
            $next = (string) stream_get_contents($in, self::PIECE);
            $last = $next === '';
            $enc = openssl_encrypt($piece, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | ($last ? 0 : OPENSSL_ZERO_PADDING), $iv);
            if ($enc === false || fwrite($out, $enc) !== strlen($enc)) {
                throw new RuntimeException('Could not write the backup file. Check the free disk space in cPanel.');
            }
            if ($last) {
                break;
            }
            $iv = substr($enc, -16);
            $piece = $next;
        }
        fclose($in);
        fclose($out) ?: throw new RuntimeException('Could not write the backup file. Check the free disk space in cPanel.');
    }

    /** Decrypts a sealed file; false when the password is wrong or the file is damaged. */
    public static function unsealFile(string $from, string $to, string $password): bool
    {
        $in = fopen($from, 'rb');
        $head = (string) fread($in, 16);
        if (strlen($head) < 16 || ! str_starts_with($head, 'Salted__')) {
            fclose($in);

            return false;
        }
        $km = hash_pbkdf2('sha256', $password, substr($head, 8, 8), 100000, 48, true);
        [$key, $iv] = [substr($km, 0, 32), substr($km, 32, 16)];
        $out = fopen($to, 'wb');
        $piece = (string) stream_get_contents($in, self::PIECE);
        $ok = $piece !== '';
        while ($ok) {
            $next = (string) stream_get_contents($in, self::PIECE);
            $last = $next === '';
            $dec = strlen($piece) % 16 === 0 ? openssl_decrypt($piece, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | ($last ? 0 : OPENSSL_ZERO_PADDING), $iv) : false;
            if ($dec === false) {
                $ok = false;
                break;
            }
            fwrite($out, $dec);
            if ($last) {
                break;
            }
            $iv = substr($piece, -16);
            $piece = $next;
        }
        fclose($in);
        fclose($out);

        return $ok;
    }

    /** The SQL inside a sealed backup held in memory, or null when the password is wrong or the file is damaged. */
    public static function unseal(string $file, string $password): ?string
    {
        $src = tempnam(sys_get_temp_dir(), 'us');
        $dst = tempnam(sys_get_temp_dir(), 'us');
        try {
            file_put_contents($src, $file);
            if (! self::unsealFile($src, $dst, $password)) {
                return null;
            }
            $data = (string) file_get_contents($dst);
            if (str_starts_with($data, "\x1f\x8b")) {
                $data = self::canGzip() ? @gzdecode($data) : false;
            }

            return $data === false ? null : $data;
        } finally {
            @unlink($src);
            @unlink($dst);
        }
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    public static function randomPassword(): string
    {
        return Str::password(16, symbols: false);
    }
}
