<?php

namespace App\Modules\Selection\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewEvaluationScore extends Model
{
    protected $table = 'interview_evaluation_scores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}
