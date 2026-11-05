<?php

namespace App\Modules\Imports\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/** A portal or channel that applicant data comes from. API push tokens belong to a source. */
class ExternalSource extends Model
{
    use HasApiTokens, HasUid;

    protected $fillable = ['code', 'name', 'status', 'description'];

    public function templates(): HasMany
    {
        return $this->hasMany(ImportMappingTemplate::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
