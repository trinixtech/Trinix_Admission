<?php

namespace App\Providers;

use App\Modules\Access\Models\User;
use App\Modules\Access\Services\AccessControl;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function gate(): void
    {
        // The queue dashboard at /horizon is for users who may see system health.
        Gate::define('viewHorizon', fn (?User $user = null) => $user !== null
            && app(AccessControl::class)->can($user, 'system.health'));
    }
}
