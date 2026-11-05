<?php

namespace App\Modules\Selection\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationCriterion extends Model
{
    protected $table = 'evaluation_criteria';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['max_marks' => 'decimal:2'];
    }
}
