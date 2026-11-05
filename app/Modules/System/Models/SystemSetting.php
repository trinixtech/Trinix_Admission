<?php

namespace App\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'description', 'is_technical', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array', 'is_technical' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
