<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventApplication;
use App\Services\EventApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventApplicationController extends Controller
{
    /**
     * Submit an application to an event (Task #39, UC-2.3).
     */
    public function store(Request $request, Event $event, EventApplicationService $service): RedirectResponse|JsonResponse
    {
        $application = $service->apply($request->user(), $event);

        $message = match ($application->status) {
            EventApplication::ACCEPTED => 'Your application has been accepted!',
            EventApplication::WAITLISTED => 'The event is at capacity. You have been placed on the waitlist.',
            EventApplication::PENDING => 'Your application has been registered; the teacher will decide on selection soon.',
            default => 'Application submitted.',
        };

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $application->status,
                'message' => $message,
                'application' => $application,
            ], 201);
        }

        return back()->with('success', $message);
    }
}
