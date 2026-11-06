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

        return Crypt::decryptString($enc);
    }

    // ----- Making backups ---------------------------------------------------------------------------------------

    /**
     * @param  'db'|'full'  $kind
     * @return array{name: string, kind: string, size: int, created_at: string}
     */
    public function create(string $kind = 'db', ?User $actor = null, string $trigger = 'manual'): array
    {
        @set_time_limit(0);
        $password = $this->password();
        $stamp = now(config('app.display_timezone', 'Asia/Kolkata'))->format('Ymd-His');
        $name = "trinix-admission-$kind-$stamp".($trigger === 'safety' ? '-before-restore' : '').'.zip';
        $path = self::dir()."/$name";
        $sql = tempnam(sys_get_temp_dir(), 'bk');

        try {
            $fh = fopen($sql, 'wb');
            $this->dump($fh);
            fclose($fh);

            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the backup file.');
            }
            $zip->setPassword($password);
            $zip->addFile($sql, 'database.sql');
            $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256);
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
                throw new RuntimeException('Could not write the backup file.');
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
        $files = glob(self::dir().'/trinix-admission-*.zip') ?: [];
        $out = array_map(fn ($f) => $this->info($f), $files);
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    public function path(string $name): string
    {
        if (! preg_match('/^trinix-admission-(db|full|upload)-[0-9A-Za-z-]+\.zip$/', $name) || ! is_file(self::dir()."/$name")) {
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
        $name = 'trinix-admission-upload-'.now(config('app.display_timezone', 'Asia/Kolkata'))->format('Ymd-His').'.zip';
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
            Cache::forever('backup:scheduled_on', $now->toDateString());
            $info = $this->create('db', null, 'schedule');
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

        $this->extractSql($path, $password, function (string $sql, array $header, ZipArchive $zip) use (&$tables, &$docs, &$safety, $actor, $name) {
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
            for ($i = 0; $i < $zip->numFiles; $i++) {
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
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('This is not a backup file (it does not open as a ZIP).');
        }
        try {
            $zip->setPassword($this->password($password));
            $tmp = tempnam(sys_get_temp_dir(), 'rs');
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
            $first = (string) fgets($fh = fopen($tmp, 'rb'));
            fclose($fh);
            if (! str_starts_with($first, self::HEADER)) {
                @unlink($tmp);
                throw new RuntimeException('The backup password is wrong, or this is not a Trinix Admission backup.');
            }
            $header = json_decode(substr($first, strlen(self::HEADER)), true) ?: [];
            $driver = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'mysql' : DB::connection()->getDriverName();
            if (($header['driver'] ?? null) !== $driver) {
                @unlink($tmp);
                throw new RuntimeException("This backup is from a {$header['driver']} database; this installation uses $driver.");
            }
            $known = array_keys(app(Migrator::class)->getMigrationFiles([database_path('migrations')]));
            if (array_diff($header['migrations'] ?? [], $known)) {
                @unlink($tmp);
                throw new RuntimeException('This backup is from a newer version of Trinix Admission. Update the app first, then restore.');
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

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    public static function randomPassword(): string
    {
        return Str::password(16, symbols: false);
    }
}
