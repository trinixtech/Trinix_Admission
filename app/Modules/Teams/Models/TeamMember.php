<?php

namespace App\Modules\Teams\Models;

use App\Modules\Access\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    public const ROLES = ['chair', 'member', 'officer', 'staff'];

    protected $fillable = ['team_id', 'user_id', 'member_role', 'valid_from', 'valid_to', 'added_by', 'removed_at', 'removed_by'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'removed_at' => 'datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
