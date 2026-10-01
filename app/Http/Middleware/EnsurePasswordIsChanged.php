<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users who still have a temporary password to the Password Change
 * page before they can use the rest of the app (UC-2.1 A).
 *
 * Use on routes as ->middleware('password.changed').
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
