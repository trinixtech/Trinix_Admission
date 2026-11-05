<?php

namespace App\Modules\Applicants\Models;

use App\Modules\Cycles\Models\AdmissionRound;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationRoundParticipation extends Model
{
    protected $table = 'application_round_participations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['added_at' => 'datetime'];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class, 'admission_round_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
