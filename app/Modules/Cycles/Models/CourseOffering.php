<?php

namespace App\Modules\Cycles\Models;

use App\Modules\Organisation\Models\BranchCourse;
use App\Support\HasUid;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A branch-course offered in an admission cycle, with its sanctioned intake. */
class CourseOffering extends Model
{
    use HasUid;

    public const STATUSES = ['draft', 'active', 'closed'];

    protected $fillable = ['admission_cycle_id', 'branch_course_id', 'sanctioned_intake', 'status'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function branchCourse(): BelongsTo
    {
        return $this->belongsTo(BranchCourse::class);
    }

    public function scope(): Scope
    {
        $bc = $this->branchCourse;

        return new Scope($bc->branch_id, $bc->course_id, $this->admission_cycle_id);
    }
}
