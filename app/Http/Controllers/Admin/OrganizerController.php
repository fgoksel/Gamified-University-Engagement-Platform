<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizerRequest;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganizerController extends Controller
{
    /**
     * Display a listing of organizers with faculty metadata for invitation.
     */
    public function index(): Response
    {
        $organizers = User::query()
            ->with('faculty:id,name,code')
            ->select([
                'id',
                'name',
                'email',
                'status',
                'faculty_id',
                'activation_token_expires_at',
                'created_at',
            ])
            ->latest('id')
            ->get();

        $faculties = Faculty::query()
            ->select(['id', 'name', 'code'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Organizers/Index', [
            'organizers' => $organizers,
            'faculties' => $faculties,
            'flash' => [
                'success' => session('success'),
                'warning' => session('warning'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Store a newly invited organizer in storage and dispatch invitation email.
     */
    public function store(StoreOrganizerRequest $request): RedirectResponse
    {
        $activationToken = Str::random(64);
        $expiresAt = now()->addHours(48);

        $user = DB::transaction(function () use ($request, $activationToken, $expiresAt) {
            $createdUser = User::create([
                'name' => trim($request->name),
                'email' => strtolower(trim($request->email)),
                'password' => Hash::make(Str::random(32)),
                'status' => 'invited',
                'must_change_password' => true,
                'faculty_id' => $request->faculty_id,
                'activation_token' => $activationToken,
                'activation_token_expires_at' => $expiresAt,
            ]);

            // Graceful forward-compatibility with spatie/laravel-permission (Task #9)
            if (method_exists($createdUser, 'assignRole')) {
                $createdUser->assignRole('teacher');
            }

            return $createdUser;
        });

        // Dispatch transactional invitation notification
        try {
            $user->notify(new OrganizerInvitationNotification($activationToken, 48));

            return redirect()
                ->route('admin.organizers.index')
                ->with('success', "The invitation was successfully sent to {$user->email}.");
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.organizers.index')
                ->with('warning', 'The user was created, but the email could not be delivered. Please try resending it from the list view.');
        }
    }
}
