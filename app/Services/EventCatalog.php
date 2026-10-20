<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\TaxonomyCategory;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Topic;
use App\Models\User;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "All events" (Task #40, UC-1.5 / UC-2.3): the published events a person may see,
 * filtered by topic or branch, category and date, plus each event's detail page.
 *
 * The query follows EventPolicy::view so the list never shows more than the
 * policy allows:
 *   - announced to an audience topic where the person holds events.view
 *     (and, for a surprise event, already revealed), or
 *   - owned at a topic where the person is event staff (staff see surprises early).
 * Drafts, events on hold and cancelled events never appear here; organisers
 * find those under "Organized by me". The detail page uses the policy itself.
 */
class EventCatalog
{
    /** "any" = no upcoming/past limit, e.g. when a date range is chosen. */
    public const WHEN = ['upcoming', 'past', 'any'];

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
     * The topics offered in the "Topic or branch" filter: every topic the person
     * can see events in, in tree order. Picking one shows the events run or
     * announced anywhere in that branch.
     *
     * @return list<array{id: int, title: string, depth: int}>
     */
    public function topicOptions(User $user): array
    {
        $ids = array_values(array_unique([
            ...$this->audienceTopicIds($user),
            ...$this->organized->staffTopicIds($user),
        ]));

        $topics = Topic::query()->whereIn('id', $ids)->get(['id', 'title', 'depth'])->keyBy('id');

        // Tree order: sort by the titles on the way down from the root, so every
        // topic is listed right under its parent ("University › Faculty › Course").
        $paths = DB::table('topic_closure')
            ->join('topics', 'topics.id', '=', 'topic_closure.ancestor_id')
            ->whereIn('topic_closure.descendant_id', $ids)
            ->orderByDesc('topic_closure.distance')
            ->get(['topic_closure.descendant_id', 'topics.title'])
            ->groupBy('descendant_id')
            ->map(fn ($rows) => $rows->pluck('title')->map(fn ($t) => mb_strtolower($t))->implode("\u{1}"));

        return $topics
            ->sortBy(fn (Topic $topic) => $paths[$topic->id] ?? '', SORT_STRING)
            ->map(fn (Topic $topic) => ['id' => $topic->id, 'title' => $topic->title, 'depth' => $topic->depth])
            ->values()
            ->all();
    }

    /**
     * Clean the filters from the address bar: unknown values are dropped, never errors.
     *
     * @param  array<string, mixed>  $input
     * @return array{q: string, category: ?string, when: string, topic: ?int, from: ?string, to: ?string}
     */
    public function filters(User $user, array $input): array
    {
        $date = function ($value): ?string {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            try {
                $parsed = Carbon::createFromFormat('!Y-m-d', $value);
            } catch (\Throwable) {
                return null;
            }

            // Rejects dates that roll over, such as 2026-02-31.
            return $parsed && $parsed->format('Y-m-d') === $value ? $value : null;
        };

        $from = $date($input['from'] ?? null);
        $to = $date($input['to'] ?? null);
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $topic = (int) ($input['topic'] ?? 0);
        $allowedTopics = array_column($this->topicOptions($user), 'id');

        $when = in_array($input['when'] ?? null, self::WHEN, true) ? $input['when'] : null;

        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'category' => in_array($input['category'] ?? null, array_column(TaxonomyCategory::cases(), 'value'), true)
                ? $input['category']
                : null,
            // A chosen date range replaces the upcoming/past default.
            'when' => $when ?? ($from || $to ? 'any' : 'upcoming'),
            'topic' => in_array($topic, $allowedTopics, true) ? $topic : null,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param  array{q: string, category: ?string, when: string, topic: ?int, from: ?string, to: ?string}  $filters
     */
    public function page(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->query($user)
            ->with(['owningTopic:id,title', 'audienceTopic:id,title', 'tag:id,name'])
            ->withCount(['applications as accepted_count' => fn (Builder $q) => $q->where('status', EventApplication::ACCEPTED)])
            ->when($filters['category'], fn (Builder $q, string $category) => $q->where('category', $category))
            // Same escaping on MySQL and SQLite (% and _ are searched literally).
            ->when($filters['q'] !== '', fn (Builder $q) => Like::contains($q, ['title', 'description'], $filters['q']))
            // Topic or branch: run or announced anywhere at or below the chosen topic.
            ->when($filters['topic'], fn (Builder $q, int $topic) => $q->where(function (Builder $q) use ($topic) {
                $branch = DB::table('topic_closure')->select('descendant_id')->where('ancestor_id', $topic);
                $q->whereIn('owning_topic_id', $branch)->orWhereIn('audience_topic_id', $branch);
            }))
            ->when($filters['from'], fn (Builder $q, string $from) => $q->where('event_starts_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'], fn (Builder $q, string $to) => $q->where('event_starts_at', '<=', Carbon::parse($to)->endOfDay()));

        match ($filters['when']) {
            'past' => $query->where('event_ends_at', '<', now())->orderByDesc('event_starts_at'),
            'any' => $query->orderBy('event_starts_at'),
            default => $query->where('event_ends_at', '>=', now())->orderBy('event_starts_at'),
        };

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        // The person's own application on each event shown, in one query.
        $mine = EventApplication::query()
            ->where('student_id', $user->id)
            ->whereIn('event_id', $page->getCollection()->pluck('id'))
            ->pluck('status', 'event_id');

        return $page->through(fn (Event $event) => $this->present($user, $event, $mine[$event->id] ?? null));
    }

    /**
     * Everything the detail page shows about one event.
     *
     * @return array<string, mixed>
     */
    public function detail(User $user, Event $event): array
    {
        $event->load(['owningTopic:id,title', 'audienceTopic:id,title', 'tag:id,name'])
            ->loadCount(['applications as accepted_count' => fn (Builder $q) => $q->where('status', EventApplication::ACCEPTED)]);

        $mine = EventApplication::query()
            ->where('event_id', $event->id)
            ->where('student_id', $user->id)
            ->value('status');

        return [
            ...$this->present($user, $event, $mine),
            'status' => $event->status,
            'ends_full' => $event->event_ends_at->format('D j M Y, H:i'),
            'applications_close_full' => $event->application_deadline->format('D j M Y, H:i'),
            'completion_deadline' => $event->completion_deadline->format('D j M Y, H:i'),
            'reveal_at' => $event->reveal_at?->format('D j M Y, H:i'),
        ];
    }

    /**
     * One event as the catalog card and the detail page show it.
     *
     * @return array<string, mixed>
     */
    private function present(User $user, Event $event, ?string $myStatus): array
    {
        $open = $this->isOpen($event);

        return [
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
            'places_left' => max(0, $event->capacity - (int) $event->accepted_count),
            'application_type' => $event->application_type,
            'points' => $event->points_value,
            'qr_enabled' => $event->qr_enabled,
            // A surprise event is shown to staff before it is revealed.
            'is_surprise' => $event->is_hidden,
            'is_open' => $open,
            'my_status' => $myStatus,
            // Used by the Apply button (Task #49).
            'can_apply' => $open
                && ! in_array($myStatus, [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED, EventApplication::REJECTED], true)
                && Gate::forUser($user)->allows('apply', $event),
        ];
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
