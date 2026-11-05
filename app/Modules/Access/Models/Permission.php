<?php

namespace App\Modules\Access\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['key', 'module', 'description'];
}
