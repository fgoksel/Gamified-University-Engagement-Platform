<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way points are written. A student's points are the sum of their
 * ledger rows; there is no balance column to drift out of step.
 *
 * Every route that credits points (a passport scan, a poster or slip QR code,
 * the live QR code, a finished milestone task) ends up in credit(), and an
 * imported list in creditImported() (Task #85), so they all share the same
 * checks and the same database guard: one base credit per student and event,
 * whichever route wins a race.
 */
class PointLedger
{
    /** Written by the organiser's tick list, which is gone (Task #83). Kept so the old rows stay readable. */
    public const MANUAL = 'manual';

    public const PASSPORT = 'passport';

    public const QR_POSTER = 'qr_poster';

    public const QR_SLIP = 'qr_slip';

    public const MILESTONE = 'milestone';

    /** The live QR code the organiser shows during the event, changing every few seconds (Task #80). */
    public const QR_LIVE = 'qr_live';

    /** A list of students imported by the organiser (Task #85). */
    public const IMPORT = 'import';

    public function __construct(private TopicAccess $access) {}

    /**
     * Why points cannot be credited for this event right now, or null when they can.
     * Shown to the organiser before they try, and re-checked inside credit().
     */
    public function refusalFor(Event $event): ?EventActionRefused
    {
        if ($event->isOnHold()) {
            return new EventActionRefused('on_hold', 'This event is on hold. Points cannot be credited until it is restored.', 409);
        }

        if (! in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_COMPLETED], true)) {
            return new EventActionRefused('inactive', 'This event is no longer active.', 409);
        }

        if ($this->access->isHidden($event->owningTopic)) {
            return new EventActionRefused('topic_archived', 'The topic of this event is archived, so nothing can be credited.', 409);
        }

        if (now()->lt($event->event_starts_at)) {
            return new EventActionRefused('not_started', 'The event has not started yet.', 409);
        }

        if (now()->gt($event->completion_deadline)) {
            return new EventActionRefused('deadline_passed', "This event's completion deadline has passed.", 409);
        }

        return null;
    }

    /**
     * Write the base credit of an accepted participant. Never for a surprise event: that
     * one is credited only by import (Task #86, 26 Oct, Q8), so no scan, code or list works.
     *
     * $creditedBy is the person who authorised it: the organiser, or the issuer
     * of the QR code. Nobody can credit themselves.
     *
     * @throws EventActionRefused
     */
    public function credit(Event $event, User $student, User $creditedBy, string $source): PointTransaction
    {
        if ($event->is_hidden) {
            throw new EventActionRefused('import_only', 'This is a surprise event: its points are given only by import.', 409);
        }

        return $this->write($event, $student, $creditedBy, $source, function (?EventApplication $application) use ($student) {
            if ($application === null || ! $application->isAccepted()) {
                throw new EventActionRefused('not_registered', "{$student->name} is not registered or selected for this event.", 403);
            }
        });
    }

    /**
     * Write the base credit of a student on a list the organiser imported (Task #85).
     * The organiser vouches that the student met the event's condition, so no application
     * is needed: a surprise event, which nobody applies to, is credited this way. The
     * student must have a student account and belong to the event's area instead
     * (importArea()).
     *
     * @throws EventActionRefused
     */
    public function creditImported(Event $event, User $student, User $creditedBy): PointTransaction
    {
        return $this->write($event, $student, $creditedBy, self::IMPORT, function () use ($event, $student) {
            if (! $student->hasRole(UserRole::Student)) {
                throw new EventActionRefused('not_a_student', "{$student->name} does not have a student account.", 403);
            }

            $area = $this->importArea($event);
            $seesEvents = RoleAssignment::query()->active()
                ->where('topic_id', $area->id)
                ->where('user_id', $student->id)
                ->with('role')
                ->get()
                ->contains(fn (RoleAssignment $assignment) => $assignment->role->has(Capability::EventsView));

            if (! $seesEvents) {
                throw new EventActionRefused('not_in_area', "{$student->name} is not assigned to {$area->title} with a role that includes \"View events\".", 403);
            }
        });
    }

    /**
     * Whose students an import may credit (Task #85, team decision of 27 Oct): the topic the
     * event is announced to, or the topic that runs it when it is announced to nobody. Only
     * people whose role at that topic itself includes "View events" count, the same rule as
     * for seeing an event announced there; nothing flows up or down the tree (Task #71).
     */
    public function importArea(Event $event): Topic
    {
        return $event->audienceTopic ?? $event->owningTopic;
    }

    /**
     * The checks every credit shares, then the ledger row. $eligible says no for this
     * route by throwing; it runs inside the transaction, with the student's application
     * (if any) locked. An accepted application is marked as credited together with the
     * row. Any other application is left as it is, so that a rejected or withdrawn one
     * never shows as credited; an imported credit needs no application.
     *
     * @param  callable(?EventApplication): void  $eligible
     *
     * @throws EventActionRefused
     */
    private function write(Event $event, User $student, User $creditedBy, string $source, callable $eligible): PointTransaction
    {
        if ($student->is($creditedBy)) {
            throw new EventActionRefused('self_credit', 'Points cannot be credited to the person who authorised them.', 403);
        }

        if ($student->status !== 'active') {
            throw new EventActionRefused('student_inactive', 'This student account is not active.', 409);
        }

        if ($refusal = $this->refusalFor($event)) {
            throw $refusal;
        }

        try {
            return DB::transaction(function () use ($event, $student, $creditedBy, $source, $eligible) {
                $application = EventApplication::query()
                    ->where('event_id', $event->id)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->first();

                // Already credited by any route, also one that keeps no application (an import).
                $credited = PointTransaction::query()
                    ->where('event_id', $event->id)
                    ->where('student_id', $student->id)
                    ->where('type', PointTransaction::CREDIT)
                    ->exists();

                if ($credited || $application?->isCredited()) {
                    throw $this->alreadyCredited($student);
                }

                $eligible($application);

                $row = PointTransaction::create([
                    'student_id' => $student->id,
                    'event_id' => $event->id,
                    'semester_id' => $event->semester_id,
                    'credited_by_id' => $creditedBy->id,
                    'points' => $event->points_value,
                    'type' => PointTransaction::CREDIT,
                    'source' => $source,
                    'category' => $event->category,
                ]);

                if ($application?->isAccepted()) {
                    $application->forceFill(['completed_at' => now(), 'scored_at' => now()])->save();
                }

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            // Another route credited the same student and event first.
            throw $this->alreadyCredited($student);
        }
    }

    /**
     * Take a credit back with a new negative row. System Admins only, and never
     * for a semester that has been closed. The original row is kept as it was.
     *
     * @throws EventActionRefused
     */
    public function reverse(PointTransaction $credit, User $admin, string $reason): PointTransaction
    {
        if (! $this->access->isSystemAdmin($admin)) {
            throw new EventActionRefused('forbidden', 'Only a System Admin can reverse points.', 403);
        }

        if (trim($reason) === '') {
            throw new EventActionRefused('reason_required', 'A reason is required to reverse points.');
        }

        try {
            return DB::transaction(function () use ($credit, $admin, $reason) {
                $original = PointTransaction::query()->lockForUpdate()->findOrFail($credit->id);

                if ($original->type !== PointTransaction::CREDIT) {
                    throw new EventActionRefused('not_a_credit', 'Only a credit can be reversed.');
                }

                if (DB::table('semesters')->where('id', $original->semester_id)->value('status') === 'archived') {
                    throw new EventActionRefused('semester_closed', 'Points from a closed semester cannot be reversed.', 409);
                }

                return PointTransaction::create([
                    'student_id' => $original->student_id,
                    'event_id' => $original->event_id,
                    'semester_id' => $original->semester_id,
                    'credited_by_id' => $admin->id,
                    'points' => -$original->points,
                    'type' => PointTransaction::REVERSAL,
                    'source' => $original->source,
                    'category' => $original->category,
                    'reversal_of_id' => $original->id,
                    'reversal_reason' => $reason,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new EventActionRefused('already_reversed', 'This credit has already been reversed.', 409);
        }
    }

    /**
     * A student's points, in total and per category, optionally for one semester.
     *
     * @return array{total: int, academic: int, scientific: int, sports: int, community: int}
     */
    public function totals(User $student, ?int $semesterId = null): array
    {
        $rows = PointTransaction::query()
            ->where('student_id', $student->id)
            ->when($semesterId, fn ($query) => $query->where('semester_id', $semesterId))
            ->selectRaw('category, sum(points) as points')
            ->groupBy('category')
            ->pluck('points', 'category');

        $totals = ['total' => 0, 'academic' => 0, 'scientific' => 0, 'sports' => 0, 'community' => 0];

        foreach ($rows as $category => $points) {
            $totals[$category] = (int) $points;
            $totals['total'] += (int) $points;
        }

        return $totals;
    }

    private function alreadyCredited(User $student): EventActionRefused
    {
        return new EventActionRefused('already_credited', "Points have already been credited to {$student->name} for this event.", 409);
    }
}
