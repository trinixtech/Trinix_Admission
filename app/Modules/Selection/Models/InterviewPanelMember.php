<?php

namespace App\Modules\Selection\Models;

use App\Modules\Access\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewPanelMember extends Model
{
    protected $table = 'interview_panel_members';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(InterviewPanel::class, 'interview_panel_id');
    }
}
