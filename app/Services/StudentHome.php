<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The student's home page (Task #92, 26 Oct, section 4): their points in total and per category
 * (N7, 9 November), the next event they have a place at, events open for applications and their
 * recent points. The leaderboard boxes come from OwnLeaderboards (leaderboards redesign,
 * 9 November). Each part is one query (open events add eligibility checks, stopping once
 * OPEN_LIMIT pass), and every rule comes from where it already lives: the ledger for points,
 * the category list for the categories, the catalog for what the student may see, the
 * application service for who may apply.
 */
class StudentHome
{
    /** How many open events and recent points the page lists; each part links to the full page. */
    public const OPEN_LIMIT = 3;

    public const RECENT_LIMIT = 5;

    public function __construct(
        private PointLedger $ledger,
        private CategoryList $categories,
        private EventCatalog $catalog,
        private EventApplicationService $applications,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $student): array
    {
        return [
            'points' => $this->points($student),
            'next' => $this->next($student),
            'open' => $this->open($student),
            'recent' => $this->recent($student),
        ];
    }

    /**
     * "Your points" (N7, 9 November): what the student has earned, in total and per category,
     * so they see where they are lacking. Everything the ledger holds, with no period, the same
     * points the leaderboards add up (L3, 27 October; reset only by hand, #102).
     *
     * One entry per category of the list (Task #116), in list order: every category in use, and
     * a retired one only while it holds points. Points of events without a category are in
     * "uncategorised", so the entries always add up to the total.
     *
     * @return array{total: int, categories: list<array{slug: string, points: int}>, uncategorised: int}
     */
    private function points(User $student): array
    {
        $totals = $this->ledger->totals($student);

        $categories = $this->categories->all()
            // "?? 0": totals cached a moment before a category was added do not have it yet.
            ->map(fn (Category $category) => ['category' => $category, 'points' => (int) ($totals[$category->slug] ?? 0)])
            ->filter(fn (array $row) => ! $row['category']->isRetired() || $row['points'] !== 0)
            ->map(fn (array $row) => ['slug' => $row['category']->slug, 'points' => $row['points']])
            ->values()
            ->all();

        return [
            'total' => $totals['total'],
            'categories' => $categories,
            'uncategorised' => $totals['total'] - array_sum(array_column($categories, 'points')),
        ];
    }

    /**
     * The soonest event the student has a place at that has not ended yet, or null.
     * Seconds are counted on the server, so the countdown never depends on the browser's clock or zone.
     *
     * @return array<string, mixed>|null
     */
    private function next(User $student): ?array
    {
        $event = Event::query()
            ->where('status', Event::STATUS_PUBLISHED)
            ->where('event_ends_at', '>=', now())
            ->whereHas('applications', fn (Builder $q) => $q
                ->where('student_id', $student->id)
                ->where('status', EventApplication::ACCEPTED))
            ->with('tag:id,name')
            ->orderBy('event_starts_at')
            ->first();

        if ($event === null) {
            return null;
        }

        return [
            ...$this->summary($event),
            'location' => $event->location,
            'running' => $event->event_starts_at->lte(now()),
            'starts_in' => max(0, (int) now()->diffInSeconds($event->event_starts_at, false)),
            'ends_in' => max(0, (int) now()->diffInSeconds($event->event_ends_at, false)),
        ];
    }

    /**
     * Up to OPEN_LIMIT events the student could apply to right now, soonest first: the
     * catalog's visibility, the application window open, no application of theirs, and the
     * application service's own eligibility check.
     *
     * @return list<array<string, mixed>>
     */
    private function open(User $student): array
    {
        $candidates = $this->catalog->query($student)
            ->where('is_hidden', false)
            ->where('event_ends_at', '>=', now())
            ->where('application_deadline', '>=', now())
            ->where(fn (Builder $q) => $q
                ->where('applications_open_at', '<=', now())
                ->orWhere(fn (Builder $q) => $q->whereNull('applications_open_at')->where('published_at', '<=', now())))
            ->whereDoesntHave('applications', fn (Builder $q) => $q
                ->where('student_id', $student->id)
                ->whereIn('status', [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED, EventApplication::REJECTED]))
            ->with('tag:id,name')
            ->withCount(['applications as accepted_count' => fn (Builder $q) => $q->where('status', EventApplication::ACCEPTED)])
            ->orderBy('event_starts_at')
            // A few spare: an event the student may not apply to (e.g. it overlaps a rule) is skipped.
            ->limit(self::OPEN_LIMIT * 4)
            ->get();

        // lazy(): stop checking eligibility once OPEN_LIMIT events pass, instead of checking
        // every candidate first (a plain Collection filter runs over all of them).
        return $candidates
            ->lazy()
            ->filter(fn (Event $event) => $this->applications->mayApply($student, $event))
            ->take(self::OPEN_LIMIT)
            ->map(fn (Event $event) => [
                ...$this->summary($event),
                'application_type' => $event->application_type,
                'capacity' => $event->capacity,
                'places_left' => max(0, $event->capacity - (int) $event->accepted_count),
                'applications_close' => $event->application_deadline->translatedFormat('D j M, H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * The student's latest point rows, credits and corrections alike, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(User $student): array
    {
        return PointTransaction::query()
            ->where('student_id', $student->id)
            ->with('event:id,title')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (PointTransaction $row) => [
                'id' => $row->id,
                'event_id' => $row->event_id,
                'event' => $row->event?->title,
                'category' => $row->category,
                'points' => $row->points,
                'correction' => $row->type === PointTransaction::REVERSAL,
                'at' => $row->created_at?->translatedFormat('D j M Y'),
            ])
            ->all();
    }

    /**
     * What every event on the page shows.
     *
     * @return array<string, mixed>
     */
    private function summary(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'category' => $event->category,
            'tag' => $event->tag?->name,
            'points' => $event->points_value,
            'starts' => $event->event_starts_at->translatedFormat('D j M Y, H:i'),
        ];
    }
}
