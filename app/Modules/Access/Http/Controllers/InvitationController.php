<?php

namespace App\Modules\Access\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Modules\Access\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/** A new user sets their own password from the invitation link. Admins never see passwords. */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
        ]);

        $status = Password::broker('invitations')->reset($data, function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => $password, 'must_change_password' => false])->save();
                $this->audit()->record('auth.invitation_accepted', $user, actorId: $user->id);
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            $this->fail('email', 'This invitation link is invalid or has expired. Ask your administrator to send a new one.');
        }

        return response()->json(['message' => 'Your password is set. You can now sign in.']);
    }
}
