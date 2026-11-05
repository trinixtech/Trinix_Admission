<?php

namespace App\Modules\Organisation\Models;

use App\Support\HasUid;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    use HasUid;

    protected $fillable = ['code', 'name', 'short_name', 'address', 'email', 'phone', 'website'];
}
