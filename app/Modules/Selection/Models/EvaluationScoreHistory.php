<?php

namespace App\Modules\Selection\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationScoreHistory extends Model
{
    public $timestamps = false;

    protected $table = 'evaluation_score_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'occurred_at' => 'datetime'];
    }
}
