<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventEdit;
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
 *   over               only the points end date (Task #76): a student who missed the scan
 *                      asks the organiser, who extends it by editing the event (26 Oct, Q4).
 *                      "Over" is the Past tab's rule: completed, or published and ended.
 *   cancelled          nothing (history stays as it is)
 *
 * Every save that changes something is logged in event_edits (Task #76): who, when, and
 * each changed field's old and new value.
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
    public const EDITABLE = [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_ON_HOLD, Event::STATUS_COMPLETED];

    /** The fields of the form. A completed event keeps all of them but the points end date. */
    public const FIELDS = [
        'title', 'description', 'location', 'topic_tag_id', 'owning_topic_id', 'audience_topic_id',
        'capacity', 'application_type', 'points_value', 'event_starts_at', 'event_ends_at',
        'applications_open_at', 'application_deadline', 'completion_deadline', 'is_hidden', 'qr_enabled',
    ];

    public function __construct(private EventPlacement $placement) {}

    /**
     * The event is over (Task #76): marked completed, or published and already ended. The same
     * rule as the "Past" tab of Organized by me (OrganizedEvents). Only the points end date of
     * such an event may change.
     */
    public function isOver(Event $event): bool
    {
        return $event->status === Event::STATUS_COMPLETED
            || ($event->status === Event::STATUS_PUBLISHED && $event->event_ends_at->isPast());
    }

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

        // The page explains it once above the form, so the fields carry no hint of their own.
        if ($this->isOver($event)) {
            return collect(self::FIELDS)
                ->reject(fn (string $field) => $field === 'completion_deadline')
                ->mapWithKeys(fn (string $field) => [$field => ''])
                ->all();
        }

        $locks = [
            'owning_topic_id' => __('events.editor.the_area_that_manages'),
            'is_hidden' => __('events.editor.the_surprise_setting_cannot_change'),
        ];

        $hasApplicants = $event->applications()->where('status', '!=', EventApplication::WITHDRAWN)->exists();

        if ($hasApplicants) {
            $locks['audience_topic_id'] = __('events.editor.students_have_already_applied_so');
            $locks['application_type'] = __('events.editor.students_have_already_applied_so_2');
        }

        if ($event->pointTransactions()->where('type', PointTransaction::CREDIT)->exists()) {
            $locks['points_value'] = __('events.editor.points_were_already_credited_so');
            $locks['topic_tag_id'] = __('events.editor.points_were_already_credited_so_2');
        }

        if ($event->event_starts_at->isPast()) {
            $locks['event_starts_at'] = __('events.editor.the_event_has_started_so');
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
                    'event' => __('events.editor.a_cancelled_event_cannot'),
                ]);
            }

            if ($version !== null && $version !== $this->version($locked)) {
                throw ValidationException::withMessages([
                    'event' => __('events.editor.someone_changed_this_event_while'),
                ]);
            }

            if ($this->isOver($locked)) {
                // Only the points end date moves; nobody needs to hear about it but the student who asked.
                $attributes = $this->completedAttributes($locked, $data);
            } else {
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
            }

            // Worked out before saving, while the old values are still there (Task #76).
            $changes = $this->changes($locked, $attributes);

            $locked->update($attributes);

            if ($changes !== []) {
                EventEdit::create([
                    'event_id' => $locked->id,
                    'actor_id' => $actor->id,
                    'changes' => $changes,
                    'created_at' => now(),
                ]);
            }

            return $locked;
        });

        if ($change !== null) {
            $this->notifyApplicants($updated, $change);
        }

        return $updated;
    }

    /**
     * An event that is over (Task #76): only the points end date may move, so a student who missed
     * the scan can still get the points. Every other field must come back as it is stored.
     *
     * @param  array<string, mixed>  $data
     * @return array{completion_deadline: CarbonInterface}
     *
     * @throws ValidationException
     */
    private function completedAttributes(Event $event, array $data): array
    {
        $errors = [];

        foreach (array_keys($this->locks($event)) as $field) {
            // A page opened before a field existed leaves it out: nothing was changed there.
            if (array_key_exists($field, $data) && $this->differs($event->{$field}, $data[$field])) {
                $errors[$field] = __('events.editor.over_only_points_until');
            }
        }

        $completeBy = Carbon::parse($data['completion_deadline']);

        if ($completeBy->lt($event->event_ends_at)) {
            $errors['completion_deadline'] = __('events.editor.deadline_before_end');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['completion_deadline' => $completeBy];
    }

    /**
     * Each field that the save changes, as {field: {old, new}} (Task #76). Dates count by the
     * minute, like the form, so seconds stored by other routes never show up as an edit.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(Event $event, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $field => $new) {
            $old = $event->{$field};

            if ($this->differs($old, $new)) {
                $changes[$field] = ['old' => $this->plain($old), 'new' => $this->plain($new)];
            }
        }

        return $changes;
    }

    /**
     * Whether a stored value and a submitted or new one differ. Dates by the minute, empty
     * text as no value, numbers and booleans by value.
     */
    private function differs(mixed $stored, mixed $value): bool
    {
        if ($stored instanceof CarbonInterface || $value instanceof CarbonInterface) {
            if (blank($stored) || blank($value)) {
                return blank($stored) !== blank($value);
            }

            return ! Carbon::parse($stored)->startOfMinute()->equalTo(Carbon::parse($value)->startOfMinute());
        }

        if (is_bool($stored)) {
            return $stored !== filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $stored = is_string($stored) ? trim($stored) : $stored;
        $value = is_string($value) ? trim($value) : $value;

        if (blank($stored) || blank($value)) {
            return blank($stored) !== blank($value);
        }

        return (string) $stored !== (string) $value;
    }

    /**
     * A value as the log keeps it: dates to the minute, everything else as it is.
     */
    private function plain(mixed $value): mixed
    {
        return $value instanceof CarbonInterface ? $value->format('Y-m-d H:i') : $value;
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
            $errors['owning_topic_id'] ??= __('events.editor.you_cannot_manage_events');
        }

        $audienceId = $data['audience_topic_id'] ?? null;
        if ($keeps('audience_topic_id', $audienceId)) {
            $audienceId = $event->audience_topic_id;
        } elseif ($audienceId !== null && $owning !== null && ! in_array((int) $audienceId, $this->placement->audienceIds($owning), true)) {
            $errors['audience_topic_id'] = __('events.editor.the_audience_must');
        }

        $type = $keeps('application_type', $data['application_type']) ? $event->application_type : $data['application_type'];
        $points = $keeps('points_value', (int) $data['points_value']) ? $event->points_value : (int) $data['points_value'];

        $tagId = $keeps('topic_tag_id', (int) $data['topic_tag_id']) ? $event->topic_tag_id : (int) $data['topic_tag_id'];
        $tag = TopicTag::query()->whereKey($tagId)->first();
        if ($tag === null || (! $tag->is_active && $tagId !== $event->topic_tag_id)) {
            $errors['topic_tag_id'] = __('events.editor.this_topic_tag');
        }

        $capacity = (int) $data['capacity'];
        $accepted = $this->minimumCapacity($event);
        if ($capacity < $accepted) {
            $errors['capacity'] = $accepted === 1 && $event->applications()->where('status', EventApplication::ACCEPTED)->doesntExist()
                ? __('events.editor.there_must_be_at_least')
                : __('events.editor.students_accepted', ['count' => $accepted]);
        }

        $starts = $keeps('event_starts_at', $data['event_starts_at']) ? $event->event_starts_at : $date('event_starts_at');
        $ends = $date('event_ends_at');
        $applyBy = $keeps('application_deadline', $data['application_deadline']) ? $event->application_deadline : $date('application_deadline');
        $completeBy = $date('completion_deadline');

        if (! isset($locks['event_starts_at']) && $starts->lte(now()) && ! $unchanged('event_starts_at', $starts)) {
            $errors['event_starts_at'] = __('events.editor.the_event_must_start');
        }
        if ($ends->lte($starts)) {
            $errors['event_ends_at'] = __('events.editor.the_event_must_end_after');
        }
        if ($applyBy->lte(now()) && ! $unchanged('application_deadline', $applyBy)) {
            $errors['application_deadline'] = __('events.editor.the_application_deadline_must');
        }
        // Applications may stay open while the event runs, but not after it (Task #78).
        if ($applyBy->gt($ends)) {
            $errors['application_deadline'] ??= __('events.editor.applications_must_close_before');
        }

        // A page opened before this field existed leaves it out: keep what is stored.
        $opensAt = $event->applications_open_at;
        if (array_key_exists('applications_open_at', $data)) {
            $opensAt = ($data['applications_open_at'] ?? null) === null ? null : $date('applications_open_at');
        }
        if ($opensAt !== null && $opensAt->gte($applyBy)) {
            $errors['applications_open_at'] = __('events.editor.applications_must_open_before_they');
        }
        if ($completeBy->lt($ends)) {
            $errors['completion_deadline'] = __('events.editor.the_completion_deadline_cannot');
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
