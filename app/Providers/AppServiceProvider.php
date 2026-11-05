<?php

namespace App\Providers;

use App\Modules\Access\Console\SyncAccessCommand;
use App\Modules\Access\Listeners\AuditAuthEvents;
use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use App\Modules\Documents\Console\ScanPending;
use App\Modules\Documents\Services\ClamAvScanner;
use App\Modules\Documents\Services\DocumentScanner;
use App\Modules\Documents\Services\EicarScanner;
use App\Modules\Imports\Console\Benchmark;
use App\Modules\Imports\Console\MakeSampleFile;
use App\Modules\Selection\Console\SendReminders;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request (and reset between queued jobs by Laravel's scoped bindings).
        $this->app->scoped(AccessControl::class);
        $this->app->bind(DocumentScanner::class, fn () => config('documents.scanner') === 'eicar'
            ? new EicarScanner
            : new ClamAvScanner);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(12)->max(128);

            // Checked against known-breached passwords (k-anonymity API) outside local and test runs.
            return $this->app->environment('local', 'testing') ? $rule : $rule->uncompromised();
        });

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Event::subscribe(AuditAuthEvents::class);

        // Staff endpoints (auth:sanctum) accept only tokens that belong to a person.
        // Portal source tokens work only on the integration endpoints, checked by AuthenticateSource.
        Sanctum::authenticateAccessTokensUsing(
            fn ($token, bool $isValid) => $isValid && $token->tokenable instanceof User,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([SyncAccessCommand::class, MakeSampleFile::class, Benchmark::class, ScanPending::class, SendReminders::class]);
        }
    }
}
