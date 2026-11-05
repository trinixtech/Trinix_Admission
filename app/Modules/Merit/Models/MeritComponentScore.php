<?php

namespace App\Modules\Merit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeritComponentScore extends Model
{
    protected $table = 'merit_component_scores';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['raw_value' => 'decimal:4', 'points' => 'decimal:4'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(MeritComponent::class, 'merit_component_id');
    }
}
