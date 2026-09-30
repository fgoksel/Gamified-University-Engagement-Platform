<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Safety net for "all routes are protected by role / permission middleware"
 * (Task #34, Technical Specification 8.2).
 *
 * A new route fails this test until it requires a login or a role / permission,
 * or is added to PUBLIC_ROUTES below on purpose.
 */
class RouteProtectionTest extends TestCase
{
    /**
     * Routes that anyone may open, with the reason. Add a route here only when
     * the specification says it is public.
     *
     * @var array<string, string>
     */
    private const PUBLIC_ROUTES = [
        '/' => 'Leaderboard home; placeholder page until the leaderboard requires a login (Task #54)',
        'up' => 'Laravel health check',
        'admin/login' => 'Admin panel login page (Filament)',
        'login' => 'Login page (UC-1.1, UC-2.1, Tasks #14 and #15)',
        'auth/activate/{token}' => 'Account activation link from the invitation email (UC-1.1, UC-2.1)',
        'leaderboard/public' => 'Observer leaderboard without login (UC-4.1, Technical Specification 7.2)',
    ];

    /**
     * Routes of packages that check access themselves.
     *
     * @var list<string>
     */
    private const PACKAGE_ROUTE_PREFIXES = [
        'livewire',           // Livewire: components authorize their own actions
        'storage/',           // Laravel's built-in public file route (development only)
        'filament/exports/',  // Filament downloads check the owner of the export
        'filament/imports/',
        '_inertia/devtools',  // Inertia devtools, guarded by its own gate
    ];

    public function test_every_route_requires_a_login_or_is_public_on_purpose(): void
    {
        $this->assertSame(
            [],
            $this->unprotectedRoutes(),
            'These routes have no auth / role / permission middleware. Protect them, for example '
            ."->middleware(['auth', 'role:admin']), or add them to PUBLIC_ROUTES in ".self::class.' if the specification says they are public.',
        );
    }

    public function test_the_guard_finds_an_unprotected_route(): void
    {
        Route::middleware('web')->get('/_guard/open', fn () => 'open');
        Route::middleware(['web', 'auth'])->get('/_guard/login-only', fn () => 'ok');
        Route::middleware(['web', 'auth', 'role:admin'])->get('/_guard/admin', fn () => 'ok');
        Route::middleware(['web', 'auth', 'permission:events.apply'])->get('/_guard/permission', fn () => 'ok');

        $this->assertSame(['GET _guard/open'], $this->unprotectedRoutes());
    }

    /**
     * @return list<string> "METHOD uri" of routes without protection
     */
    private function unprotectedRoutes(): array
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->isPublic($route) || $this->isProtected($route)) {
                continue;
            }

            $unprotected[] = $route->methods()[0].' '.$route->uri();
        }

        sort($unprotected);

        return $unprotected;
    }

    private function isPublic(RoutingRoute $route): bool
    {
        if (array_key_exists($route->uri(), self::PUBLIC_ROUTES)) {
            return true;
        }

        foreach (self::PACKAGE_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route->uri(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isProtected(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth' || preg_match('/^(auth|role|permission|role_or_permission|can):/', $middleware)) {
                return true;
            }

            // Middleware classes: Laravel's / Filament's "Authenticate", the gate check "Authorize", spatie's checks
            if (preg_match('/\\\\(Authenticate|Authorize|RoleMiddleware|PermissionMiddleware|RoleOrPermissionMiddleware)(:|$)/', $middleware)) {
                return true;
            }
        }

        return false;
    }
}
