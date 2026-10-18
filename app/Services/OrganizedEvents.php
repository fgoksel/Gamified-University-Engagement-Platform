<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Organized by me" list (Task #37, UC-1.3): every event owned at a topic
 * where the person may create events, whoever created it (Task #64).
 */
class OrganizedEvents
{
    /** The tabs of the page, in order. */
    public const FILTERS = ['upcoming', 'drafts', 'past', 'on_hold', 'cancelled'];

    public function __construct(private EventPlacement $placement) {}

    /**
     * @return Builder<Event>
     */
    public function query(User $user): Builder
    {
        return Event::query()->whereIn(
            'owning_topic_id',
            $this->placement->owningTopics($user)->pluck('id'),
        );
    }

    /**
     * @param  Builder<Event>  $query
     */
    private function applyFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'upcoming' => $query->where('status', Event::STATUS_PUBLISHED)->where('event_ends_at', '>=', now()),
            'drafts' => $query->where('status', Event::STATUS_DRAFT),
            'past' => $query->where(fn (Builder $q) => $q
                ->where('status', Event::STATUS_COMPLETED)
                ->orWhere(fn (Builder $q) => $q->where('status', Event::STATUS_PUBLISHED)->where('event_ends_at', '<', now()))),
            'on_hold' => $query->where('status', Event::STATUS_ON_HOLD),
            'cancelled' => $query->where('status', Event::STATUS_CANCELLED),
        };
    }

    /**
     * Number of events behind each tab.
     *
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        return collect(self::FILTERS)->mapWithKeys(function (string $filter) use ($user) {
            $query = $this->query($user);
            $this->applyFilter($query, $filter);

            return [$filter => $query->count()];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $user, string $filter): array
    {
        $query = $this->query($user)
            ->with(['owningTopic:id,title', 'audienceTopic:id,title', 'tag:id,name'])
            ->withCount([
                'applications as accepted_count' => fn (Builder $q) => $q->where('status', EventApplication::ACCEPTED),
                'applications as waiting_count' => fn (Builder $q) => $q->whereIn('status', [EventApplication::PENDING, EventApplication::WAITLISTED]),
                'applications',
            ]);

        $this->applyFilter($query, $filter);

        // Soonest first while it is still ahead, most recent first afterwards.
        in_array($filter, ['past', 'cancelled'], true)
            ? $query->orderByDesc('event_starts_at')
            : $query->orderBy('event_starts_at');

        return $query->get()->map(fn (Event $event) => [
            'id' => $event->id,
            'title' => $event->title,
            'status' => $event->status,
            'category' => $event->category,
            'tag' => $event->tag?->name,
            'owning_topic' => $event->owningTopic?->title,
            'audience_topic' => $event->audienceTopic?->title,
            // Formatted here: the dates are stored as typed, so the browser must not shift them.
            'starts' => $event->event_starts_at->format('D j M Y, H:i'),
            'ends' => $event->event_ends_at->format('H:i'),
            'applications_close' => $event->application_deadline->format('j M, H:i'),
            'capacity' => $event->capacity,
            'accepted' => $event->accepted_count,
            'waiting' => $event->waiting_count,
            'points' => $event->points_value,
            'application_type' => $event->application_type,
            'is_hidden' => $event->is_hidden,
            'reveal_at' => $event->reveal_at?->format('j M, H:i'),
            'qr_enabled' => $event->qr_enabled,
            'cancellation_reason' => $event->cancellation_reason,
            // An event with applicants is never deleted; it can be cancelled instead.
            'can_delete' => $event->applications_count === 0,
        ])->all();
    }
}
