<?php

namespace App\Modules\Teams\Models;

use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Cycles\Models\CourseOffering;
use App\Modules\Organisation\Models\Branch;
use App\Support\HasUid;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Admission committee, admission cell or accounts team. */
class Team extends Model
{
    use HasUid;

    public const TYPES = ['admission_committee', 'admission_cell', 'accounts_team'];

    protected $fillable = [
        'type', 'name', 'branch_id', 'course_offering_id', 'admission_cycle_id',
        'valid_from', 'valid_to', 'approval_powers', 'status',
    ];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function currentMembers(): HasMany
    {
        return $this->members()->whereNull('removed_at');
    }

    public function scope(): Scope
    {
        $offering = $this->offering?->loadMissing('branchCourse');

        return new Scope(
            $this->branch_id ?? $offering?->branchCourse->branch_id,
            $offering?->branchCourse->course_id,
            $this->admission_cycle_id ?? $offering?->admission_cycle_id,
        );
    }
}
