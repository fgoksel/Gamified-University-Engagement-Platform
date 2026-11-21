<?php

namespace App\Services;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointReset;
use App\Models\PointTransaction;
use App\Models\User;
use App\Notifications\EventModeratedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * What a system admin may do to any event, whoever runs it (Task #58, UC-3.5): put it on hold,
 * cancel it, or remove it. Each needs a reason, which goes to the audit log and to the organizer.
 *
 *   published  -> on_hold    (hold: frozen, applications kept)
 *   any open   -> cancelled  (cancel: the points it gave stay)
 *   any event  -> removed    (remove: every credit it gave is reversed, then it is gone for everybody)
 *
 * Removing is final and all or nothing. Each credit gets a reversal row that points back at it (the
 * ledger itself is never edited, so a removal adds rows and changes none). If one cannot be written,
 * because the points belong to a closed semester, nothing is changed at all.
 */
class EventModerator
{
    public const MAX_REASON = 500;

    public function __construct(private TopicAccess $access, private PointLedger $ledger) {}

    public function canHold(Event $event): bool
    {
        return $event->status === Event::STATUS_PUBLISHED;
    }

    /** The same events an organizer may cancel: everything that has not ended or been cancelled. */
    public function canCancel(Event $event): bool
    {
        return in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_ON_HOLD], true);
    }

    public function canRemove(Event $event): bool
    {
        return ! $event->isRemoved();
    }

    /**
     * What removing the event would do, for the admin to read before they confirm.
     *
     * @return array{applicants: int, credits: int, points: int}
     */
    public function impact(Event $event): array
    {
        $points = $this->unreversedCredits($event)->pluck('points');

        return [
            'applicants' => $this->openApplications($event)->count(),
            'credits' => $points->count(),
            'points' => (int) $points->sum(),
        ];
    }

    /**
     * @throws EventActionRefused
     */
    public function hold(Event $event, User $admin, string $reason): Event
    {
        $reason = $this->checked($admin, $reason);

        $held = DB::transaction(function () use ($event, $admin, $reason) {
            $locked = $this->lock($event);

            if (! $this->canHold($locked)) {
                throw new EventActionRefused('not_published', __('events.status.only_a_published_event_can'), 409);
            }

            $locked->update(['status' => Event::STATUS_ON_HOLD]);

            $this->audit($admin, 'event.held', $locked, Event::STATUS_PUBLISHED, ['status' => Event::STATUS_ON_HOLD, 'reason' => $reason]);

            return $locked;
        });

        $this->tellApplicantsAndOrganizer($held, EventModeratedNotification::HELD, $reason);

        return $held;
    }

    /**
     * @throws EventActionRefused
     */
    public function cancel(Event $event, User $admin, string $reason): Event
    {
        $reason = $this->checked($admin, $reason);

        $cancelled = DB::transaction(function () use ($event, $admin, $reason) {
            $locked = $this->lock($event);

            if (! $this->canCancel($locked)) {
                throw new EventActionRefused('closed', __('events.status.this_event_can_no_longer'), 409);
            }

            $before = $locked->status;

            $locked->update([
                'status' => Event::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->audit($admin, 'event.cancelled', $locked, $before, ['status' => Event::STATUS_CANCELLED, 'reason' => $reason]);

            return $locked;
        });

        $this->tellApplicantsAndOrganizer($cancelled, EventModeratedNotification::CANCELLED, $reason);

        return $cancelled;
    }

    /**
     * Remove the event: mark it removed and reverse every credit it gave. Returns what was taken back.
     *
     * @return array{credits: int, points: int}
     *
     * @throws EventActionRefused
     */
    public function remove(Event $event, User $admin, string $reason): array
    {
        $reason = $this->checked($admin, $reason);

        [$removed, $credits, $points, $counted] = DB::transaction(function () use ($event, $admin, $reason) {
            $locked = $this->lock($event);

            if (! $this->canRemove($locked)) {
                throw new EventActionRefused('already_removed', __('admin.event_moderation.this_event_is_already_removed'), 409);
            }

            $before = $locked->status;

            // The event row is locked, so no credit is written while this runs (PointLedger::write asks
            // again after taking the same lock). Every credit not yet reversed gets its reversal, or
            // the whole removal is undone: the closed semester a credit belongs to refuses it.
            $found = $this->unreversedCredits($locked)->lockForUpdate()->get();
            $cutoff = PointReset::lastUncountedId();
            $counted = [];

            foreach ($found as $credit) {
                $this->ledger->reverse($credit, $admin, $reason);

                // Points from before the last reset no longer counted: the student loses nothing now.
                if ($credit->id > $cutoff) {
                    $counted[$credit->student_id] = ($counted[$credit->student_id] ?? 0) + $credit->points;
                }
            }

            $locked->update([
                'status' => Event::STATUS_REMOVED,
                'removed_at' => now(),
                'removed_by_id' => $admin->id,
                'removal_reason' => $reason,
            ]);

            $credits = $found->count();
            $points = (int) $found->sum('points');

            $this->audit($admin, 'event.removed', $locked, $before, [
                'status' => Event::STATUS_REMOVED,
                'reason' => $reason,
                'credits_reversed' => $credits,
                'points_reversed' => $points,
            ]);

            return [$locked, $credits, $points, $counted];
        });

        $this->tellAboutRemoval($removed, $reason, $counted);

        return ['credits' => $credits, 'points' => $points];
    }

    /**
     * Only a system admin moderates, and always with a reason. Returns the reason as it is kept.
     *
     * @throws EventActionRefused
     */
    private function checked(User $admin, string $reason): string
    {
        if (! $this->access->isSystemAdmin($admin)) {
            throw new EventActionRefused('forbidden', __('admin.event_moderation.only_a_system_admin_can'), 403);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new EventActionRefused('reason_required', __('admin.event_moderation.a_reason_is_required'));
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new EventActionRefused('reason_too_long', __('admin.event_moderation.the_reason_is_too_long', ['max' => self::MAX_REASON]));
        }

        return $reason;
    }

    private function lock(Event $event): Event
    {
        return Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $admin, string $action, Event $event, string $from, array $after): void
    {
        TopicAudit::record($admin, $action, $event->owning_topic_id, 'event', $event->id, ['status' => $from], ['event' => $event->title, ...$after]);
    }

    /**
     * The credits of the event that nothing has taken back yet.
     *
     * @return Builder<PointTransaction>
     */
    private function unreversedCredits(Event $event): Builder
    {
        return PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('type', PointTransaction::CREDIT)
            ->whereNotIn('id', PointTransaction::query()->whereNotNull('reversal_of_id')->select('reversal_of_id'))
            ->orderBy('id');
    }

    /**
     * @return HasMany<EventApplication, Event>
     */
    private function openApplications(Event $event): HasMany
    {
        return $event->applications()->whereIn('status', [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED]);
    }

    /**
     * Everyone whose application is still open hears what happened (withdrawn and rejected students
     * are left alone, as for what an organizer does). The organizer who made the event hears it with the
     * reason.
     */
    private function tellApplicantsAndOrganizer(Event $event, string $change, string $reason): void
    {
        $organizer = $event->creator;

        $students = User::query()
            ->whereIn('id', $this->openApplications($event)->select('student_id'))
            ->get()
            ->reject(fn (User $student) => $organizer?->is($student));

        Notification::send($students, new EventModeratedNotification($change, $event->id, $event->title));
        $organizer?->notify(new EventModeratedNotification($change, $event->id, $event->title, $reason));
    }

    /**
     * The students with an open application, and the ones whose points were taken back, hear that the
     * event is gone, and the points each lost (none for a student who only applied). The organizer hears it
     * with the reason.
     *
     * @param  array<int, int>  $counted  student id => points that counted and were taken back
     */
    private function tellAboutRemoval(Event $event, string $reason, array $counted): void
    {
        $organizer = $event->creator;

        $students = User::query()
            ->whereIn('id', $this->openApplications($event)->select('student_id'))
            ->orWhereIn('id', array_keys($counted))
            ->get()
            ->reject(fn (User $student) => $organizer?->is($student));

        foreach ($students as $student) {
            $student->notify(new EventModeratedNotification(EventModeratedNotification::REMOVED, $event->id, $event->title, null, $counted[$student->id] ?? 0));
        }

        $organizer?->notify(new EventModeratedNotification(EventModeratedNotification::REMOVED, $event->id, $event->title, $reason));
    }
}
