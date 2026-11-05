<?php

namespace App\Modules\Organisation\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasUid;

    protected $fillable = ['institution_id', 'code', 'name', 'address', 'city', 'state', 'pincode', 'status'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function isReferenced(): bool
    {
        return $this->branches()->exists();
    }
}
