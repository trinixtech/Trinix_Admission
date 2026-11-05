<?php

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One staged row. Never read by the rest of the app until written. */
class ImportRecord extends Model
{
    public const NEW = 'new';

    public const UPDATE = 'update';

    public const UNCHANGED = 'unchanged';

    public const DUPLICATE = 'duplicate';

    public const ERROR = 'error';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw' => 'array', 'mapped' => 'array', 'diff' => 'array', 'excluded' => 'boolean', 'written_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
