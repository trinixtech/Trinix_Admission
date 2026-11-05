<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountReady;
use App\Http\Middleware\RedirectToInstaller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: fn () => Route::middleware([])->group(__DIR__.'/../routes/install.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(RedirectToInstaller::class);
        // Behind a load balancer: trust its forwarded headers (set TRUSTED_PROXIES to its addresses in production).
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        $middleware->statefulApi();
        $middleware->redirectGuestsTo('/login');
        $middleware->alias(['account.ready' => EnsureAccountReady::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (AuthorizationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage() ?: 'You do not have permission to do this.'], 403)
            : null);
    })->create();
