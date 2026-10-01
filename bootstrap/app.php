<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Route checks for roles and permissions, e.g. ->middleware('role:admin').
        // Put 'auth' first: ->middleware(['auth', 'role:admin']).
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Temporary password must be changed first (UC-2.1)
            'password.changed' => EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Too many login or "forgot password" attempts: show a message on the
        // form instead of an error page (Task #16 rate limits).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->routeIs('login.store', 'password.email')) {
                return back()->withErrors([
                    'email' => 'Too many attempts. Please wait a minute and try again.',
                ]);
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A logged-in user who opens a page they may not see goes back to the
        // Leaderboard with a message (Functional Specification UC-1.2, UC-2.2).
        // Requests that expect JSON (for example the QR scanner) still get a 403.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 403 || $request->expectsJson() || $request->is('/')) {
                return null;
            }

            return redirect('/')->with('error', 'You do not have permission to view this page.');
        });
    })->create();
