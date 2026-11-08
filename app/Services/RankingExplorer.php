<?php

namespace App\Services;

use App\Enums\TaxonomyCategory;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Where am I?" (Task #56): a person's place in an area, narrowed by category and tag if they
 * like. The counting is an area leaderboard's (PointCalculationService), and the areas on offer
 * are the ones the explorer opens for them (TopicAccess).
 *
 * Only students use it, and they only ever get their own numbers: no other student's name,
 * Neptun code or row leaves the server. A ranked list exists only on a leaderboard that an admin
 * created (D1 and D3, 7 November), so nobody gets one here (Task #112).
 */
class RankingExplorer
{
    /** The most areas a picker lists; the address still opens any area the person can open. */
    private const AREA_LIMIT = 500;

    public function __construct(
        private TopicAccess $access,
        private PointCalculationService $points,
    ) {}

    /**
     * The controller has already checked that the person may open $area. A category or a tag
     * that does not fit is dropped, as with the filters of the event list.
     *
     * @return array{areas: list<array{id: int, path: list<string>}>, tags: list<array{id: int, name: string, category: string}>, filters: array{area: ?int, category: ?string, tag: ?int}, result: array<string, mixed>|null}
     */
    public function for(User $user, ?Topic $area, string $category, int $tagId): array
    {
        $tags = TopicTag::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'category']);

        $category = in_array($category, array_column(TaxonomyCategory::cases(), 'value'), true) ? $category : null;
        $tag = $tags->first(fn (TopicTag $tag) => $tag->id === $tagId && ($category === null || $tag->category === $category));

        $filters = ['area' => $area?->id, 'category' => $category, 'tag' => $tag?->id];

        return [
            'areas' => $this->areas($user),
            'tags' => $tags->map(fn (TopicTag $tag) => $tag->only(['id', 'name', 'category']))->values()->all(),
            'filters' => $filters,
            'result' => $area === null ? null : $this->result($user, $area, $category, $tag?->id),
        ];
    }

    /**
     * @return array{total: int, you: array{rank: int, points: int, to_next: ?int, next_rank: ?int}|null}
     */
    private function result(User $user, Topic $area, ?string $category, ?int $tagId): array
    {
        $listed = collect($this->points->standings($area->id, $category, $tagId));

        return ['total' => $listed->count(), 'you' => $this->you($user, $listed)];
    }

    /**
     * Only the student's own numbers. "to_next" is how many points draw level with the next
     * place up, and "next_rank" is that place; both null in first place, which students with
     * equal points share.
     *
     * @param  Collection<int, array<string, mixed>>  $listed
     * @return array{rank: int, points: int, to_next: ?int, next_rank: ?int}|null
     */
    private function you(User $user, Collection $listed): ?array
    {
        $mine = $listed->firstWhere('student_id', $user->id);

        if ($mine === null) {
            return null;
        }

        // The list is best first, so the last one above is the next place up.
        $next = $listed->where('points', '>', $mine['points'])->last();

        return [
            'rank' => $mine['rank'],
            'points' => $mine['points'],
            'to_next' => $next === null ? null : $next['points'] - $mine['points'],
            'next_rank' => $next['rank'] ?? null,
        ];
    }

    /**
     * The areas the person can open, each with its path in the tree. A path only holds areas they
     * can open too: the titles above their own part of the tree stay hidden, as in the explorer.
     *
     * @return list<array{id: int, path: list<string>}>
     */
    private function areas(User $user): array
    {
        $ids = $this->access->visibleTopics($user)
            ->orderBy('depth')
            ->orderBy('title')
            ->limit(self::AREA_LIMIT)
            ->pluck('topics.id');

        return DB::table('topic_closure')
            ->join('topics', 'topics.id', '=', 'topic_closure.ancestor_id')
            ->whereIn('topic_closure.descendant_id', $ids)
            ->whereIn('topic_closure.ancestor_id', $ids)
            ->orderByDesc('topic_closure.distance')
            ->get(['topic_closure.descendant_id', 'topics.title'])
            ->groupBy('descendant_id')
            ->map(fn (Collection $rows, int $id) => ['id' => $id, 'path' => $rows->pluck('title')->all()])
            // Sorted by path, so the list reads like the tree.
            ->sortBy(fn (array $area) => mb_strtolower(implode("\u{0}", $area['path'])), SORT_STRING)
            ->values()
            ->all();
    }
}
