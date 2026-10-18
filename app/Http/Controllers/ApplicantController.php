<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventApplication;
use App\Services\ApplicantReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Applied students" tab of an event (Task #41, UC-1.6).
 */
class ApplicantController extends Controller
{
    public function __construct(private ApplicantReviewService $review) {}

    public function index(Request $request, Event $event): Response
    {
        $this->authorizeReview($request, $event);

        return Inertia::render('Events/Applicants', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'capacity' => $event->capacity,
                'application_type' => $event->application_type,
                'status' => $event->status,
                'application_deadline' => $event->application_deadline?->toIso8601String(),
            ],
            'applicants' => $this->review->applicants($event),
        ]);
    }

    public function select(Request $request, Event $event): RedirectResponse
    {
        $this->authorizeReview($request, $event);

        $data = $request->validate([
            'selected' => ['present', 'array'],
            'selected.*' => ['integer'],
            'expand_capacity' => ['sometimes', 'boolean'],
        ]);

        $this->review->saveSelection(
            $request->user(),
            $event,
            $data['selected'],
            (bool) ($data['expand_capacity'] ?? false),
        );

        return back()->with('success', 'Applicant selections successfully saved.');
    }

    public function reject(Request $request, Event $event, EventApplication $application): RedirectResponse
    {
        $this->authorizeReview($request, $event);
        abort_unless($application->event_id === $event->id, 404);

        $this->review->reject($request->user(), $event, $application);

        return back()->with('success', 'The student was removed from the event.');
    }

    private function authorizeReview(Request $request, Event $event): void
    {
        abort_unless($this->review->mayReview($request->user(), $event), 403);
    }
}
