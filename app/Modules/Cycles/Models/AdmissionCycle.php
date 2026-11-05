<?php

namespace App\Modules\Cycles\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionCycle extends Model
{
    use HasUid;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = ['academic_year_id', 'code', 'name', 'status'];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(AdmissionRound::class);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }
}
