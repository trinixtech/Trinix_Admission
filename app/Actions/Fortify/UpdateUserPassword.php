<?php

namespace App\Actions\Fortify;

use App\Modules\Access\Models\User;
use App\Modules\Audit\Services\Auditor;
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
    }
}
