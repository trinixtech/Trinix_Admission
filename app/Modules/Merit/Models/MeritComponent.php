<?php

namespace App\Modules\Merit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeritComponent extends Model
{
    protected $table = 'merit_components';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['params' => 'array', 'minimum' => 'array', 'weight' => 'decimal:4', 'cap' => 'decimal:4'];
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(MeritFormula::class, 'merit_formula_id');
    }
}
