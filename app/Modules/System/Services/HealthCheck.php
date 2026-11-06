<?php

namespace App\Modules\System\Services;

use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentScanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Checks the parts every app server depends on. Used by /up (load balancer) and the System Health screen. */
class HealthCheck
{
    public const SCHEDULER_KEY = 'health:scheduler_last_run';

    /** @return array<string, array{ok: bool, detail: string}> */
    public function essentials(): array
    {
        return [
            'database' => $this->check(function () {
                DB::select('select 1');

                return DB::connection()->getDriverName();
            }),
            'redis' => $this->usesRedis()
                ? $this->check(function () {
                    Redis::connection()->ping();

                    return 'ping ok';
                })
                : ['ok' => true, 'detail' => 'not used by this configuration'],
            'storage' => $this->check(function () {
                $disk = Storage::disk('private');
                $path = 'health/'.Str::random(12).'.txt';
                $disk->put($path, 'ok');
                $ok = $disk->get($path) === 'ok';
                $disk->delete($path);
                if (! $ok) {
                    throw new \RuntimeException('read back failed');
                }

                return config('filesystems.disks.private.driver');
            }),
        ];
    }

    public function details(): array
    {
        $checks = $this->essentials();
        $lastRun = Cache::get(self::SCHEDULER_KEY);
        $checks['scheduler'] = [
            'ok' => $lastRun !== null && now()->diffInMinutes($lastRun, true) <= 5,
            'detail' => $lastRun ? 'last run '.$lastRun : 'has not run yet',
        ];
        $checks['queue'] = $this->check(fn () => 'default queue backlog: '.Queue::size().', imports: '.Queue::size(config('import.queue')));
        $scanner = app(DocumentScanner::class);
        $pending = DocumentVersion::where('scan_status', 'pending')->count();
        $checks['virus_scanner'] = config('documents.scanner') === 'eicar'
            ? ['ok' => ! app()->isProduction(), 'detail' => 'Test scanner only (EICAR); set DOCUMENT_SCANNER=clamav. Pending scans: '.$pending]
            : $this->check(function () use ($scanner, $pending) {
                $probe = tempnam(sys_get_temp_dir(), 'probe');
                file_put_contents($probe, 'health check');
                $r = $scanner->scan($probe);
                @unlink($probe);
                if ($r['status'] === 'error') {
                    throw new \RuntimeException('unreachable');
                }

                return $scanner->name().' reachable. Pending scans: '.$pending;
            });

        $missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'openssl', 'intl', 'zip', 'fileinfo', 'dom', 'xml', 'xmlreader', 'curl'],
            fn ($e) => ! extension_loaded($e) && ! ($e === 'pdo_mysql' && config('database.default') !== 'mysql')));
        $checks['php_extensions'] = ['ok' => ! $missing, 'detail' => $missing
            ? 'Missing: '.implode(', ', $missing).' (cPanel → Select PHP Version → Extensions, for PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.').'
            : 'All needed extensions are enabled (PHP '.PHP_VERSION.').'];

        $backups = app(BackupService::class);
        $latest = $backups->list()[0] ?? null;
        $settings = $backups->settings();
        $age = $latest ? now()->diffInHours($latest['created_at'], true) : null;
        $checks['backups'] = [
            'ok' => $settings['enabled'] && $latest !== null && $age <= 26,
            'detail' => ! $settings['enabled'] ? 'Daily backups are off (System → Backups).'
                : ($latest ? 'Last backup '.$latest['created_at'].' ('.BackupService::humanSize($latest['size']).'), '.count($backups->list()).' kept.' : 'No backup made yet.'),
        ];

        return [
            'ok' => collect($checks)->every(fn ($c) => $c['ok']),
            'checks' => $checks,
            'app' => [
                'environment' => app()->environment(),
                'version' => config('app.version'),
                'server' => gethostname(),
                'time' => now()->toIso8601String(),
            ],
        ];
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [
            config('cache.default'), config('session.driver'), config('queue.default'),
        ], true);
    }

    private function check(callable $fn): array
    {
        try {
            return ['ok' => true, 'detail' => (string) $fn()];
        } catch (Throwable $e) {
            // Shown only to signed-in IT staff (System → Health); /up exposes only true/false.
            return ['ok' => false, 'detail' => class_basename($e).': '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 220)];
        }
    }
}
