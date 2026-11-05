<?php

namespace App\Services;

use App\Enums\TaxonomyCategory;
use App\Enums\UserRole;
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
 * A student only ever gets their own numbers: no other student's name, Neptun code or row leaves
 * the server. Everyone else (a teacher earns no points) gets the full list for an area they can open.
 */
class RankingExplorer
{
    public const PER_PAGE = 50;

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
    public function for(User $user, ?Topic $area, string $category, int $tagId, int $page): array
    {
        $tags = TopicTag::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'category']);

        $category = in_array($category, array_column(TaxonomyCategory::cases(), 'value'), true) ? $category : null;
        $tag = $tags->first(fn (TopicTag $tag) => $tag->id === $tagId && ($category === null || $tag->category === $category));

        $filters = ['area' => $area?->id, 'category' => $category, 'tag' => $tag?->id];

        return [
            'areas' => $this->areas($user),
            'tags' => $tags->map(fn (TopicTag $tag) => $tag->only(['id', 'name', 'category']))->values()->all(),
            'filters' => $filters,
            'result' => $area === null ? null : $this->result($user, $area, $category, $tag?->id, $page),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(User $user, Topic $area, ?string $category, ?int $tagId, int $page): array
    {
        $listed = collect($this->points->standings($area->id, $category, $tagId));

        return $user->hasRole(UserRole::Student)
            ? ['total' => $listed->count(), 'you' => $this->you($user, $listed)]
            : ['list' => $this->fullList($listed, $page)];
    }

    /**
     * Only the student's own numbers. "to_next" is how many points reach the next place up;
     * null in first place, which students with equal points share.
     *
     * @param  Collection<int, array<string, mixed>>  $listed
     * @return array{rank: int, points: int, to_next: ?int}|null
     */
    private function you(User $user, Collection $listed): ?array
    {
        $mine = $listed->firstWhere('student_id', $user->id);

        if ($mine === null) {
            return null;
        }

        $nextPlace = $listed->where('points', '>', $mine['points'])->min('points');

        return [
            'rank' => $mine['rank'],
            'points' => $mine['points'],
            'to_next' => $nextPlace === null ? null : $nextPlace - $mine['points'],
        ];
    }

    /**
     * A student's account id stays on the server here too.
     *
     * @param  Collection<int, array<string, mixed>>  $listed
     * @return array{items: list<array<string, mixed>>, total: int, page: int, last_page: int, per_page: int}
     */
    private function fullList(Collection $listed, int $page): array
    {
        return [
            'items' => $listed->forPage($page, self::PER_PAGE)
                ->map(fn (array $row) => [
                    'position' => $row['position'],
                    'rank' => $row['rank'],
                    'name' => $row['name'],
                    'neptun_code' => $row['neptun_code'],
                    'points' => $row['points'],
                ])
                ->values()
                ->all(),
            'total' => $listed->count(),
            'page' => $page,
            'last_page' => max(1, (int) ceil($listed->count() / self::PER_PAGE)),
            'per_page' => self::PER_PAGE,
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
