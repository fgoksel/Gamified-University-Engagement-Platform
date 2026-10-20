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
            return back()->with('error', 'Only a draft can be published.');
        }

        if ($event->event_starts_at->isPast() || $event->application_deadline->isPast()) {
            return back()->with('error', 'This draft has dates in the past. Change them before publishing.');
        }

        if ($event->topic_tag_id === null) {
            return back()->with('error', 'Choose a topic tag before publishing.');
        }

        $event->update(['status' => Event::STATUS_PUBLISHED, 'published_at' => now()]);

        return back()->with('success', "“{$event->title}” is published.");
    }

    public function hold(Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        if ($event->status !== Event::STATUS_PUBLISHED) {
            return back()->with('error', 'Only a published event can be put on hold.');
        }

        $event->update(['status' => Event::STATUS_ON_HOLD]);
        $this->notifyApplicants($event, EventStatusChangedNotification::ON_HOLD);

        return back()->with('success', "“{$event->title}” is on hold. Students no longer see it.");
    }

    public function restore(Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        if ($event->status !== Event::STATUS_ON_HOLD) {
            return back()->with('error', 'Only an event on hold can be restored.');
        }

        $event->update(['status' => Event::STATUS_PUBLISHED]);
        $this->notifyApplicants($event, EventStatusChangedNotification::RESTORED);

        return back()->with('success', "“{$event->title}” is published again.");
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Tell students why the event is cancelled.',
        ]);

        if (in_array($event->status, [Event::STATUS_CANCELLED, Event::STATUS_COMPLETED], true)) {
            return back()->with('error', 'This event can no longer be cancelled.');
        }

        $event->update([
            'status' => Event::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => trim($data['reason']),
        ]);
        $this->notifyApplicants($event, EventStatusChangedNotification::CANCELLED, $event->cancellation_reason);

        return back()->with('success', "“{$event->title}” is cancelled.");
    }

    public function destroy(Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        // The database refuses these deletes too; this gives a readable message instead.
        if ($event->applications()->exists() || $event->qrTokens()->exists() || $event->pointTransactions()->exists()) {
            return back()->with('error', 'This event already has applications or attendance, so it cannot be deleted. Cancel it instead.');
        }

        $event->delete();

        return back()->with('success', "“{$event->title}” is deleted.");
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
