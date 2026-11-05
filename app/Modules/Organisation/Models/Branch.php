<?php

namespace App\Modules\Organisation\Models;

use App\Modules\Access\Models\UserRoleAssignment;
use App\Modules\Teams\Models\Team;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasUid;

    protected $fillable = ['institution_id', 'code', 'name', 'location_id', 'email', 'phone', 'status'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function branchCourses(): HasMany
    {
        return $this->hasMany(BranchCourse::class);
    }

    public function isReferenced(): bool
    {
        return $this->branchCourses()->exists()
            || UserRoleAssignment::where('branch_id', $this->id)->exists()
            || Team::where('branch_id', $this->id)->exists();
    }
}
