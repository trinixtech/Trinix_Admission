<?php

namespace App\Modules\Applicants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationEntranceDetail extends Model
{
    protected $table = 'application_entrance_details';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sectional' => 'array'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
