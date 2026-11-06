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
                    'overlap' => __('events.apply.overlap', ['event' => $overlap->title]),
                ]);
            }
        }

        $application = $service->apply($request->user(), $event);

        $message = match ($application->status) {
            EventApplication::ACCEPTED => __('events.apply_flash.your_application_has_been_accepted'),
            EventApplication::WAITLISTED => __('events.apply_flash.the_event_is_at_capacity'),
            EventApplication::PENDING => __('events.apply_flash.your_application_has_been_registered'),
            default => __('events.apply_flash.application_submitted'),
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

        $message = __('events.apply_flash.you_have_successfully_withdrawn');

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $application->status,
                'message' => $message,
                'application' => $application,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Undo an application straight after sending it (Task #94).
     */
    public function undo(Request $request, Event $event, EventApplicationService $service): RedirectResponse|JsonResponse
    {
        $service->undo($request->user(), $event);

        $message = __('events.apply_flash.application_undone');

        if ($request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return back()->with('success', $message);
    }
}
