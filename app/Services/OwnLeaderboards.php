<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\User;

/**
 * The boxes of the Leaderboards page and of the start pages (Task #54, leaderboards redesign of
 * 9 November): one box per leaderboard a person may see, the highlighted ones first.
 *
 * A student's box holds their own place, the number of students listed, their points and the
 * points to the next place. Staff are not on the leaderboards, so their box describes the board:
 * who leads it and how many students it lists. Which leaderboards those are is decided by
 * AreaLeaderboards; the numbers come from LeaderboardStandings.
 */
class OwnLeaderboards
{
    public function __construct(
        private AreaLeaderboards $boards,
        private LeaderboardStandings $standings,
    ) {}

    /**
     * @return array{isStudent: bool, leaderboards: list<array<string, mixed>>, highlightLimit: int}
     */
    public function for(User $user): array
    {
        $student = $user->hasRole(UserRole::Student);
        $open = $this->boards->openTo($user);
        $highlighted = $this->boards->highlightedIn($user, $open);

        // The highlighted ones come first (D6, 7 November); each group stays in name order.
        [$starred, $others] = $open
            ->map(fn (Leaderboard $board) => $this->box($board, $user, $student, in_array($board->id, $highlighted, true)))
            ->partition(fn (array $box) => $box['highlighted']);

        return [
            'isStudent' => $student,
            'leaderboards' => $starred->concat($others)->values()->all(),
            'highlightLimit' => Leaderboard::HIGHLIGHT_LIMIT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function box(Leaderboard $board, User $user, bool $student, bool $highlighted): array
    {
        $ranked = $this->standings->ranked($board)->values();

        return [
            ...$this->boards->summary($board),
            'highlighted' => $highlighted,
            'students' => $ranked->count(),
            // A student's own place; null when they have no points in this area yet.
            'you' => $student ? $this->standings->place($ranked, $user) : null,
            // Staff see who leads instead, with only the columns the creator chose (L6).
            'leader' => $student ? null : $this->standings->leader($board, $ranked),
        ];
    }
}
