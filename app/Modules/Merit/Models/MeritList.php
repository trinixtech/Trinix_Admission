<?php

namespace App\Modules\Merit\Models;

use App\Modules\Access\Models\User;
use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Course;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeritList extends Model
{
    use HasUid;

    protected $table = 'merit_lists';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'run_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime', 'stale_since' => 'datetime'];
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(MeritFormula::class, 'merit_formula_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class, 'admission_round_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MeritListEntry::class, 'merit_list_id');
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
