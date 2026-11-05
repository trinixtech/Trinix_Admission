<?php

namespace App\Modules\Merit\Models;

use App\Modules\Access\Models\User;
use App\Modules\Applicants\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeritListEntry extends Model
{
    protected $table = 'merit_list_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ranked' => 'boolean', 'tie_shared' => 'boolean', 'manual' => 'boolean', 'inputs' => 'array', 'total' => 'decimal:4'];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(MeritList::class, 'merit_list_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(MeritComponentScore::class, 'merit_list_entry_id');
    }

    public function manualBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_by');
    }
}
