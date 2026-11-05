<?php

namespace App\Modules\Selection\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SelectionStage extends Model
{
    use HasUid;

    protected $table = 'selection_stages';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['remark_rule' => 'array', 'show_previous_marks' => 'boolean', 'slot_order' => 'array'];
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(EvaluationCriterion::class, 'selection_stage_id');
    }
}
