<?php

namespace App\Modules\Selection\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterviewSlot extends Model
{
    use HasUid;

    protected $table = 'interview_slots';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(InterviewPanel::class, 'interview_panel_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(InterviewCandidate::class, 'interview_slot_id');
    }
}
