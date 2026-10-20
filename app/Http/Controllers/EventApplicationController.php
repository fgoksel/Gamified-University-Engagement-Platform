<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventApplication;
use App\Services\EventApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EventApplicationController extends Controller
{
    /**
     * Submit an application to an event (Task #39, UC-2.3).
     */
    public function store(Request $request, Event $event, EventApplicationService $service): RedirectResponse|JsonResponse
    {
        // Time-conflict warning (UC-2.3): when the student already has a place at an
        // overlapping event, they must confirm before the application is sent.
        if (! $request->boolean('confirm_overlap') && $service->mayApply($request->user(), $event)) {
            $overlap = $service->conflictingEvent($request->user(), $event);

            if ($overlap !== null) {
                throw ValidationException::withMessages([
                    'overlap' => "This event overlaps in time with {$overlap->title}. Are you sure you want to apply?",
                ]);
            }
        }

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

    /**
     * Withdraw an application from an event (Task #50, UC-2.3).
     */
    public function withdraw(Request $request, Event $event, EventApplicationService $service): RedirectResponse|JsonResponse
    {
        $application = $service->withdraw($request->user(), $event);

        $message = 'You have successfully withdrawn from the event.';

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $application->status,
                'message' => $message,
                'application' => $application,
            ]);
        }

        return back()->with('success', $message);
    }
}
