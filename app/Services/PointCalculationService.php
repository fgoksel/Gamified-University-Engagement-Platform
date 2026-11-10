<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\PointTransaction;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where points are added up (Task #55): the standings of an area and the totals of a student,
 * always from the ledger, and cached for a short time (config/leaderboards.php, 30 seconds).
 *
 * The cache is tagged. A credit or a reversal clears exactly what it changes: the totals of that
 * student, and the standings of the area of the event and of every area above it (a leaderboard
 * covers its area and everything below). So a new credit shows at once; the time to live is
 * only the limit for changes that do not pass the ledger, such as a deactivated account.
 *
 * The cache never decides anything. Without a store that supports tags, with the time to live
 * at 0, or when the store cannot be reached, the totals are worked out again on each request.
 *
 * The points per category follow the category list (Task #116): one total for each category in
 * it, retired ones too, so the totals still add up to the points of the student. Points of an
 * event with no category count in "points" and "total" only.
 */
class PointCalculationService
{
    /** Every cached standing of an area carries this tag, so all of them can be cleared at once. */
    private const ALL_AREAS = 'leaderboards';

    /** After a failure the cache is left alone for this many seconds. */
    private const RETRY_AFTER = 30;

    private ?int $unavailableUntil = null;

    public function __construct(private CategoryList $categories) {}

    /**
     * Every student with points in the area (the topic and everything below it), best first.
     * Only student accounts appear and deactivated ones stay out; reversed points drop out, and a
     * student left with nothing is not listed. Equal points share a rank (1, 2, 2, 4).
     *
     * Each row also has the points per category, under the category's slug.
     *
     * @return list<array<string, int|string|null>>
     */
    public function areaStandings(int $topicId): array
    {
        return $this->standings($topicId);
    }

    /**
     * The same standings, narrowed to the points of one category and to the events of one tag
     * ("Where am I?", Task #56). Kept under the area's tags, so a credit clears them all alike.
     *
     * @return list<array<string, int|string|null>>
     */
    public function standings(int $topicId, ?string $category = null, ?int $tagId = null): array
    {
        $key = "leaderboard:area:{$topicId}";

        if ($category !== null || $tagId !== null) {
            $key .= ':category:'.($category ?? 'any').':tag:'.($tagId ?? 'any');
        }

        return $this->remember(
            $key,
            [self::ALL_AREAS, $this->areaTag($topicId)],
            fn () => $this->calculateArea($topicId, $category, $tagId),
        );
    }

    /**
     * A student's points in total and per category, over everything or for one semester:
     * "total" and one entry per category slug.
     *
     * @return array<string, int>
     */
    public function studentTotals(int $studentId, ?int $semesterId = null): array
    {
        return $this->remember(
            'points:student:'.$studentId.':'.($semesterId ?? 'all'),
            [$this->studentTag($studentId)],
            fn () => $this->calculateStudent($studentId, $semesterId),
        );
    }

    /**
     * A new ledger row (a credit, an import or a reversal) changes the totals of its student and
     * the standings of the event's area and of the areas above it. Called by PointTransaction.
     */
    public function forgetCredit(PointTransaction $row): void
    {
        $areas = DB::table('events')
            ->join('topic_closure', 'topic_closure.descendant_id', '=', 'events.owning_topic_id')
            ->where('events.id', $row->event_id)
            ->pluck('topic_closure.ancestor_id');

        $tags = [
            $this->studentTag((int) $row->student_id),
            ...$areas->map(fn ($id) => $this->areaTag((int) $id))->all(),
        ];

        // After the commit: a clear before it could be undone by a read of the old rows.
        DB::afterCommit(fn () => $this->flush($tags));
    }

    /**
     * Something other than a credit changed which events an area counts (a topic was moved, so the
     * areas above it changed): clear every standing.
     */
    public function forgetAllAreas(): void
    {
        DB::afterCommit(fn () => $this->flush([self::ALL_AREAS]));
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private function calculateArea(int $topicId, ?string $category = null, ?int $tagId = null): array
    {
        // One sum per category of the list. The slugs are bound, and the columns get plain names
        // (c0, c1, ...), so nothing an admin types ever becomes part of the query.
        $slugs = array_keys($this->categories->zeroes());
        $sums = collect($slugs)
            ->map(fn (string $slug, int $index) => ", sum(case when ledger.category = ? then ledger.points else 0 end) as c{$index}")
            ->implode('');

        $rows = DB::table('point_transactions as ledger')
            ->join('events', 'events.id', '=', 'ledger.event_id')
            ->join('topic_closure', 'topic_closure.descendant_id', '=', 'events.owning_topic_id')
            ->join('users', 'users.id', '=', 'ledger.student_id')
            ->where('topic_closure.ancestor_id', $topicId)
            ->whereIn('ledger.student_id', User::role(UserRole::Student)->select('users.id'))
            // A deactivated account stays out of the lists.
            ->where('users.status', '!=', 'inactive')
            ->when($category, fn ($query) => $query->where('ledger.category', $category))
            ->when($tagId, fn ($query) => $query->where('events.topic_tag_id', $tagId))
            ->groupBy('ledger.student_id', 'users.name', 'users.neptun_code')
            ->havingRaw('sum(ledger.points) > 0')
            ->selectRaw("ledger.student_id, users.name, users.neptun_code, sum(ledger.points) as points{$sums}", $slugs)
            ->orderByDesc('points')
            ->orderBy('ledger.student_id')
            ->get();

        $rank = 0;
        $previous = null;

        return $rows->values()->map(function (object $row, int $index) use (&$rank, &$previous, $slugs) {
            $points = (int) $row->points;

            if ($points !== $previous) {
                $rank = $index + 1;
                $previous = $points;
            }

            return [
                'student_id' => (int) $row->student_id,
                'name' => (string) $row->name,
                'neptun_code' => $row->neptun_code,
                'points' => $points,
                ...collect($slugs)->mapWithKeys(fn (string $slug, int $index) => [$slug => (int) $row->{"c{$index}"}])->all(),
                'position' => $index + 1,
                'rank' => $rank,
            ];
        })->all();
    }

    /**
     * @return array<string, int>
     */
    private function calculateStudent(int $studentId, ?int $semesterId): array
    {
        $rows = DB::table('point_transactions')
            ->where('student_id', $studentId)
            ->when($semesterId, fn ($query) => $query->where('semester_id', $semesterId))
            ->selectRaw('category, sum(points) as points')
            ->groupBy('category')
            ->get();

        $totals = ['total' => 0, ...$this->categories->zeroes()];

        foreach ($rows as $row) {
            // Points with no category count in the total only.
            if ($row->category !== null) {
                $totals[$row->category] = (int) $row->points;
            }

            $totals['total'] += (int) $row->points;
        }

        return $totals;
    }

    private function areaTag(int $topicId): string
    {
        return "leaderboard-area:{$topicId}";
    }

    private function studentTag(int $studentId): string
    {
        return "student-points:{$studentId}";
    }

    /**
     * The cached value, or the value worked out now (and kept for the time to live).
     *
     * @template T
     *
     * @param  list<string>  $tags
     * @param  Closure(): T  $calculate
     * @return T
     */
    private function remember(string $key, array $tags, Closure $calculate): mixed
    {
        $store = $this->store();

        if ($store === null) {
            return $calculate();
        }

        try {
            $hit = $store->tags($tags)->get($key);
        } catch (Throwable $e) {
            $this->unavailable($e);

            return $calculate();
        }

        if ($hit !== null) {
            return $hit;
        }

        // Worked out outside the try: an error of the calculation itself must not be swallowed.
        $value = $calculate();

        try {
            $store->tags($tags)->put($key, $value, config('leaderboards.ttl'));
        } catch (Throwable $e) {
            $this->unavailable($e);
        }

        return $value;
    }

    /**
     * @param  list<string>  $tags
     */
    private function flush(array $tags): void
    {
        $store = $this->store();

        if ($store === null) {
            return;
        }

        try {
            $store->tags($tags)->flush();
        } catch (Throwable $e) {
            $this->unavailable($e);
        }
    }

    /**
     * The cache to use, or null for "work it out": switched off, no tags, or recently unreachable.
     */
    private function store(): ?Repository
    {
        if ((int) config('leaderboards.ttl') <= 0) {
            return null;
        }

        if ($this->unavailableUntil !== null && now()->timestamp < $this->unavailableUntil) {
            return null;
        }

        try {
            $repository = Cache::store(config('leaderboards.cache_store'));

            return $repository->supportsTags() ? $repository : null;
        } catch (Throwable $e) {
            $this->unavailable($e);

            return null;
        }
    }

    private function unavailable(Throwable $e): void
    {
        $this->unavailableUntil = now()->timestamp + self::RETRY_AFTER;

        Log::warning('The leaderboard cache is not available, so the totals are worked out on each request.', [
            'store' => config('leaderboards.cache_store'),
            'error' => $e->getMessage(),
        ]);
    }
}
