<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot password" (UC-1.1 C).
 *
 * Also the way to get a new link when an invitation link has expired.
 */
class ForgotPasswordController extends Controller
{
    public const SENT_MESSAGE = 'If an account exists for this email address, we have sent a password reset link to it.';

    public const LINK_VALID_HOURS = 24;

    /**
     * Show the "Forgot password" page.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Send a new password link.
     *
     * The same message is shown whether or not the email exists, to protect
     * against phishing (UC-1.1 C).
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()
            ->where('email', $request->input('email'))
            ->whereIn('status', ['invited', 'active'])
            ->first();

        if ($user) {
            // A new link replaces any older one.
            $token = Str::random(64);

            $user->forceFill([
                'activation_token' => $token,
                'activation_token_expires_at' => now()->addHours(self::LINK_VALID_HOURS),
            ])->save();

            $user->notify(new PasswordResetNotification($token, self::LINK_VALID_HOURS));
        }

        return back()->with('success', self::SENT_MESSAGE);
    }
}
