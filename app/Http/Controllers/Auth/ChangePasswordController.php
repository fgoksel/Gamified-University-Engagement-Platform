<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Http\RedirectResponse;
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
    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ])->save();

        // Shown as a pop-up message on the Home page.
        return redirect()->route('home')->with('success', 'Your password has been changed.');
    }
}
