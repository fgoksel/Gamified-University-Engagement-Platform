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
 *   start                        once the event has started
 *   surprise setting             always (it stays hidden, and its points are given by import)
 * Places can never drop below the students already accepted.
 *
 * Students who still have an application are told when the time or the location
 * changes (Task #77): one message, even when both change in the same save.
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
        $change = null;

        $updated = DB::transaction(function () use ($actor, $event, $data, $version, &$change) {
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
            $draft = $locked->status === Event::STATUS_DRAFT;
            $changedTime = ! $draft
                && ($minute($attributes['event_starts_at'])->ne($minute($locked->event_starts_at))
                    || $minute($attributes['event_ends_at'])->ne($minute($locked->event_ends_at)));
            // Set, changed or removed: applicants may have to go somewhere else.
            $changedLocation = ! $draft && $attributes['location'] !== $locked->location;

            $change = match (true) {
                $changedTime && $changedLocation => EventStatusChangedNotification::RESCHEDULED_AND_RELOCATED,
                $changedTime => EventStatusChangedNotification::RESCHEDULED,
                $changedLocation => EventStatusChangedNotification::RELOCATED,
                default => null,
            };

            $locked->update($attributes);

            return $locked;
        });

        if ($change !== null) {
            $this->notifyApplicants($updated, $change);
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
        if ($applyBy->lte(now()) && ! $unchanged('application_deadline', $applyBy)) {
            $errors['application_deadline'] = 'The application deadline must be in the future.';
        }
        // Applications may stay open while the event runs, but not after it (Task #78).
        if ($applyBy->gt($ends)) {
            $errors['application_deadline'] ??= 'Applications must close before the event ends.';
        }

        // A page opened before this field existed leaves it out: keep what is stored.
        $opensAt = $event->applications_open_at;
        if (array_key_exists('applications_open_at', $data)) {
            $opensAt = ($data['applications_open_at'] ?? null) === null ? null : $date('applications_open_at');
        }
        if ($opensAt !== null && $opensAt->gte($applyBy)) {
            $errors['applications_open_at'] = 'Applications must open before they close.';
        }
        if ($completeBy->lt($ends)) {
            $errors['completion_deadline'] = 'The completion deadline cannot be before the event ends.';
        }

        $hidden = $keeps('is_hidden', (bool) ($data['is_hidden'] ?? false)) ? $event->is_hidden : (bool) ($data['is_hidden'] ?? false);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Free text that may stay empty (Task #77). Left out of the request, for example by a
        // page opened before the field existed, it keeps its value instead of being wiped.
        $location = array_key_exists('location', $data)
            ? (filled($data['location']) ? trim($data['location']) : null)
            : $event->location;

        return [
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'location' => $location,
            'topic_tag_id' => $tagId,
            'category' => $tag->category,
            'owning_topic_id' => $owningId,
            'audience_topic_id' => $audienceId === null ? null : (int) $audienceId,
            'capacity' => $capacity,
            'application_type' => $type,
            'points_value' => $points,
            'event_starts_at' => $starts,
            'event_ends_at' => $ends,
            'applications_open_at' => $opensAt,
            'application_deadline' => $applyBy,
            'completion_deadline' => $completeBy,
            'is_hidden' => $hidden,
            // A surprise event has no QR codes: it is credited by import (Task #86).
            'qr_enabled' => ! $hidden && (bool) ($data['qr_enabled'] ?? false),
        ];
    }

    /**
     * The students who still have an application hear about the new time or location.
     */
    private function notifyApplicants(Event $event, string $change): void
    {
        $students = User::query()
            ->whereIn('id', $event->applications()
                ->whereIn('status', [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED])
                ->select('student_id'))
            ->get();

        Notification::send($students, new EventStatusChangedNotification(
            $change,
            $event->id,
            $event->title,
            location: $event->location,
        ));
    }
}
