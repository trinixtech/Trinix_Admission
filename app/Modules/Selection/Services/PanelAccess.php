<?php

namespace App\Modules\Selection\Services;

use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use App\Modules\Selection\Models\InterviewCandidate;
use App\Modules\Selection\Models\InterviewPanel;
use App\Modules\Selection\Models\InterviewPanelMember;
use App\Modules\Selection\Models\PanelConflict;

/** Panel scope: panel members reach only candidates on panels they currently sit on (decision D24). */
class PanelAccess
{
    public static function isMember(User $user, InterviewPanel $panel, ?string $role = null): bool
    {
        return $panel->members()->where('user_id', $user->id)->whereNull('removed_at')->when($role, fn ($q) => $q->where('role', $role))->exists();
    }

    public static function hasConflict(User $user, InterviewCandidate $candidate): bool
    {
        return PanelConflict::where(['user_id' => $user->id, 'application_id' => $candidate->application_id,
            'interview_panel_id' => $candidate->slot->interview_panel_id])->exists();
    }

    /** Is the user currently on any panel? Such users get 404 (not 403) for applications outside their panels. */
    public static function onAnyPanel(User $user): bool
    {
        return InterviewPanelMember::where('user_id', $user->id)->whereNull('removed_at')->exists();
    }

    /** May this panel member open the application (and its documents)? */
    public static function canSeeApplication(User $user, Application $app): bool
    {
        return InterviewCandidate::query()
            ->join('interview_slots as s', 's.id', '=', 'interview_candidates.interview_slot_id')
            ->join('interview_panel_members as m', 'm.interview_panel_id', '=', 's.interview_panel_id')
            ->where('interview_candidates.application_id', $app->id)->where('interview_candidates.active', true)
            ->where('m.user_id', $user->id)->whereNull('m.removed_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('panel_conflicts as pc')
                ->whereColumn('pc.interview_panel_id', 's.interview_panel_id')->where('pc.user_id', $user->id)->where('pc.application_id', $app->id))
            ->exists();
    }
}
