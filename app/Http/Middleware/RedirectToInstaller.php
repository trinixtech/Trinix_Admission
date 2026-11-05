<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the first Super Admin exists, every page goes to /install (API calls get 503). Off in automated tests. */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('installer.enforce') || $request->is('install', 'up') || Installer::installed()) {
            return $next($request);
        }
        if ($request->is('api/*') || $request->expectsJson()) {
            return new JsonResponse(['message' => 'This server is not installed yet. Open /install.'], 503);
        }

        // A plain response: the redirect helper would start an (encrypted) session, and there may be no key yet.
        return new RedirectResponse('/install');
    }
}
