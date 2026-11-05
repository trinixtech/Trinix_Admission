<?php

namespace App\Modules\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['key', 'name', 'description', 'is_system', 'requires_two_factor'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'requires_two_factor' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissions->pluck('key')->all();
    }
}
