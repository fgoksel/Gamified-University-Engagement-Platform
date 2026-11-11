<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\User;
use App\Notifications\EventStatusChangedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Lifecycle actions on "Organized by me" (Task #37, UC-1.3).
 *
 *   draft      -> published   (publish)
 *   published  -> on_hold     (hold: kept and restorable, frozen meanwhile)
 *   on_hold    -> published   (restore)
 *   any open   -> cancelled   (cancel, with a reason)
 *   no applicants -> deleted  (delete; otherwise cancel instead)
 *
 * Hold, restore and cancel tell the students who still have an application
 * (Task #53, D09).
 */
class EventStatusController extends Controller
{
    public function publish(Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        if ($event->status !== Event::STATUS_DRAFT) {
            return back()->with('error', __('events.status.only_a_draft_can'));
        }

        if ($event->event_starts_at->isPast() || $event->application_deadline->isPast()) {
            return back()->with('error', __('events.status.this_draft_has_dates'));
        }

        $event->update(['status' => Event::STATUS_PUBLISHED, 'published_at' => now()]);

        return back()->with('success', __('events.status.title_published', ['title' => $event->title]));
    }

    public function hold(Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        if ($event->status !== Event::STATUS_PUBLISHED) {
            return back()->with('error', __('events.status.only_a_published_event_can'));
        }

        $event->update(['status' => Event::STATUS_ON_HOLD]);
        $this->notifyApplicants($event, EventStatusChangedNotification::ON_HOLD);

        return back()->with('success', __('events.status.title_on_hold', ['title' => $event->title]));
    }

    public function restore(Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        if ($event->status !== Event::STATUS_ON_HOLD) {
            return back()->with('error', __('events.status.only_an_event_on_hold'));
        }

        $event->update(['status' => Event::STATUS_PUBLISHED]);
        $this->notifyApplicants($event, EventStatusChangedNotification::RESTORED);

        return back()->with('success', __('events.status.title_published_again', ['title' => $event->title]));
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => __('events.status.tell_students_why_the_event'),
        ]);

        if (in_array($event->status, [Event::STATUS_CANCELLED, Event::STATUS_COMPLETED], true)) {
            return back()->with('error', __('events.status.this_event_can_no_longer'));
        }

        $event->update([
            'status' => Event::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => trim($data['reason']),
        ]);
        $this->notifyApplicants($event, EventStatusChangedNotification::CANCELLED, $event->cancellation_reason);

        return back()->with('success', __('events.status.title_cancelled', ['title' => $event->title]));
    }

    public function destroy(Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        // The database refuses these deletes too; this gives a readable message instead.
        if ($event->applications()->exists() || $event->qrTokens()->exists() || $event->pointTransactions()->exists()) {
            return back()->with('error', __('events.status.this_event_already_has_applications'));
        }

        $event->delete();

        return back()->with('success', __('events.status.title_deleted', ['title' => $event->title]));
    }

    /**
     * Everyone whose application is still open; withdrawn and rejected students are left alone.
     */
    private function notifyApplicants(Event $event, string $change, ?string $reason = null): void
    {
        $students = User::query()
            ->whereIn('id', $event->applications()
                ->whereIn('status', [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED])
                ->select('student_id'))
            ->get();

        Notification::send($students, new EventStatusChangedNotification($change, $event->id, $event->title, $reason));
    }
}
