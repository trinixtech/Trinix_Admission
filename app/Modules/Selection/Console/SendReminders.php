<?php

namespace App\Modules\Selection\Console;

use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Services\Notifier;
use App\Modules\Selection\Services\PanelService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Reminder email the day before (decision 7), once per booking; a reschedule allows a new reminder. */
class SendReminders extends Command
{
    protected $signature = 'interviews:send-reminders';

    protected $description = 'Email applicants whose interview is tomorrow (Indian time)';

    public function handle(Notifier $notifier, PanelService $panels): int
    {
        $tz = config('app.display_timezone');
        $from = CarbonImmutable::now($tz)->addDay()->startOfDay()->utc();
        $to = $from->addDay();
        $n = 0;
        InterviewCandidate::with('slot.panel.stage', 'slot.panel.location', 'application')
            ->where('active', true)->where('status', 'scheduled')->whereNull('reminded_at')
            ->whereHas('slot', fn ($q) => $q->where('starts_at', '>=', $from)->where('starts_at', '<', $to))
            ->each(function (InterviewCandidate $c) use ($notifier, $panels, &$n) {
                $notifier->send('interview_reminder', $c->application, $panels->vars($c->slot->panel, $c->slot));
                $c->forceFill(['reminded_at' => now()])->save();
                $n++;
            });
        $this->info("Sent $n reminders.");

        return self::SUCCESS;
    }
}
