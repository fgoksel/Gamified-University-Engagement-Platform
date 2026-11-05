<?php

namespace App\Services;

use App\Enums\TaxonomyCategory;
use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\User;

/**
 * "My leaderboard" (Task #54, UC-1.2 and UC-2.2): what a signed-in person sees of the leaderboards.
 *
 * A student sees their own points in total and per category, and on every leaderboard they may
 * see, their rank, how many students are listed and their points there, per category. Everyone
 * else (a teacher earns no points) sees the leaderboards they may see and how many students each
 * lists. Which leaderboards those are is decided by AreaLeaderboards (the published ones, and the
 * area-only ones of the topics the person can open); the numbers come from PointCalculationService.
 */
class OwnLeaderboards
{
    public function __construct(
        private AreaLeaderboards $boards,
        private LeaderboardStandings $standings,
        private PointCalculationService $points,
    ) {}

    /**
     * @return array{isStudent: bool, points: array{total: int, academic: int, scientific: int, sports: int, community: int}|null, leaderboards: list<array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $student = $user->hasRole(UserRole::Student);

        return [
            'isStudent' => $student,
            // Everything the ledger holds for the student, with no period (L3, 27 October).
            'points' => $student ? $this->points->studentTotals($user->id) : null,
            'leaderboards' => $this->boards->openTo($user)
                ->map(fn (Leaderboard $board) => $this->describe($board, $student ? $user : null))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Leaderboard $board, ?User $student): array
    {
        $listed = $this->standings->ranked($board);
        $mine = $student === null ? null : $listed->firstWhere('student_id', $student->id);

        return [
            ...$this->boards->summary($board),
            'students' => $listed->count(),
            // Null when the student has no points in this area yet, so they are not listed.
            'you' => $mine === null ? null : [
                'rank' => $mine->rank,
                'points' => $mine->points,
                'categories' => collect(TaxonomyCategory::cases())
                    ->mapWithKeys(fn (TaxonomyCategory $category) => [$category->value => $mine->{$category->value}])
                    ->all(),
            ],
        ];
    }
}
