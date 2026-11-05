<?php

namespace App\Modules\Selection\Models;

use App\Modules\Cycles\Models\AdmissionRound;
use App\Modules\Organisation\Models\Location;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterviewPanel extends Model
{
    use HasUid;

    protected $table = 'interview_panels';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['panel_date' => 'date:Y-m-d'];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(SelectionStage::class, 'selection_stage_id');
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class, 'admission_round_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(InterviewPanelMember::class, 'interview_panel_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(InterviewSlot::class, 'interview_panel_id');
    }
}
