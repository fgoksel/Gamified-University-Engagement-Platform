<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationTransition;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Notifications\EventApplicationDecisionNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Service managing student event applications, atomic capacity guards,
 * and state transitions (Task #39).
 */
class EventApplicationService
{
    /**
     * Atomically apply a student to an event, enforcing capacity guards,
     * audience eligibility, and reapplication rules.
     *
     * In FCFS mode the outcome is known at once, so the student is told whether
     * they got a place or were waitlisted (Task #53). A pending application in
     * selection mode is told about later, when the organizer decides.
     *
     * @throws ValidationException
     */
    public function apply(User $student, Event $event): EventApplication
    {
        $application = DB::transaction(function () use ($student, $event) {
            /** @var Event $lockedEvent */
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $this->validateEligibility($student, $lockedEvent);

            /** @var EventApplication|null $existing */
            $existing = EventApplication::query()
                ->where('event_id', $lockedEvent->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            $fromStatus = $existing?->status;

            if ($existing) {
                if (in_array($existing->status, [
                    EventApplication::PENDING,
                    EventApplication::ACCEPTED,
                    EventApplication::WAITLISTED,
                ], true)) {
                    throw ValidationException::withMessages([
                        'event' => __('events.application.you_have_already_applied'),
                    ]);
                }

                if ($existing->status === EventApplication::REJECTED) {
                    throw ValidationException::withMessages([
                        'event' => __('events.application.your_application_was_previously_rejected'),
                    ]);
                }
            }

            // Determine application status based on mode and capacity
            if ($lockedEvent->application_type === 'fcfs') {
                $acceptedCount = EventApplication::query()
                    ->where('event_id', $lockedEvent->id)
                    ->where('status', EventApplication::ACCEPTED)
                    ->lockForUpdate()
                    ->count();

                if ($acceptedCount < $lockedEvent->capacity) {
                    $toStatus = EventApplication::ACCEPTED;
                    $decidedAt = now();
                } else {
                    $toStatus = EventApplication::WAITLISTED;
                    $decidedAt = null;
                }
            } else {
                // teacher_select: all applicants start pending review
                $toStatus = EventApplication::PENDING;
                $decidedAt = null;
            }

            $wasUndone = $existing?->isUndone() ?? false;

            if ($existing) {
                $existing->update([
                    'status' => $toStatus,
                    'applied_at' => now(),
                    'decided_at' => $decidedAt,
                    'withdrawn_at' => null,
                    'undone_at' => null,
                ]);
                $application = $existing;
            } else {
                $application = EventApplication::create([
                    'event_id' => $lockedEvent->id,
                    'student_id' => $student->id,
                    'status' => $toStatus,
                    'applied_at' => now(),
                    'decided_at' => $decidedAt,
                ]);
            }

            EventApplicationTransition::create([
                'event_application_id' => $application->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $student->id,
                'reason' => match (true) {
                    $wasUndone => __('events.application.applied_again_after_undo'),
                    $fromStatus === EventApplication::WITHDRAWN => __('events.application.re_applied_after_withdrawal'),
                    default => __('events.application.initial_application'),
                },
                'created_at' => now(),
            ]);

            return $application;
        });

        // Queued notifications go out only after the transaction has committed.
        $decision = match ($application->status) {
            EventApplication::ACCEPTED => EventApplicationDecisionNotification::CONFIRMED,
            EventApplication::WAITLISTED => EventApplicationDecisionNotification::WAITLISTED,
            default => null,
        };

        // Sent once the undo minute is over (Task #94), and skipped if the student undid or left by then.
        if ($decision !== null) {
            $student->notify(
                (new EventApplicationDecisionNotification(
                    $decision,
                    $event->id,
                    $event->title,
                    $application->id,
                    $application->applied_at?->toIso8601String(),
                ))
                    ->delay(now()->addSeconds(EventApplication::UNDO_SECONDS))
            );
        }

        return $application;
    }

    /**
     * Atomically withdraw a student's application from an event (Task #50, UC-2.3).
     *
     * In FCFS mode, if an accepted applicant withdraws before the application deadline,
     * the oldest waitlisted applicant is automatically promoted to accepted (D08, D09).
     *
     * @throws ValidationException
     */
    public function withdraw(User $student, Event $event): EventApplication
    {
        return $this->leave($student, $event, undo: false);
    }

    /**
     * Take back an application straight after sending it (Task #94). Allowed only within
     * EventApplication::UNDO_SECONDS of applying and before any points were credited.
     * It runs like a withdrawal (a freed place still goes to the waitlist), but the
     * application is marked undone, so the student screens show it as never sent.
     *
     * @throws ValidationException
     */
    public function undo(User $student, Event $event): EventApplication
    {
        return $this->leave($student, $event, undo: true);
    }

    /**
     * Withdrawal and undo share one transaction, so the waitlist promotion is the same.
     *
     * @throws ValidationException
     */
    private function leave(User $student, Event $event, bool $undo): EventApplication
    {
        $promoted = null;

        $application = DB::transaction(function () use ($student, $event, $undo, &$promoted) {
            /** @var Event $lockedEvent */
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            /** @var EventApplication|null $application */
            $application = EventApplication::query()
                ->where('event_id', $lockedEvent->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if (! $application || ($undo && $application->status === EventApplication::WITHDRAWN)) {
                throw ValidationException::withMessages([
                    'event' => $undo
                        ? __('events.application.no_application_to_undo')
                        : __('events.application.you_do_not_have'),
                ]);
            }

            if ($undo && (
                $application->status === EventApplication::REJECTED
                || $application->isCredited()
                || $application->applied_at === null
                || $application->applied_at->lt(now()->subSeconds(EventApplication::UNDO_SECONDS))
            )) {
                throw ValidationException::withMessages([
                    'event' => __('events.application.too_late_to_undo'),
                ]);
            }

            if ($application->status === EventApplication::WITHDRAWN) {
                throw ValidationException::withMessages([
                    'event' => __('events.application.you_have_already_withdrawn'),
                ]);
            }

            if ($application->status === EventApplication::REJECTED) {
                throw ValidationException::withMessages([
                    'event' => __('events.application.you_cannot_withdraw_a_rejected'),
                ]);
            }

            if ($application->isCredited()) {
                throw ValidationException::withMessages([
                    'event' => __('events.application.you_cannot_withdraw'),
                ]);
            }

            // Undo is only possible in the first minute, so it is not blocked once the event runs.
            if (! $undo && now()->gt($lockedEvent->event_starts_at)) {
                throw ValidationException::withMessages([
                    'event' => __('events.application.you_cannot_withdraw_2'),
                ]);
            }

            $fromStatus = $application->status;

            $application->update($undo
                ? ['status' => EventApplication::WITHDRAWN, 'withdrawn_at' => null, 'undone_at' => now()]
                : ['status' => EventApplication::WITHDRAWN, 'withdrawn_at' => now()]);

            EventApplicationTransition::create([
                'event_application_id' => $application->id,
                'from_status' => $fromStatus,
                'to_status' => EventApplication::WITHDRAWN,
                'actor_id' => $student->id,
                'reason' => $undo ? __('events.application.undone_by_the_student') : __('events.application.student_withdrawal'),
                'created_at' => now(),
            ]);

            // Auto-promote waitlisted student if:
            // 1. The withdrawing student was accepted (vacating an accepted seat)
            // 2. The event is FCFS (D08 & D09 - no auto-promotion in teacher_select mode)
            // 3. The event is currently published
            // 4. Withdrawal occurred on or before application_deadline (D09)
            if ($fromStatus === EventApplication::ACCEPTED
                && $lockedEvent->application_type === 'fcfs'
                && $lockedEvent->status === Event::STATUS_PUBLISHED
                && now()->lte($lockedEvent->application_deadline)) {

                /** @var EventApplication|null $nextWaitlisted */
                $nextWaitlisted = EventApplication::query()
                    ->where('event_id', $lockedEvent->id)
                    ->where('status', EventApplication::WAITLISTED)
                    // applied_at has one-second precision: the id breaks a tie, so the first in line is clear.
                    ->orderBy('applied_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($nextWaitlisted) {
                    $nextWaitlisted->update([
                        'status' => EventApplication::ACCEPTED,
                        'decided_at' => now(),
                    ]);

                    EventApplicationTransition::create([
                        'event_application_id' => $nextWaitlisted->id,
                        'from_status' => EventApplication::WAITLISTED,
                        'to_status' => EventApplication::ACCEPTED,
                        'actor_id' => null,
                        'reason' => __('events.application.promoted_from_waitlist_upon_vacancy'),
                        'created_at' => now(),
                    ]);

                    $promoted = $nextWaitlisted->student;
                }
            }

            return $application;
        });

        // Queued notifications go out only after the transaction has committed.
        $promoted?->notify(new EventApplicationDecisionNotification(
            EventApplicationDecisionNotification::PROMOTED,
            $event->id,
            $event->title
        ));

        return $application;
    }

    /**
     * Check if a student is eligible to apply to the given event.
     *
     * @throws ValidationException
     */
    public function validateEligibility(User $student, Event $event): void
    {
        if ($student->status !== 'active') {
            throw ValidationException::withMessages([
                'student' => __('events.application.only_active_accounts_may_apply'),
            ]);
        }

        if (empty($student->neptun_code)) {
            throw ValidationException::withMessages([
                'student' => __('events.application.a_valid_neptun_code'),
            ]);
        }

        if ($event->status !== Event::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'event' => __('events.application.this_event_is_not_open'),
            ]);
        }

        // Archiving a topic freezes everything below it too (TopicAccess::isHidden looks upwards).
        $access = app(TopicAccess::class);
        if (($event->owningTopic && $access->isHidden($event->owningTopic))
            || ($event->audienceTopic && $access->isHidden($event->audienceTopic))) {
            throw ValidationException::withMessages([
                'event' => __('events.application.applications_are_frozen_because'),
            ]);
        }

        // A surprise event takes no applications (Task #86). Students never see it, so the
        // answer is the one any event that is not open gives and says nothing about it.
        if ($event->is_hidden) {
            throw ValidationException::withMessages([
                'event' => __('events.application.this_event_is_not_open'),
            ]);
        }

        // Applications have their own window (Task #78): they open on the chosen date, or when
        // the event was published, and may stay open while the event runs.
        $opensAt = $event->applicationsOpenFrom();
        if ($opensAt !== null && now()->lt($opensAt)) {
            throw ValidationException::withMessages([
                'event' => __('events.application.applications_for_this_event'),
            ]);
        }

        if ($event->application_deadline !== null && now()->gt($event->application_deadline)) {
            throw ValidationException::withMessages([
                'event' => __('events.application.the_application_deadline'),
            ]);
        }

        if (now()->gt($event->event_ends_at)) {
            throw ValidationException::withMessages([
                'event' => __('events.application.this_event_has_already_ended'),
            ]);
        }

        // Audience membership check (D01 & D03)
        if ($event->audience_topic_id === null) {
            throw ValidationException::withMessages([
                'event' => __('events.application.this_event_is_by_invitation'),
            ]);
        }

        // Explicit audience-node membership (D01 & D03, meeting of 16 Oct): the student
        // must hold an active role on the audience topic itself. Membership of a topic
        // above it does not count; nothing flows down automatically.
        $isMember = RoleAssignment::query()
            ->active()
            ->where('user_id', $student->id)
            ->where('topic_id', $event->audience_topic_id)
            ->exists();

        // ...and be allowed to apply there (events.apply, Task #65).
        if (! $isMember || ! Gate::forUser($student)->allows('apply', $event)) {
            throw ValidationException::withMessages([
                'event' => __('events.application.you_do_not_belong'),
            ]);
        }
    }

    /**
     * Whether the student may apply right now, without throwing: the same checks
     * as apply(). The catalog uses it so the Apply button and the server agree.
     */
    public function mayApply(User $student, Event $event): bool
    {
        try {
            $this->validateEligibility($student, $event);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * Another event the student already has a place at that overlaps this one in time
     * (UC-2.3: "This event overlaps in time with [event name]"), or null.
     */
    public function conflictingEvent(User $student, Event $event): ?Event
    {
        return Event::query()
            ->where('id', '!=', $event->id)
            ->where('status', Event::STATUS_PUBLISHED)
            ->where('event_starts_at', '<', $event->event_ends_at)
            ->where('event_ends_at', '>', $event->event_starts_at)
            ->whereHas('applications', fn ($query) => $query
                ->where('student_id', $student->id)
                ->where('status', EventApplication::ACCEPTED))
            ->orderBy('event_starts_at')
            ->first();
    }

    /**
     * Check whether the student has any schedule overlap with another accepted event.
     */
    public function hasTimeConflict(User $student, Event $event): bool
    {
        return $this->conflictingEvent($student, $event) !== null;
    }
}
