<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Public identifier (ULID) used in URLs and the API, so internal ids are never exposed.
 */
trait HasUid
{
    public static function bootHasUid(): void
    {
        static::creating(function ($model) {
            $model->uid ??= strtolower((string) Str::ulid());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }
}
