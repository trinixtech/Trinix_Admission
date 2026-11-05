<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\System\Models\SystemSetting;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        $all = $this->access()->can($this->actor(), 'settings.manage');
        if (! $all) {
            $this->access()->authorize($this->actor(), 'settings.technical');
        }

        return response()->json(['data' => SystemSetting::orderBy('key')->get()->map(fn (SystemSetting $s) => [
            'key' => $s->key,
            'value' => $s->value,
            'description' => $s->description,
            'is_technical' => $s->is_technical,
            'editable' => $all || $s->is_technical,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ])]);
    }

    public function update(Request $request, SystemSetting $setting): JsonResponse
    {
        $actor = $this->actor();
        $allowed = $this->access()->can($actor, 'settings.manage')
            || ($setting->is_technical && $this->access()->can($actor, 'settings.technical'));
        if (! $allowed) {
            throw new AuthorizationException('You cannot change this setting.');
        }

        $default = config("access.settings.{$setting->key}.0");
        if (is_array($default)) {
            $data = $request->validate(['value' => ['present', 'array', 'max:50'], 'value.*' => ['string', 'max:253', 'regex:/^(\*\.)?[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/i']],
                ['value.*.regex' => 'Use host names such as portal.example.org or *.example.org.']);
            DB::transaction(fn () => $this->audit()->update('setting.updated', $setting, ['value' => array_values(array_unique(array_map('strtolower', $data['value']))), 'updated_by' => $actor->id]));
            Settings::forget();

            return $this->index();
        }
        $rules = match (true) {
            is_int($default) => ['required', 'integer', 'min:1', 'max:100000'],
            $setting->key === 'support_email' => ['nullable', 'email'],
            default => ['nullable', 'string', 'max:1000'],
        };
        $data = $request->validate(['value' => $rules]);
        $value = is_int($default) ? (int) $data['value'] : $data['value'];

        DB::transaction(fn () => $this->audit()->update('setting.updated', $setting, ['value' => $value, 'updated_by' => $actor->id]));
        Settings::forget();

        return $this->index();
    }
}
