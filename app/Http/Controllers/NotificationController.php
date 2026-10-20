<?php

namespace App\Http\Controllers;

use App\Services\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The person's in-app notifications (Task #53): list, mark one read, mark all read.
 * Everything works on the signed-in person's own notifications only.
 */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationFeed $feed): Response
    {
        $notifications = $request->user()->notifications()->paginate(20);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications->through(fn ($notification) => $feed->present($notification)),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
