<?php

namespace App\Http\Middleware;

use App\Modules\Access\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after authentication on every API request:
 * - suspended accounts are signed out at once;
 * - users whose role needs two-factor can only set it up until it is confirmed;
 * - users who must change their password can only do that.
 */
class EnsureAccountReady
{
    /** Routes allowed while two-factor setup or a password change is pending. */
    private const ALWAYS_ALLOWED = ['me.show', 'me.update'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json(['message' => 'This account is suspended.', 'code' => 'account_suspended'], 401);
        }

        if (in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        if ($user->requiresTwoFactor() && ! $user->hasConfirmedTwoFactor()) {
            return response()->json([
                'message' => 'Set up two-factor sign-in before continuing.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        if ($user->must_change_password) {
            return response()->json([
                'message' => 'Change your password before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
