<?php

namespace App\Modules\Imports\Http\Middleware;

use App\Modules\Imports\Models\ExternalSource;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Bearer token issued to an external source (portal) for pushing applicant data. */
class AuthenticateSource
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;
        $source = $token?->tokenable;

        if (! $source instanceof ExternalSource || ! $token->can('import:push')
            || ($token->expires_at && $token->expires_at->isPast()) || ! $source->isActive()) {
            return response()->json(['message' => 'Invalid or revoked source token.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('source', $source);

        return $next($request);
    }
}
