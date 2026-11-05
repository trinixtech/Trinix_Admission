<?php

namespace App\Modules\Access\Services;

use App\Modules\Access\Models\User;
use Illuminate\Support\Facades\Password;

class Invitations
{
    public function send(User $user): void
    {
        $token = Password::broker('invitations')->createToken($user);
        $user->sendInvitationNotification($token);
    }
}
