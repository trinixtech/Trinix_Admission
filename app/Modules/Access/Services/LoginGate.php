<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks a sign-in attempt: suspended and locked accounts are refused,
 * and too many wrong passwords lock the account for a while.
 */
class LoginGate
{
    public function __construct(private Auditor $auditor) {}

    public function attempt(Request $request): ?User
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->auditor->record('auth.login_failed', null, new: ['reason' => 'unknown_account'], actorType: 'system');

            return null;
        }

        if ($user->isLocked()) {
            $this->auditor->record('auth.login_refused', $user, new: ['reason' => 'locked'], actorId: $user->id);
            throw ValidationException::withMessages([
                'email' => 'This account is locked after too many failed attempts. Try again after '
                    .$user->locked_until->timezone(config('app.display_timezone'))->format('g:i A').'.',
            ]);
        }

        if (! $user->isActive()) {
            $this->auditor->record('auth.login_refused', $user, new: ['reason' => 'suspended'], actorId: $user->id);
            throw ValidationException::withMessages(['email' => 'This account is suspended. Contact your administrator.']);
        }

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            DB::transaction(function () use ($user) {
                $user->failed_logins++;
                $max = (int) Settings::get('login_max_attempts', 5);
                if ($user->failed_logins >= $max) {
                    $user->locked_until = now()->addMinutes((int) Settings::get('login_lock_minutes', 15));
                    $user->failed_logins = 0;
                    $user->save();
                    $this->auditor->record('auth.locked', $user, new: ['locked_until' => $user->locked_until], actorId: $user->id);
                } else {
                    $user->save();
                    $this->auditor->record('auth.login_failed', $user, new: ['reason' => 'wrong_password'], actorId: $user->id);
                }
            });

            return null;
        }

        if ($user->failed_logins > 0 || $user->locked_until !== null) {
            $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
        }

        return $user;
    }
}
