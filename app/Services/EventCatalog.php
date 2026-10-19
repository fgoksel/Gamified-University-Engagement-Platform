<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\TaxonomyCategory;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * "All events" (Task #40, UC-1.5 / UC-2.3): the published events a person may see.
 *
 * The query follows EventPolicy::view so the list never shows more than the
 * policy allows:
 *   - announced to an audience topic where the person holds events.view
 *     (and, for a surprise event, already revealed), or
 *   - owned at a topic where the person is event staff (staff see surprises early).
 * Drafts, events on hold and cancelled events never appear here; organisers
 * find those under "Organized by me".
 */
class EventCatalog
{
    public const WHEN = ['upcoming', 'past'];

    public const PER_PAGE = 12;

    public function __construct(
        private TopicAccess $access,
        private OrganizedEvents $organized,
    ) {}

    /**
     * Topics where the person may see the events announced there.
     *
     * @return list<int>
     */
    public function audienceTopicIds(User $user): array
    {
        return $this->access->visibleTopics($user)
            ->notArchived()
            ->get()
            ->filter(fn (Topic $topic) => $this->access->can($user, Capability::EventsView, $topic))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @return Builder<Event>
     */
    public function query(User $user): Builder
    {
        $audience = $this->audienceTopicIds($user);
        $staff = $this->organized->staffTopicIds($user);

        return Event::query()
            ->where('status', Event::STATUS_PUBLISHED)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->whereIn('audience_topic_id', $audience)
                    ->where(fn (Builder $q) => $q
                        ->where('is_hidden', false)
                        ->orWhere('reveal_at', '<=', now())))
                ->orWhereIn('owning_topic_id', $staff));
    }

    /**
     * @param  array{q?: ?string, category?: ?string, when?: ?string}  $filters
     */
    public function page(User $user, array $filters): LengthAwarePaginator
    {
        $when = in_array($filters['when'] ?? null, self::WHEN, true) ? $filters['when'] : 'upcoming';
        $category = in_array($filters['category'] ?? null, array_column(TaxonomyCategory::cases(), 'value'), true)
            ? $filters['category']
            : null;
        $search = trim((string) ($filters['q'] ?? ''));

        $query = $this->query($user)
            ->with(['owningTopic:id,title', 'audienceTopic:id,title', 'tag:id,name'])
            ->withCount(['applications as accepted_count' => fn (Builder $q) => $q->where('status', EventApplication::ACCEPTED)])
            ->when($category, fn (Builder $q) => $q->where('category', $category))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('description', 'like', '%'.addcslashes($search, '%_\\').'%')));

        $when === 'past'
            ? $query->where('event_ends_at', '<', now())->orderByDesc('event_starts_at')
            : $query->where('event_ends_at', '>=', now())->orderBy('event_starts_at');

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        // The person's own application on each event shown, in one query.
        $mine = EventApplication::query()
            ->where('student_id', $user->id)
            ->whereIn('event_id', $page->getCollection()->pluck('id'))
            ->pluck('status', 'event_id');

        $gate = Gate::forUser($user);

        return $page->through(fn (Event $event) => [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'category' => $event->category,
            'tag' => $event->tag?->name,
            'owning_topic' => $event->owningTopic?->title,
            'audience_topic' => $event->audienceTopic?->title,
            // Formatted here: the dates are stored as typed, so the browser must not shift them.
            'starts' => $event->event_starts_at->format('D j M Y, H:i'),
            'ends' => $event->event_ends_at->format('H:i'),
            'applications_close' => $event->application_deadline->format('j M, H:i'),
            'capacity' => $event->capacity,
            'places_left' => max(0, $event->capacity - $event->accepted_count),
            'application_type' => $event->application_type,
            'points' => $event->points_value,
            'qr_enabled' => $event->qr_enabled,
            // A surprise event is shown to staff before it is revealed.
            'is_surprise' => $event->is_hidden,
            'is_open' => $this->isOpen($event),
            'my_status' => $mine[$event->id] ?? null,
            // Used by the Apply button (Task #49).
            'can_apply' => $this->isOpen($event)
                && ! in_array($mine[$event->id] ?? null, [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED, EventApplication::REJECTED], true)
                && $gate->allows('apply', $event),
        ]);
    }

    /**
     * Still taking applications: the same timing rules as EventApplicationService.
     */
    private function isOpen(Event $event): bool
    {
        if (now()->gte($event->event_starts_at)) {
            return false;
        }

        // A revealed surprise event skips the application deadline (Decision Record D02).
        return $event->is_hidden || now()->lte($event->application_deadline);
    }
}
