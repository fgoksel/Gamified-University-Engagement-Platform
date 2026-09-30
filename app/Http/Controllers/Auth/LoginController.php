<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login and logout for teachers and students (UC-1.1 B, UC-2.1).
 */
class LoginController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Log the user in.
     *
     * Only "active" accounts can log in. Invited accounts must activate first,
     * and deactivated accounts are refused. Every failure shows the same
     * message, so nobody can find out which email addresses exist.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([...$credentials, 'status' => 'active'])) {
            throw ValidationException::withMessages([
                'email' => 'Incorrect username or password.',
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();

        // Administrators work in the Filament admin panel.
        if ($user->hasRole(UserRole::Admin)) {
            return redirect('/admin');
        }

        // First login with a temporary password (UC-2.1 A).
        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        return redirect()->intended(route('home'));
    }

    /**
     * Log the user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
