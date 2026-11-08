<?php

namespace App\Services;

use App\Enums\Capability;
use App\Models\Leaderboard;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who may see and manage area leaderboards, and every change to them (Task #89).
 *
 * Managing follows the "Manage leaderboards" right at the topic, inherited from the topics
 * above like every other right. Seeing follows the person's own areas (D4, 7 November): a
 * leaderboard is for the people who can open its topic, on the hall screen or not. The system
 * admin chooses the hall screen (D7, Task #110).
 */
class AreaLeaderboards
{
    public function __construct(private TopicAccess $access) {}

    public function canManage(User $user, Topic $topic): bool
    {
        return $this->access->can($user, Capability::LeaderboardsManage, $topic) && ! $this->access->isHidden($topic);
    }

    public function canSee(User $user, Leaderboard $board): bool
    {
        return $this->access->canView($user, $board->topic);
    }

    /**
     * The leaderboards a person can open: the ones of the topics they can open, which are the
     * topics they are assigned to and everything below them.
     *
     * @return Collection<int, Leaderboard>
     */
    public function openTo(User $user): Collection
    {
        return Leaderboard::query()
            ->inNormalBrowsing()
            ->whereIn('topic_id', $this->access->visibleTopics($user)->select('topics.id'))
            ->with('topic:id,title')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Which of the leaderboards open to a person they highlighted (D6, 7 November). A highlight
     * of a leaderboard that has left their view, because their area changed or was archived,
     * stays in the table but does not show and does not count.
     *
     * @param  Collection<int, Leaderboard>  $open  what openTo() gave for this person
     * @return list<int>
     */
    public function highlightedIn(User $user, Collection $open): array
    {
        return $open->pluck('id')
            ->intersect($user->highlightedLeaderboards()->pluck('leaderboards.id'))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function isHighlightedBy(User $user, Leaderboard $board): bool
    {
        return $user->highlightedLeaderboards()->whereKey($board->id)->exists();
    }

    /**
     * Highlight a leaderboard for a person: it is listed first. Doing it twice changes nothing.
     * A person highlights up to Leaderboard::HIGHLIGHT_LIMIT of the leaderboards they can open.
     */
    public function highlight(User $user, Leaderboard $board): void
    {
        DB::transaction(function () use ($user, $board) {
            // Two taps at once cannot get past the limit.
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            $already = $this->highlightedIn($user, $this->openTo($user));

            if (in_array($board->id, $already, true)) {
                return;
            }

            if (count($already) >= Leaderboard::HIGHLIGHT_LIMIT) {
                throw ValidationException::withMessages([
                    'highlight' => __('leaderboards.highlight.limit', ['limit' => Leaderboard::HIGHLIGHT_LIMIT]),
                ]);
            }

            $user->highlightedLeaderboards()->syncWithoutDetaching([$board->id]);
        });
    }

    public function removeHighlight(User $user, Leaderboard $board): void
    {
        $user->highlightedLeaderboards()->detach($board->id);
    }

    /**
     * The leaderboards made for this very topic.
     *
     * @return Collection<int, Leaderboard>
     */
    public function at(Topic $topic): Collection
    {
        return Leaderboard::query()
            ->where('topic_id', $topic->id)
            ->with('topic:id,title')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * What the screens need to know about a leaderboard.
     *
     * @return array<string, mixed>
     */
    public function summary(Leaderboard $board): array
    {
        return [
            'id' => $board->id,
            'name' => $board->name,
            'is_public' => $board->isPublic(),
            'topic' => ['id' => $board->topic->id, 'title' => $board->topic->title],
            'columns' => $board->columns(),
            'show_neptun_code' => $board->show_neptun_code,
            'show_first_name' => $board->show_first_name,
            'show_last_name' => $board->show_last_name,
            'hall_seconds' => $board->hall_seconds,
        ];
    }

    /**
     * A new leaderboard stays off the hall screen until the system admin switches it on: a Neptun code
     * is still a personal identifier.
     *
     * @param  array{name: string, show_neptun_code: bool, show_first_name: bool, show_last_name: bool}  $data
     */
    public function create(User $actor, Topic $topic, array $data): Leaderboard
    {
        return DB::transaction(function () use ($actor, $topic, $data) {
            $board = Leaderboard::create([
                ...$data,
                'topic_id' => $topic->id,
                'visibility' => Leaderboard::AREA,
                'created_by_id' => $actor->id,
            ]);

            TopicAudit::record($actor, 'leaderboard.created', $topic->id, 'leaderboard', $board->id, null, $this->snapshot($board));

            return $board;
        });
    }

    /**
     * @param  array{name?: string, show_neptun_code?: bool, show_first_name?: bool, show_last_name?: bool, hall_seconds?: ?int}  $data
     */
    public function update(User $actor, Leaderboard $board, array $data): Leaderboard
    {
        return DB::transaction(function () use ($actor, $board, $data) {
            $before = $this->snapshot($board);
            $board->fill($data);

            if ($board->isDirty()) {
                $board->save();
                TopicAudit::record($actor, 'leaderboard.updated', $board->topic_id, 'leaderboard', $board->id, $before, $this->snapshot($board));
            }

            return $board;
        });
    }

    public function setPublished(User $actor, Leaderboard $board, bool $published): Leaderboard
    {
        return DB::transaction(function () use ($actor, $board, $published) {
            $before = $this->snapshot($board);
            $board->visibility = $published ? Leaderboard::PUBLIC : Leaderboard::AREA;

            if ($board->isDirty()) {
                $board->save();
                TopicAudit::record($actor, $published ? 'leaderboard.published' : 'leaderboard.unpublished', $board->topic_id, 'leaderboard', $board->id, $before, $this->snapshot($board));
            }

            return $board;
        });
    }

    /**
     * Deleting a leaderboard changes no points: they stay in the ledger.
     */
    public function delete(User $actor, Leaderboard $board): void
    {
        DB::transaction(function () use ($actor, $board) {
            TopicAudit::record($actor, 'leaderboard.deleted', $board->topic_id, 'leaderboard', $board->id, $this->snapshot($board));
            $board->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Leaderboard $board): array
    {
        return $board->only(['name', 'visibility', 'show_neptun_code', 'show_first_name', 'show_last_name', 'hall_seconds']);
    }
}
