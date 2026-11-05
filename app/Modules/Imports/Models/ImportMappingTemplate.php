<?php

namespace App\Modules\Imports\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMappingTemplate extends Model
{
    use HasUid;

    protected $fillable = ['external_source_id', 'name', 'column_map', 'value_map', 'created_by'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'value_map' => 'array'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class, 'external_source_id');
    }
}
