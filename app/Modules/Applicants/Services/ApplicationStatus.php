<?php

namespace App\Modules\Applicants\Services;

use App\Modules\Applicants\Models\Application;
use App\Modules\Applicants\Models\ApplicationStatusHistory;
use App\Modules\Audit\Services\Auditor;

/** Moves an application's status, keeping the history and the audit log. */
class ApplicationStatus
{
    public const LABELS = [
        'imported' => 'Imported', 'under_scrutiny' => 'Under scrutiny', 'deficient' => 'Deficient',
        'scrutiny_complete' => 'Scrutiny complete', 'eligible' => 'Eligible', 'provisionally_eligible' => 'Provisionally eligible',
        'not_eligible' => 'Not eligible',
    ];

    public static function move(Application $app, string $to, ?string $reason, ?int $actorId): void
    {
        $from = $app->status;
        if ($from === $to) {
            return;
        }
        $app->forceFill(['status' => $to])->save();
        ApplicationStatusHistory::create([
            'application_id' => $app->id, 'from_status' => $from, 'to_status' => $to,
            'reason' => $reason, 'actor_id' => $actorId, 'occurred_at' => now(),
        ]);
        app(Auditor::class)->record('application.status_changed', $app, ['status' => $from], ['status' => $to], $reason, $app->scope(), $actorId);
    }
}
