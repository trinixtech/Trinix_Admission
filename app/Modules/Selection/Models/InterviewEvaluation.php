<?php

namespace App\Modules\Selection\Models;

use App\Modules\Access\Models\User;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterviewEvaluation extends Model
{
    use HasUid;

    protected $table = 'interview_evaluations';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function scores(): HasMany
    {
        return $this->hasMany(InterviewEvaluationScore::class, 'interview_evaluation_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(InterviewCandidate::class, 'interview_candidate_id');
    }
}
