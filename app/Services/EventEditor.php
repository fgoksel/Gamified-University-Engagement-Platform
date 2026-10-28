<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Notifications\EventStatusChangedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Changing an event after it was created (Task #68, UC-1.3 "Editing an Event").
 *
 * Who may do it is the EventPolicy (the capability at the owning topic). This class
 * decides what may change in which state, so that an edit never hurts people who
 * already applied or were credited:
 *
 *   draft              everything
 *   published/on hold  text, dates and options, with the locks below
 *   completed/cancelled  nothing (history stays as it is)
 *
 * Locks of a published or held event:
 *   managed-from topic           always (authority would move under the people running it)
 *   who may see and apply, and
 *   how students get a place     once anybody has applied
 *   points and the tag           once anybody was credited (everyone gets the same points)
 *   start and application close  once the event has started
 *   surprise setting             always; the reveal time only until it is revealed
 * Places can never drop below the students already accepted.
 *
 * Everything is checked again under a database lock, so an edit and a concurrent
 * application or credit cannot slip past each other.
 */
class EventEditor
{
    /** @var list<string> */
    public const EDITABLE = [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_ON_HOLD];

    public function __construct(private EventPlacement $placement) {}

    public function isEditable(Event $event): bool
    {
        return in_array($event->status, self::EDITABLE, true);
    }

    /**
     * Changes since the form was opened show up as a different version.
     */
    public function version(Event $event): string
    {
        return $event->updated_at?->format('Uu') ?? '0';
    }

    /**
     * Fields that cannot be changed right now, each with the reason shown to the organiser.
     *
     * @return array<string, string>
     */
    public function locks(Event $event): array
    {
        if ($event->status === Event::STATUS_DRAFT) {
            return [];
        }

        $locks = [
            'owning_topic_id' => 'The topic that manages an event cannot change once it is published.',
            'is_hidden' => 'The surprise setting cannot change once the event is published.',
        ];

        $hasApplicants = $event->applications()->where('status', '!=', EventApplication::WITHDRAWN)->exists();

        if ($hasApplicants) {
            $locks['audience_topic_id'] = 'Students have already applied, so who can see and apply cannot change.';
            $locks['application_type'] = 'Students have already applied, so how they get a place cannot change.';
        }

        if ($event->pointTransactions()->where('type', PointTransaction::CREDIT)->exists()) {
            $locks['points_value'] = 'Points were already credited, so the points cannot change.';
            $locks['topic_tag_id'] = 'Points were already credited, so the tag and category cannot change.';
        }

        if ($event->event_starts_at->isPast()) {
            $locks['event_starts_at'] = 'The event has started, so its start cannot move.';
            $locks['application_deadline'] = 'The event has started, so the application deadline cannot move.';
        }

        if (! $event->is_hidden || $event->isRevealed()) {
            $locks['reveal_at'] = 'The event is already visible, so there is no reveal time to change.';
        }

        return $locks;
    }

    /**
     * The fewest places the event may have: the students already accepted.
     */
    public function minimumCapacity(Event $event): int
    {
        return max(1, $event->applications()->where('status', EventApplication::ACCEPTED)->count());
    }

    /**
     * @param  array<string, mixed>  $data  The validated form fields.
     *
     * @throws ValidationException
     */
    public function update(User $actor, Event $event, array $data, ?string $version = null): Event
    {
        $changedTime = false;

        $updated = DB::transaction(function () use ($actor, $event, $data, $version, &$changedTime) {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $this->isEditable($locked)) {
                throw ValidationException::withMessages([
                    'event' => $locked->status === Event::STATUS_CANCELLED
                        ? 'A cancelled event cannot be changed.'
                        : 'A completed event cannot be changed.',
                ]);
            }

            if ($version !== null && $version !== $this->version($locked)) {
                throw ValidationException::withMessages([
                    'event' => 'Someone changed this event while you were editing. Reload the page and make your changes again.',
                ]);
            }

            $attributes = $this->attributes($actor, $locked, $data, $this->locks($locked));

            $minute = fn (CarbonInterface $time) => $time->copy()->startOfMinute();
            $changedTime = $locked->status !== Event::STATUS_DRAFT
                && ($minute($attributes['event_starts_at'])->ne($minute($locked->event_starts_at))
                    || $minute($attributes['event_ends_at'])->ne($minute($locked->event_ends_at)));

            $locked->update($attributes);

            return $locked;
        });

        if ($changedTime) {
            $this->notifyApplicants($updated);
        }

        return $updated;
    }

    /**
     * Check the submitted fields against the locks and the rules, and return what to store.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $locks
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function attributes(User $actor, Event $event, array $data, array $locks): array
    {
        $errors = [];
        $draft = $event->status === Event::STATUS_DRAFT;

        $date = fn (string $field): CarbonInterface => Carbon::parse($data[$field]);
        // A date that is left as it was is never "wrong": the form only holds minutes, and
        // an event whose applications already closed must still be editable before it starts.
        $unchanged = fn (string $field, CarbonInterface $value): bool => $event->{$field}->copy()->startOfMinute()->equalTo($value->copy()->startOfMinute());
        $keeps = function (string $field, mixed $value) use ($event, $locks, &$errors): bool {
            if (! isset($locks[$field])) {
                return false;
            }

            // The form's date fields only hold minutes; seconds stored by other routes do not count.
            $current = $event->{$field};
            $same = $current instanceof CarbonInterface
                ? ($value !== null && Carbon::parse($value)->startOfMinute()->equalTo($current->copy()->startOfMinute()))
                : $value == $current;

            if (! $same) {
                $errors[$field] = $locks[$field];
            }

            return true;
        };

        $owningId = $keeps('owning_topic_id', $data['owning_topic_id']) ? $event->owning_topic_id : (int) $data['owning_topic_id'];
        $owning = Topic::find($owningId);

        if ($owning === null || (! isset($locks['owning_topic_id']) && $owningId !== $event->owning_topic_id && ! $this->placement->mayOwn($actor, $owning))) {
            $errors['owning_topic_id'] ??= 'You cannot manage events at this topic.';
        }

        $audienceId = $data['audience_topic_id'] ?? null;
        if ($keeps('audience_topic_id', $audienceId)) {
            $audienceId = $event->audience_topic_id;
        } elseif ($audienceId !== null && $owning !== null && ! in_array((int) $audienceId, $this->placement->audienceIds($owning), true)) {
            $errors['audience_topic_id'] = 'The audience must be the owning topic or a topic above it.';
        }

        $type = $keeps('application_type', $data['application_type']) ? $event->application_type : $data['application_type'];
        $points = $keeps('points_value', (int) $data['points_value']) ? $event->points_value : (int) $data['points_value'];

        $tagId = $keeps('topic_tag_id', (int) $data['topic_tag_id']) ? $event->topic_tag_id : (int) $data['topic_tag_id'];
        $tag = TopicTag::query()->whereKey($tagId)->first();
        if ($tag === null || (! $tag->is_active && $tagId !== $event->topic_tag_id)) {
            $errors['topic_tag_id'] = 'This topic tag is no longer available.';
        }

        $capacity = (int) $data['capacity'];
        $accepted = $this->minimumCapacity($event);
        if ($capacity < $accepted) {
            $errors['capacity'] = $accepted === 1 && $event->applications()->where('status', EventApplication::ACCEPTED)->doesntExist()
                ? 'There must be at least one place.'
                : "{$accepted} students are already accepted, so there cannot be fewer places.";
        }

        $starts = $keeps('event_starts_at', $data['event_starts_at']) ? $event->event_starts_at : $date('event_starts_at');
        $ends = $date('event_ends_at');
        $applyBy = $keeps('application_deadline', $data['application_deadline']) ? $event->application_deadline : $date('application_deadline');
        $completeBy = $date('completion_deadline');

        if (! isset($locks['event_starts_at']) && $starts->lte(now()) && ! $unchanged('event_starts_at', $starts)) {
            $errors['event_starts_at'] = 'The event must start in the future.';
        }
        if ($ends->lte($starts)) {
            $errors['event_ends_at'] = 'The event must end after it starts.';
        }
        if (! isset($locks['application_deadline']) && $applyBy->lte(now()) && ! $unchanged('application_deadline', $applyBy)) {
            $errors['application_deadline'] = 'The application deadline must be in the future.';
        }
        if ($applyBy->gt($starts)) {
            $errors['application_deadline'] ??= 'Applications must close before the event starts.';
        }
        if ($completeBy->lt($ends)) {
            $errors['completion_deadline'] = 'The completion deadline cannot be before the event ends.';
        }

        $hidden = $keeps('is_hidden', (bool) ($data['is_hidden'] ?? false)) ? $event->is_hidden : (bool) ($data['is_hidden'] ?? false);
        $revealAt = $event->reveal_at;
        if ($hidden && ! $keeps('reveal_at', $data['reveal_at'] ?? null)) {
            $revealAt = isset($data['reveal_at']) ? $date('reveal_at') : null;

            if ($revealAt === null) {
                $errors['reveal_at'] = 'Choose when a hidden event is revealed.';
            } elseif ($revealAt->lte(now())) {
                $errors['reveal_at'] = 'The reveal time must be in the future.';
            } elseif ($revealAt->gt($starts)) {
                $errors['reveal_at'] = 'A hidden event must be revealed before it starts.';
            }
        } elseif (! $hidden) {
            $revealAt = null;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'topic_tag_id' => $tagId,
            'category' => $tag->category,
            'owning_topic_id' => $owningId,
            'audience_topic_id' => $audienceId === null ? null : (int) $audienceId,
            'capacity' => $capacity,
            'application_type' => $type,
            'points_value' => $points,
            'event_starts_at' => $starts,
            'event_ends_at' => $ends,
            'application_deadline' => $applyBy,
            'completion_deadline' => $completeBy,
            'is_hidden' => $hidden,
            'reveal_at' => $revealAt,
            'qr_enabled' => (bool) ($data['qr_enabled'] ?? false),
        ];
    }

    /**
     * The students who still have an application hear about the new time.
     */
    private function notifyApplicants(Event $event): void
    {
        $students = User::query()
            ->whereIn('id', $event->applications()
                ->whereIn('status', [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED])
                ->select('student_id'))
            ->get();

        Notification::send($students, new EventStatusChangedNotification(
            EventStatusChangedNotification::RESCHEDULED,
            $event->id,
            $event->title,
        ));
    }
}
