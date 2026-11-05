<?php

namespace App\Modules\Access\Models;

use App\Modules\Cycles\Models\AdmissionCycle;
use App\Modules\Organisation\Models\Branch;
use App\Modules\Organisation\Models\Course;
use App\Support\HasUid;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One role held by a user, limited to a scope (branch, course, cycle; null = all). */
class UserRoleAssignment extends Model
{
    use HasUid;

    protected $fillable = [
        'user_id', 'role_id', 'branch_id', 'course_id', 'admission_cycle_id',
        'valid_from', 'valid_to', 'assigned_by', 'revoked_at', 'revoked_by', 'revoke_reason',
    ];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'revoked_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereNull('revoked_at')
            ->whereDate('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function scope(): Scope
    {
        return new Scope($this->branch_id, $this->course_id, $this->admission_cycle_id);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
