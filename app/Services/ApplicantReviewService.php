<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationTransition;
use App\Models\PointTransaction;
use App\Models\User;
use App\Notifications\EventApplicationDecisionNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an organizer does with the applicants of an event (Task #41, UC-1.6).
 *
 * Authority comes from the event's owning topic. Until the event capabilities
 * of Task #65 exist, "may add topics here" is used, exactly as EventPlacement
 * does for creating events; swap MANAGE_CAPABILITY when #65 is merged.
 */
class ApplicantReviewService
{
    /**
     * The capability required to review applicants (Task #41 & #65).
     * Uses `applications.manage` from Task #65 when available,
     * falling back to `topic.create` while Task #65 is pending merge.
     */
    public const MANAGE_CAPABILITY = Capability::Create;

    public static function manageCapability(): Capability
    {
        return Capability::tryFrom('applications.manage') ?? self::MANAGE_CAPABILITY;
    }

    /** Applications that are still open to an organizer's decision. */
    private const OPEN = [
        EventApplication::PENDING,
        EventApplication::ACCEPTED,
        EventApplication::WAITLISTED,
    ];

    public function __construct(private TopicAccess $access) {}

    public function mayReview(User $user, Event $event): bool
    {
        $owning = $event->owningTopic;

        if ($owning === null || ! $this->access->isActive($user) || $this->access->isHidden($owning)) {
            return false;
        }

        $required = self::manageCapability();

        return $this->access->can($user, $required, $owning)
            || ($required !== Capability::Create && $this->access->can($user, Capability::Create, $owning));
    }

    /**
     * Applicants in arrival order, with each student's points per category.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function applicants(Event $event): Collection
    {
        $applications = EventApplication::query()
            ->where('event_id', $event->id)
            ->with('student:id,name,neptun_code')
            ->orderBy('applied_at')
            ->orderBy('id')
            ->get();

        $points = PointTransaction::query()
            ->whereIn('student_id', $applications->pluck('student_id'))
            ->selectRaw('student_id, category, SUM(points) as total')
            ->groupBy('student_id', 'category')
            ->get()
            ->groupBy('student_id');

        return $applications->map(function (EventApplication $application) use ($points) {
            $byCategory = ['academic' => 0, 'scientific' => 0, 'sports' => 0, 'community' => 0];
            foreach ($points->get($application->student_id, []) as $row) {
                $byCategory[$row->category] = (int) $row->total;
            }

            return [
                'id' => $application->id,
                'status' => $application->status,
                'applied_at' => $application->applied_at?->toIso8601String(),
                'is_credited' => $application->isCredited(),
                'student' => [
                    'id' => $application->student_id,
                    'name' => $application->student?->name,
                    'neptun_code' => $application->student?->neptun_code,
                ],
                'points' => [...$byCategory, 'total' => array_sum($byCategory)],
            ];
        })->values();
    }

    /**
     * Save the ticked candidates in one go (selection mode): ticked open
     * applications become accepted, unticked accepted ones go back to pending.
     * More ticks than seats needs $expandCapacity, which raises the capacity to
     * the number selected (capacity is only ever increased, D08).
     *
     * @param  list<int>  $selectedIds  application ids
     * @return array{accepted: int, unselected: int, capacity: int}
     *
     * @throws ValidationException
     */
    public function saveSelection(User $actor, Event $event, array $selectedIds, bool $expandCapacity): array
    {
        $notify = [];

        $result = DB::transaction(function () use ($actor, $event, $selectedIds, $expandCapacity, &$notify) {
            $locked = $this->lockedEvent($event);

            if ($locked->application_type !== 'teacher_select') {
                throw ValidationException::withMessages([
                    'event' => 'Selections can only be saved for events in selection mode.',
                ]);
            }

            $applications = EventApplication::query()
                ->where('event_id', $locked->id)
                ->whereIn('status', self::OPEN)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $selected = collect($selectedIds)->map(fn ($id) => (int) $id)->unique()->values();

            if ($selected->contains(fn (int $id) => ! $applications->has($id))) {
                throw ValidationException::withMessages([
                    'selected' => 'Some selected applications cannot be chosen any more. Reload the page.',
                ]);
            }

            // A credited attendance can never be taken back.
            $credited = $applications->filter(fn (EventApplication $a) => $a->isCredited())->keys();
            $selected = $selected->merge($credited)->unique()->values();

            $capacity = $locked->capacity;

            if ($selected->count() > $capacity) {
                if (! $expandCapacity) {
                    throw ValidationException::withMessages([
                        'capacity' => 'The number of selected students exceeds the maximum capacity.',
                    ]);
                }

                $capacity = $selected->count();
                $locked->update(['capacity' => $capacity]);
            }

            $accepted = 0;
            $unselected = 0;

            foreach ($applications as $id => $application) {
                $isSelected = $selected->contains($id);

                if ($isSelected && $application->status !== EventApplication::ACCEPTED) {
                    $this->move($application, EventApplication::ACCEPTED, $actor, 'Selected by organizer');
                    $application->update(['decided_by_id' => $actor->id, 'decided_at' => now()]);
                    $notify[] = [$application->student_id, EventApplicationDecisionNotification::ACCEPTED];
                    $accepted++;
                } elseif (! $isSelected && $application->status === EventApplication::ACCEPTED) {
                    $this->move($application, EventApplication::PENDING, $actor, 'Removed from selection by organizer');
                    $application->update(['decided_by_id' => null, 'decided_at' => null]);
                    $unselected++;
                }
            }

            return ['accepted' => $accepted, 'unselected' => $unselected, 'capacity' => $capacity];
        });

        $this->notify($event, $notify);

        return $result;
    }

    /**
     * Reject one applicant. Rejection is final for the semester (D07).
     *
     * @throws ValidationException
     */
    public function reject(User $actor, Event $event, EventApplication $application): EventApplication
    {
        $notify = [];

        $rejected = DB::transaction(function () use ($actor, $event, $application, &$notify) {
            $this->lockedEvent($event);

            /** @var EventApplication $locked */
            $locked = EventApplication::query()
                ->whereKey($application->id)
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, self::OPEN, true)) {
                throw ValidationException::withMessages([
                    'application' => 'This application can no longer be rejected.',
                ]);
            }

            if ($locked->isCredited()) {
                throw ValidationException::withMessages([
                    'application' => 'Points were already credited for this student; the application cannot be rejected.',
                ]);
            }

            $this->move($locked, EventApplication::REJECTED, $actor, 'Rejected by organizer');
            $locked->update(['decided_by_id' => $actor->id, 'decided_at' => now()]);
            $notify[] = [$locked->student_id, EventApplicationDecisionNotification::REJECTED];

            return $locked;
        });

        $this->notify($event, $notify);

        return $rejected;
    }

    private function lockedEvent(Event $event): Event
    {
        /** @var Event $locked */
        $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== Event::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'event' => 'Applicants can only be reviewed while the event is published.',
            ]);
        }

        return $locked;
    }

    private function move(EventApplication $application, string $to, User $actor, string $reason): void
    {
        $from = $application->status;
        $application->update(['status' => $to]);

        EventApplicationTransition::create([
            'event_application_id' => $application->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Queued notifications go out only after the transaction has committed.
     *
     * @param  list<array{0: int, 1: string}>  $notify
     */
    private function notify(Event $event, array $notify): void
    {
        foreach ($notify as [$studentId, $decision]) {
            User::find($studentId)?->notify(
                new EventApplicationDecisionNotification($decision, $event->id, $event->title)
            );
        }
    }
}
