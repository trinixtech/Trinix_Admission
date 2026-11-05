<?php

namespace App\Actions\Fortify;

use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /** @param  array<string, string>  $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => $this->passwordRules()])->validate();

        DB::transaction(function () use ($user, $input) {
            $user->forceFill([
                'password' => $input['password'],
                'must_change_password' => false,
                'failed_logins' => 0,
                'locked_until' => null,
            ])->save();
            app(Auditor::class)->record('auth.password_reset', $user, actorId: $user->id);
        });
    }
}
