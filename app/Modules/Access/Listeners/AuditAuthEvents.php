<?php

namespace App\Modules\Access\Listeners;

use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

class AuditAuthEvents
{
    public function __construct(private Auditor $auditor) {}

    public function login(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->save();
            $this->auditor->record('auth.login', $event->user, actorId: $event->user->id);
        }
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->auditor->record('auth.logout', $event->user, actorId: $event->user->id);
        }
    }

    public function twoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->auditor->record('auth.two_factor_enabled', $event->user, actorId: $event->user->id);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->auditor->record('auth.two_factor_disabled', $event->user, actorId: $event->user->id);
    }

    public function recoveryCodes(RecoveryCodesGenerated $event): void
    {
        $this->auditor->record('auth.recovery_codes_generated', $event->user, actorId: $event->user->id);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            RecoveryCodesGenerated::class => 'recoveryCodes',
        ];
    }
}
