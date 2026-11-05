<?php

namespace App\Modules\Merit\Models;

use App\Modules\Access\Models\User;
use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Organisation\Models\Course;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeritFormula extends Model
{
    use HasUid;

    protected $table = 'merit_formulas';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['rounding' => 'array', 'tie_breaks' => 'array', 'inclusion' => 'array', 'approved_at' => 'datetime'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(MeritComponent::class, 'merit_formula_id')->orderBy('sort_order')->orderBy('id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
