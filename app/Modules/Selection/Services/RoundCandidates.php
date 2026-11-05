<?php

namespace App\Modules\Selection\Services;

use App\Modules\Applicants\Models\Application;
use Illuminate\Support\Facades\DB;

/**
 * Who takes part in a round's GD/PI. In Trinix Admission the institute shortlists before importing, so every
 * applicant imported into the round is a candidate (there are no call lists).
 */
class RoundCandidates
{
    public static function isIn(Application $app, int $roundId): bool
    {
        return DB::table('application_round_participations')->where(['application_id' => $app->id, 'admission_round_id' => $roundId])->exists();
    }

    /** Limit an applications query to applicants imported into the round. */
    public static function where($query, int $roundId): void
    {
        $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('application_round_participations as rp')
            ->whereColumn('rp.application_id', 'applications.id')->where('rp.admission_round_id', $roundId));
    }
}
