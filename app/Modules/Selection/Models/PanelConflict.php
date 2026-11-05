<?php

namespace App\Modules\Selection\Models;

use Illuminate\Database\Eloquent\Model;

class PanelConflict extends Model
{
    protected $table = 'panel_conflicts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['declared_at' => 'datetime'];
    }
}
