<?php

namespace App\Actions\Fortify;

use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /** @param  array<string, string>  $input */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => 'The current password is not correct.',
        ])->validateWithBag('updatePassword');

        DB::transaction(function () use ($user, $input) {
            $user->forceFill(['password' => $input['password'], 'must_change_password' => false])->save();
            app(Auditor::class)->record('auth.password_changed', $user);
        });

        // Keep this session signed in: Sanctum compares a fingerprint of the password kept in the session,
        // and this request is not on the API routes that refresh it.
        if (request()->hasSession()) {
            foreach ((array) config('sanctum.guard', ['web']) as $name) {
                $guard = Auth::guard($name);
                if ($guard->check() && $guard->id() === $user->id) {
                    request()->session()->put("password_hash_$name", method_exists($guard, 'hashPasswordForCookie')
                        ? $guard->hashPasswordForCookie($user->getAuthPassword()) : $user->getAuthPassword());
                }
            }
        }
    }
}
