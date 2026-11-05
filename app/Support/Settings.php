<?php

namespace App\Support;

use App\Modules\System\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Reads system settings (cached briefly), falling back to config/access.php defaults. */
class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $fallback = config("access.settings.$key.0", $default);

        try {
            $values = Cache::remember('system_settings', 60, fn () => Schema::hasTable('system_settings')
                ? SystemSetting::pluck('value', 'key')->all()
                : []);
        } catch (\Throwable) {
            return $fallback;
        }

        return $values[$key] ?? $fallback;
    }

    public static function forget(): void
    {
        Cache::forget('system_settings');
    }
}
