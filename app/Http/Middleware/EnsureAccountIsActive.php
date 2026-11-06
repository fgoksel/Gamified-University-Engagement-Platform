<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     *
     * Ensures that an authenticated user is active. If deactivated, they are
     * logged out immediately on their next click (UC-3.1.2, UC-3.2.2).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, __('auth.account.deactivated'));
            }

            return redirect()->route('login')->with('error', __('auth.account.deactivated_contact'));
        }

        return $next($request);
    }
}
