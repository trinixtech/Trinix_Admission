<?php

namespace App\Modules\Imports\Models;

use App\Modules\Access\Models\User;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Course;
use App\Support\HasUid;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasUid;

    public const UPLOADED = 'uploaded';

    public const MAPPED = 'mapped';

    public const STAGING = 'staging';

    public const VALIDATED = 'validated';

    public const APPROVED = 'approved';

    public const WRITING = 'writing';

    public const COMPLETED = 'completed';

    public const PARTIAL = 'partially_completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Statuses in which nothing may change any more. */
    public const FINAL = [self::COMPLETED, self::CANCELLED];

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'column_map' => 'array',
            'value_map' => 'array',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class, 'external_source_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class, 'admission_round_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ImportRecord::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    public function scope(): Scope
    {
        return new Scope(null, $this->course_id, $this->round->admission_cycle_id);
    }

    /** ZIP or document-link batches (Phase 3) rather than applicant data. */
    public function isDocuments(): bool
    {
        return in_array($this->channel, ['zip', 'urls'], true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    /** Rows in the file must equal the sum of what happened to them. */
    public function reconciles(): bool
    {
        return $this->rows_total === $this->rows_written + $this->rows_excluded + $this->rows_error + $this->rows_duplicate;
    }
}
