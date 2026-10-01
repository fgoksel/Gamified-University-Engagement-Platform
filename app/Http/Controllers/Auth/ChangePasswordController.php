<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Password Change page shown on first login (UC-2.1 A):
 * old password, new password and confirm new password.
 */
class ChangePasswordController extends Controller
{
    /**
     * Show the Password Change page.
     */
    public function edit(): Response
    {
        return Inertia::render('Auth/ChangePassword');
    }

    /**
     * Save the new password.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'current_password.current_password' => 'The old password is incorrect.',
            'password.confirmed' => 'The two passwords do not match.',
            'password.different' => 'The new password cannot be the same as the old one.',
        ]);

        $request->user()->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ])->save();

        // Shown as a pop-up message on the Home page.
        return redirect()->route('home')->with('success', 'Your password has been changed.');
    }
}
