<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\User;

/**
 * "My leaderboard" (Task #54, UC-1.2 and UC-2.2): what a signed-in person sees of the leaderboards.
 *
 * A student sees their own points in total and per category, and on every leaderboard they may
 * see, their rank, how many students are listed and their points there, per category. Everyone
 * else (a teacher earns no points) sees the leaderboards they may see and how many students each
 * lists. Which leaderboards those are is decided by AreaLeaderboards (the ones of the topics the
 * person can open); the numbers come from PointCalculationService.
 */
class OwnLeaderboards
{
    public function __construct(
        private AreaLeaderboards $boards,
        private LeaderboardStandings $standings,
        private PointCalculationService $points,
    ) {}

    /**
     * @return array{isStudent: bool, points: array<string, int>|null, leaderboards: list<array<string, mixed>>, highlightLimit: int}
     */
    public function for(User $user): array
    {
        $student = $user->hasRole(UserRole::Student);
        $open = $this->boards->openTo($user);
        $highlighted = $this->boards->highlightedIn($user, $open);

        // The highlighted ones come first (D6, 7 November); each group stays in name order.
        [$starred, $others] = $open
            ->map(fn (Leaderboard $board) => $this->describe($board, $student ? $user : null, in_array($board->id, $highlighted, true)))
            ->partition(fn (array $row) => $row['highlighted']);

        return [
            'isStudent' => $student,
            // Everything the ledger holds for the student, with no period (L3, 27 October).
            'points' => $student ? $this->points->studentTotals($user->id) : null,
            'leaderboards' => $starred->concat($others)->values()->all(),
            'highlightLimit' => Leaderboard::HIGHLIGHT_LIMIT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Leaderboard $board, ?User $student, bool $highlighted): array
    {
        $listed = $this->standings->ranked($board);
        $mine = $student === null ? null : $listed->firstWhere('student_id', $student->id);

        return [
            ...$this->boards->summary($board),
            'highlighted' => $highlighted,
            'students' => $listed->count(),
            // Null when the student has no points in this area yet, so they are not listed.
            'you' => $mine === null ? null : [
                'rank' => $mine->rank,
                'points' => $mine->points,
                // One entry per category of the list (Task #116).
                'categories' => collect(app(CategoryList::class)->zeroes())
                    ->map(fn (int $none, string $slug) => (int) ($mine->{$slug} ?? 0))
                    ->all(),
            ],
        ];
    }
}
