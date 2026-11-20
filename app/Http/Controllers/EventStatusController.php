<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventEdit;
use App\Models\User;
use App\Notifications\EventStatusChangedNotification;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle actions on "Organized by me" (Task #37, UC-1.3).
 *
 *   draft      -> published   (publish)
 *   published  -> on_hold     (hold: kept and restorable, frozen meanwhile)
 *   on_hold    -> published   (restore, with a new date and time: C10, Task #122)
 *   published  -> completed   (when it ends: the events:complete command, Task #101)
 *   any open   -> cancelled   (cancel, with a reason)
 *   no applicants -> deleted  (delete, after typing the title: C11, Task #122; otherwise cancel)
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

    /**
     * An event on hold comes back only with a new date and time (C10, 13 October; Task #122):
     * the old one has usually passed or no longer holds. The other dates move by the same amount
     * as the start, so the event keeps its shape, and are then kept inside the agreed windows
     * (Task #78): applications close by the end at the latest and never in the past, and points
     * can be given until the end at least. The organiser can fine-tune them on the Edit page.
     * The change goes into the edit history, and applicants are told the new date.
     */
    public function restore(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        if ($event->status !== Event::STATUS_ON_HOLD) {
            return back()->with('error', __('events.status.only_an_event_on_hold'));
        }

        $data = $request->validate([
            'event_starts_at' => ['required', 'date', 'after:now'],
            'event_ends_at' => ['required', 'date', 'after:event_starts_at'],
        ], [
            'event_starts_at.required' => __('events.status.restore_needs_a_new_start'),
            'event_starts_at.after' => __('events.status.restore_start_in_the_future'),
            'event_ends_at.required' => __('events.status.restore_needs_a_new_end'),
            'event_ends_at.after' => __('events.status.restore_end_after_start'),
        ]);

        $restored = DB::transaction(function () use ($request, $event, $data) {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Two clicks at once, or someone else acted first.
            if ($locked->status !== Event::STATUS_ON_HOLD) {
                throw ValidationException::withMessages(['event_starts_at' => __('events.status.only_an_event_on_hold')]);
            }

            $attributes = ['status' => Event::STATUS_PUBLISHED, ...$this->movedDates($locked, $data)];
            $changes = $this->dateChanges($locked, $attributes);

            $locked->update($attributes);

            EventEdit::create([
                'event_id' => $locked->id,
                'actor_id' => $request->user()->id,
                'changes' => ['status' => ['old' => Event::STATUS_ON_HOLD, 'new' => Event::STATUS_PUBLISHED], ...$changes],
                'created_at' => now(),
            ]);

            return $locked;
        });

        $this->notifyApplicants($restored, EventStatusChangedNotification::RESTORED, null, $this->when($restored));

        return back()->with('success', __('events.status.title_published_again_on', [
            'title' => $restored->title,
            'when' => $this->when($restored),
        ]));
    }

    /**
     * The dates of a restored event: the new start and end, and the other dates moved with the start.
     *
     * @param  array{event_starts_at: string, event_ends_at: string}  $data
     * @return array<string, CarbonInterface|null>
     */
    private function movedDates(Event $event, array $data): array
    {
        $starts = Carbon::parse($data['event_starts_at'])->startOfMinute();
        $ends = Carbon::parse($data['event_ends_at'])->startOfMinute();
        $offset = (int) $event->event_starts_at->copy()->startOfMinute()->diffInSeconds($starts, false);
        $moved = fn (?CarbonInterface $date): ?CarbonInterface => $date?->copy()->addSeconds($offset);

        // Applications close by the end at the latest (Task #78) and never in the past.
        $applyBy = $moved($event->application_deadline);
        if ($applyBy->gt($ends)) {
            $applyBy = $ends->copy();
        }
        if ($applyBy->lte(now())) {
            $applyBy = $starts->copy();
        }

        // An opening date must come before the closing one; otherwise applications open on publishing.
        $opensAt = $moved($event->applications_open_at);
        if ($opensAt !== null && $opensAt->gte($applyBy)) {
            $opensAt = null;
        }

        // Points can be given until the end at least.
        $completeBy = $moved($event->completion_deadline);
        if ($completeBy->lt($ends)) {
            $completeBy = $ends->copy();
        }

        return [
            'event_starts_at' => $starts,
            'event_ends_at' => $ends,
            'applications_open_at' => $opensAt,
            'application_deadline' => $applyBy,
            'completion_deadline' => $completeBy,
        ];
    }

    /**
     * The date fields a restore changed, as {field: {old, new}} like the edit history (Task #76).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{old: ?string, new: ?string}>
     */
    private function dateChanges(Event $event, array $attributes): array
    {
        $minute = fn (?CarbonInterface $date): ?string => $date?->format('Y-m-d H:i');
        $changes = [];

        foreach ($attributes as $field => $new) {
            if ($field === 'status') {
                continue;
            }

            $old = $minute($event->{$field});
            if ($old !== $minute($new)) {
                $changes[$field] = ['old' => $old, 'new' => $minute($new)];
            }
        }

        return $changes;
    }

    /**
     * The new date and time, as students and organisers read it.
     */
    private function when(Event $event): string
    {
        return $event->event_starts_at->translatedFormat('D j M Y, H:i').'–'.$event->event_ends_at->format('H:i');
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

    /**
     * Deleting is for good, so the organiser types the event's title first (C11, 13 October;
     * Task #122). Only an event nobody has applied to can go; otherwise it is cancelled instead.
     */
    public function destroy(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manage', $event);

        $request->validate([
            'title' => ['required', 'string'],
        ], [
            'title.required' => __('events.status.type_the_title_to_delete'),
        ]);

        // Exactly the title, so a slip of the hand never deletes the wrong event.
        if ($request->string('title')->toString() !== $event->title) {
            throw ValidationException::withMessages(['title' => __('events.status.the_title_does_not_match')]);
        }

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
    private function notifyApplicants(Event $event, string $change, ?string $reason = null, ?string $when = null): void
    {
        $students = User::query()
            ->whereIn('id', $event->applications()
                ->whereIn('status', [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED])
                ->select('student_id'))
            ->get();

        Notification::send($students, new EventStatusChangedNotification($change, $event->id, $event->title, $reason, when: $when));
    }
}
