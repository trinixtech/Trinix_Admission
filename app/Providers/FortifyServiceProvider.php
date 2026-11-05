<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Modules\Access\Services\LoginGate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::authenticateUsing(fn (Request $request) => app(LoginGate::class)->attempt($request));

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        // Portal pushes are counted per source, not per user.
        RateLimiter::for('source-push', fn (Request $request) => Limit::perMinute(60)->by('source:'.($request->attributes->get('source')?->id ?? $request->ip())));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by($request->user()?->id ?: $request->ip()));
    }
}
