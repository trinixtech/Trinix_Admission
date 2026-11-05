<?php

namespace App\Modules\Cycles\Models;

use App\Modules\Access\Models\User;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionRound extends Model
{
    use HasUid;

    public const INITIAL = 'initial';

    public const FRESH = 'fresh';

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    protected $fillable = ['admission_cycle_id', 'number', 'type', 'name', 'status', 'closed_at', 'closed_by', 'close_reason'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class, 'admission_cycle_id');
    }

    public function roundOfferings(): HasMany
    {
        return $this->hasMany(AdmissionRoundOffering::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }
}
