<?php

namespace App\Modules\Documents\Models;

use App\Modules\Access\Models\User;
use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentVersion extends Model
{
    use HasUid;

    protected $table = 'document_versions';

    protected $guarded = ['id', 'uid'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'application_document_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(DocumentVerification::class, 'document_version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
