<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;

class FileAccessLog extends Model
{
    public $timestamps = false;

    protected $table = 'file_access_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
