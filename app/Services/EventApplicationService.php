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
                        'event' => 'You have already applied to this event.',
                    ]);
                }

                if ($existing->status === EventApplication::REJECTED) {
                    throw ValidationException::withMessages([
                        'event' => 'Your application was previously rejected and cannot be resubmitted.',
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

            if ($existing) {
                $existing->update([
                    'status' => $toStatus,
                    'applied_at' => now(),
                    'decided_at' => $decidedAt,
                    'withdrawn_at' => null,
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
                'reason' => $fromStatus === EventApplication::WITHDRAWN
                    ? 'Re-applied after withdrawal'
                    : 'Initial application',
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

        if ($decision !== null) {
            $student->notify(new EventApplicationDecisionNotification($decision, $event->id, $event->title));
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
        $promoted = null;

        $application = DB::transaction(function () use ($student, $event, &$promoted) {
            /** @var Event $lockedEvent */
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            /** @var EventApplication|null $application */
            $application = EventApplication::query()
                ->where('event_id', $lockedEvent->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw ValidationException::withMessages([
                    'event' => 'You do not have an application for this event.',
                ]);
            }

            if ($application->status === EventApplication::WITHDRAWN) {
                throw ValidationException::withMessages([
                    'event' => 'You have already withdrawn from this event.',
                ]);
            }

            if ($application->status === EventApplication::REJECTED) {
                throw ValidationException::withMessages([
                    'event' => 'You cannot withdraw a rejected application.',
                ]);
            }

            if ($application->isCredited()) {
                throw ValidationException::withMessages([
                    'event' => 'You cannot withdraw from an event that has already been credited.',
                ]);
            }

            if (now()->gt($lockedEvent->event_starts_at)) {
                throw ValidationException::withMessages([
                    'event' => 'You cannot withdraw from an event that has already started.',
                ]);
            }

            $fromStatus = $application->status;

            $application->update([
                'status' => EventApplication::WITHDRAWN,
                'withdrawn_at' => now(),
            ]);

            EventApplicationTransition::create([
                'event_application_id' => $application->id,
                'from_status' => $fromStatus,
                'to_status' => EventApplication::WITHDRAWN,
                'actor_id' => $student->id,
                'reason' => 'Student withdrawal',
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
                    ->orderBy('applied_at')
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
                        'reason' => 'Promoted from waitlist upon vacancy',
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
                'student' => 'Only active accounts may apply to events.',
            ]);
        }

        if (empty($student->neptun_code)) {
            throw ValidationException::withMessages([
                'student' => 'A valid Neptun code is required to apply to events.',
            ]);
        }

        if ($event->status !== Event::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'event' => 'This event is not open for applications.',
            ]);
        }

        // Archiving a topic freezes everything below it too (TopicAccess::isHidden looks upwards).
        $access = app(TopicAccess::class);
        if (($event->owningTopic && $access->isHidden($event->owningTopic))
            || ($event->audienceTopic && $access->isHidden($event->audienceTopic))) {
            throw ValidationException::withMessages([
                'event' => 'Applications are frozen because this topic is archived.',
            ]);
        }

        // A surprise event takes no applications (Task #86). Students never see it, so the
        // answer is the one any event that is not open gives and says nothing about it.
        if ($event->is_hidden) {
            throw ValidationException::withMessages([
                'event' => 'This event is not open for applications.',
            ]);
        }

        // Applications have their own window (Task #78): they open on the chosen date, or when
        // the event was published, and may stay open while the event runs.
        $opensAt = $event->applicationsOpenFrom();
        if ($opensAt !== null && now()->lt($opensAt)) {
            throw ValidationException::withMessages([
                'event' => 'Applications for this event are not open yet.',
            ]);
        }

        if ($event->application_deadline !== null && now()->gt($event->application_deadline)) {
            throw ValidationException::withMessages([
                'event' => 'The application deadline for this event has passed.',
            ]);
        }

        if (now()->gt($event->event_ends_at)) {
            throw ValidationException::withMessages([
                'event' => 'This event has already ended.',
            ]);
        }

        // Audience membership check (D01 & D03)
        if ($event->audience_topic_id === null) {
            throw ValidationException::withMessages([
                'event' => 'This event is by invitation only.',
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
                'event' => 'You do not belong to the audience for this event.',
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
