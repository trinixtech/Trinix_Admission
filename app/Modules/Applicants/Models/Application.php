<?php

namespace App\Modules\Applicants\Models;

use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Documents\Models\ApplicationDocument;
use App\Modules\Imports\Models\ExternalSource;
use App\Modules\Organisation\Models\Course;
use App\Support\HasUid;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** An application for one course in one cycle. The campus is decided at allocation (decision D21). */
class Application extends Model
{
    use HasUid;

    public const FIELDS = ['category', 'pwbd', 'pwbd_type', 'work_experience_months'];

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['pwbd' => 'boolean', 'extra' => 'array', 'interview_centres' => 'array'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class, 'external_source_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function entrance(): HasOne
    {
        return $this->hasOne(ApplicationEntranceDetail::class);
    }

    public function academics(): HasMany
    {
        return $this->hasMany(ApplicationAcademic::class);
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(ApplicationPreference::class)->orderBy('rank');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ApplicationRoundParticipation::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderBy('occurred_at');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ApplicationCorrection::class)->orderBy('occurred_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function scope(): Scope
    {
        return new Scope(null, $this->course_id, $this->admission_cycle_id);
    }
}
