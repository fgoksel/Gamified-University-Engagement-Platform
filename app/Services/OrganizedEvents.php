<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The "Organized by me" list (Task #37, UC-1.3): every event owned at a topic
 * where the person holds an event staff capability (Task #65), whoever created
 * it (Task #64). Which buttons they get is decided per event by EventPolicy.
 */
class OrganizedEvents
{
    /** The tabs of the page, in order. */
    public const FILTERS = ['upcoming', 'drafts', 'past', 'on_hold', 'cancelled'];

    /** Any of these at the owning topic makes the person staff of its events (EventPolicy::staff). */
    private const STAFF = [
        Capability::EventsCreate,
        Capability::EventsManage,
        Capability::ApplicationsManage,
        Capability::PointsCredit,
        Capability::QrCodesManage,
    ];

    /** @var array<int, list<int>> */
    private array $staffTopicIds = [];

    public function __construct(private TopicAccess $access) {}

    /**
     * @return list<int>
     */
    public function staffTopicIds(User $user): array
    {
        return $this->staffTopicIds[$user->id] ??= $this->access->visibleTopics($user)
            ->notArchived()
            ->get()
            ->filter(fn (Topic $topic) => collect(self::STAFF)
                ->contains(fn (Capability $capability) => $this->access->can($user, $capability, $topic)))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @return Builder<Event>
     */
    public function query(User $user): Builder
    {
        return Event::query()->whereIn('owning_topic_id', $this->staffTopicIds($user));
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

        $gate = Gate::forUser($user);

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
            // What this person may do here (Task #65): buttons follow their capabilities.
            'can_manage' => $gate->allows('manage', $event),
            'can_review' => $gate->allows('manageApplicants', $event),
            'can_credit' => $gate->allows('credit', $event),
            'can_qr' => $gate->allows('manageQr', $event),
        ])->all();
    }
}
