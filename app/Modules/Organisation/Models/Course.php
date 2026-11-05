<?php

namespace App\Modules\Organisation\Models;

use App\Modules\Access\Models\UserRoleAssignment;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasUid;

    public const LEVELS = ['UG', 'PG', 'Integrated'];

    protected $fillable = ['institution_id', 'code', 'name', 'short_name', 'level', 'duration_months', 'entrance_exam', 'status'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function branchCourses(): HasMany
    {
        return $this->hasMany(BranchCourse::class);
    }

    public function isReferenced(): bool
    {
        return $this->branchCourses()->exists()
            || UserRoleAssignment::where('course_id', $this->id)->exists();
    }
}
