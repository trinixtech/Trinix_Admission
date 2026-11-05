<?php

namespace App\Modules\Selection\Models;

use App\Modules\Applicants\Models\Application;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterviewCandidate extends Model
{
    use HasUid;

    protected $table = 'interview_candidates';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'reminded_at' => 'datetime', 'result_locked_at' => 'datetime'];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(InterviewSlot::class, 'interview_slot_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SelectionStage::class, 'selection_stage_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(InterviewEvaluation::class, 'interview_candidate_id');
    }
}
