<?php

namespace App\Modules\Documents\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasUid;

    protected $table = 'document_types';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['allowed_types' => 'array'];
    }
}
