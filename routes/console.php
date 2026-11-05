<?php

use App\Modules\System\Services\HealthCheck;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled tasks. They run inside the cron's own PHP process (Artisan::call), not as separate processes,
 * because shared hosting often disables proc_open. Each runs on one server only, even with several app servers.
 */
$task = fn (string $name, string $command, array $args = []) => Schedule::call(fn () => Artisan::call($command, $args))
    ->name($name)->onOneServer()->withoutOverlapping(10);

// Heartbeat for the System Health screen.
Schedule::call(fn () => Cache::forever(HealthCheck::SCHEDULER_KEY, now()->toIso8601String()))
    ->name('scheduler-heartbeat')->everyMinute()->onOneServer()->withoutOverlapping();

// Horizon (queue dashboard) only with Redis.
if (config('queue.default') === 'redis') {
    $task('horizon-snapshot', 'horizon:snapshot')->everyFiveMinutes();
}

$task('clear-password-resets', 'auth:clear-resets')->daily();

// Documents that are still unscanned (e.g. the scanner was down) are queued again.
$task('documents-scan-pending', 'documents:scan-pending')->everyFiveMinutes();

// Interview reminders the day before, at 09:00 Indian time.
$task('interview-reminders', 'interviews:send-reminders')->dailyAt('03:30');

// Offer and waitlist-hold reminders, at 09:30 Indian time.
$task('offer-reminders', 'offers:send-reminders')->dailyAt('04:00');
$task('fee-reminders', 'fees:send-reminders')->dailyAt('04:10');

// Shared hosting (no SSH, no long-running worker): the every-minute cron also works the queue,
// processing imports, document scans and emails for up to 50 seconds per minute.
if (config('queue.run_from_scheduler')) {
    $task('queue-from-scheduler', 'queue:work', ['--queue' => config('import.queue').',default', '--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3])
        ->everyMinute();
}
