<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Password Setup page (UC-1.1 A and C, UC-2.1).
 *
 * The same page is used for:
 * - activating a new account from the invitation email, and
 * - choosing a new password from the "Forgot password" email.
 */
class SetPasswordController extends Controller
{
    /**
     * What a person is told when the link in their email no longer works.
     */
    public static function expiredMessage(): string
    {
        return __('auth.set_password.expired');
    }

    /**
     * Show the Password Setup page, or send the user back if the link is bad.
     */
    public function create(Request $request, string $token): Response|RedirectResponse
    {
        $user = $this->findUserByToken($token);

        if (! $user) {
            return redirect()->route('login')->with('error', self::expiredMessage());
        }

        return Inertia::render('Auth/SetPassword', [
            'token' => $token,
            'email' => $user->email,
            'isActivation' => $user->isInvited(),
            'loggedOutName' => $this->logOutSomeoneElse($request, $user),
        ]);
    }

    /**
     * Save the new password and activate the account.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'password.confirmed' => __('auth.passwords_do_not_match'),
        ]);

        $user = $this->findUserByToken($request->string('token'));

        if (! $user) {
            return redirect()->route('login')->with('error', self::expiredMessage());
        }

        $this->logOutSomeoneElse($request, $user);

        // On a password reset the old password is the user's own one (UC-1.1 exceptions).
        if (! $user->isInvited() && Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password_same_as_old'),
            ]);
        }

        $wasInvited = $user->isInvited();

        $user->forceFill([
            'password' => $request->input('password'),
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
            // The link works only once.
            'activation_token' => null,
            'activation_token_expires_at' => null,
        ])->save();

        return redirect()->route('login')->with('success', $wasInvited
            ? __('auth.set_password.activated')
            : __('auth.set_password.changed'));
    }

    /**
     * The link belongs to $owner. If another account is logged in in this
     * browser (for example the admin who sent the invitations), log it out,
     * so the page is not skipped and the new password is not mixed up with
     * that session. Returns the name of the account that was logged out.
     */
    private function logOutSomeoneElse(Request $request, User $owner): ?string
    {
        $current = $request->user();

        if ($current === null || $current->is($owner)) {
            return null;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $current->name;
    }

    /**
     * Find the invited or active user this link belongs to.
     *
     * Returns null when the link is unknown, already used or expired.
     * Deactivated accounts cannot use links.
     */
    private function findUserByToken(string $token): ?User
    {
        $user = User::query()
            ->where('activation_token', $token)
            ->whereIn('status', ['invited', 'active'])
            ->first();

        return $user?->hasValidActivationToken() ? $user : null;
    }
}
