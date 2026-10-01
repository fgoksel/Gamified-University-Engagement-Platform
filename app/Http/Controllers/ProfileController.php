<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profile Settings page of teachers and students, with three sections:
 * Profile Data, Change Password and Appearance.
 *
 * Name, email, Neptun code, faculty, major and year come from the Neptun
 * import and are managed by the administrator (UC-3.1, UC-3.2), so they are
 * shown read-only. The user can change their photo, password and theme.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user()->load('faculty:id,name');

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'faculty' => $user->faculty?->name,
                'neptunCode' => $user->neptun_code,
                'major' => $user->major,
                'yearOfStudy' => $user->year_of_study,
            ],
        ]);
    }

    /**
     * Upload a new profile photo (replaces the old one).
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'avatar.image' => 'The photo must be an image.',
            'avatar.mimes' => 'The photo must be a JPG, PNG or WebP file.',
            'avatar.max' => 'The photo cannot be larger than 2 MB.',
        ]);

        $user = $request->user();
        $this->deleteAvatarFile($user->avatar);

        $user->update([
            'avatar' => $request->file('avatar')->store('avatars', 'public'),
        ]);

        return back()->with('success', 'Your profile photo has been updated.');
    }

    /**
     * Remove the profile photo; the initials are shown again.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->deleteAvatarFile($user->avatar);
        $user->update(['avatar' => null]);

        return back()->with('success', 'Your profile photo has been removed.');
    }

    /**
     * Change password from the Profile Settings page.
     */
    public function updatePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ])->save();

        return back()->with('success', 'Your password has been changed.');
    }

    private function deleteAvatarFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
