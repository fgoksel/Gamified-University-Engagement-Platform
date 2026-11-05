<?php

namespace App\Services;

use App\Models\Leaderboard;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The ranking of a leaderboard, always worked out from the ledger (Task #89).
 *
 * A leaderboard sums the points from events owned in its topic and every topic below it
 * (L1, 27 October), with no periods or semesters (L3). Only students appear, equal points
 * share a rank (1, 2, 2, 4) and reversed points drop out, because a reversal is a negative
 * ledger row. The sums, and their cache, are in PointCalculationService (#55).
 */
class LeaderboardStandings
{
    /** The top places everybody sees (L5, 27 October). */
    public const TOP = 10;

    /** Places above and below the person, for "about 10 places around the student" (L5). */
    public const AROUND = 5;

    public function __construct(private PointCalculationService $points) {}

    /**
     * Every student on the leaderboard, best first, each with the points per category.
     * Worked out, and kept for a moment, by PointCalculationService (Task #55).
     *
     * @return Collection<int, object{student_id: int, name: string, neptun_code: ?string, points: int, academic: int, scientific: int, sports: int, community: int, position: int, rank: int}>
     */
    public function ranked(Leaderboard $board): Collection
    {
        return collect($this->points->areaStandings($board->topic_id))->map(fn (array $row) => (object) $row);
    }

    /**
     * What the hall screen shows: the top places and how many students are listed.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function top(Leaderboard $board): array
    {
        $ranked = $this->ranked($board);

        return [
            'total' => $ranked->count(),
            'rows' => $ranked->take(self::TOP)->map(fn (object $row) => $this->row($board, $row))->values()->all(),
        ];
    }

    /**
     * What a signed-in person sees (L5): the top 10, about 10 places around their own
     * position when they are further down, and how many students are listed.
     *
     * @return array{total: int, top: list<array<string, mixed>>, around: list<array<string, mixed>>, places_skipped: bool, you: array{rank: int, points: int}|null}
     */
    public function view(Leaderboard $board, User $viewer): array
    {
        $ranked = $this->ranked($board)->values();
        $position = $ranked->search(fn (object $row) => $row->student_id === $viewer->id);

        $from = self::TOP;
        $around = collect();

        if ($position !== false) {
            $from = max(self::TOP, $position - self::AROUND);
            $around = $ranked->slice($from, max(0, $position + self::AROUND + 1 - $from));
        }

        $present = fn (Collection $rows) => $rows->map(fn (object $row) => $this->row($board, $row, $viewer))->values()->all();

        return [
            'total' => $ranked->count(),
            'top' => $present($ranked->take(self::TOP)),
            'around' => $present($around),
            'places_skipped' => $around->isNotEmpty() && $from > self::TOP,
            'you' => $position === false ? null : ['rank' => $ranked[$position]->rank, 'points' => $ranked[$position]->points],
        ];
    }

    /**
     * One row as people see it. Only the columns the creator chose leave the server (L6);
     * a student's account id never does.
     *
     * @return array<string, mixed>
     */
    private function row(Leaderboard $board, object $row, ?User $viewer = null): array
    {
        [$first, $last] = $this->nameParts($row->name);

        $shown = ['position' => $row->position, 'rank' => $row->rank, 'points' => $row->points];

        if ($board->show_neptun_code) {
            $shown['neptun_code'] = $row->neptun_code;
        }
        if ($board->show_first_name) {
            $shown['first_name'] = $first;
        }
        if ($board->show_last_name) {
            $shown['last_name'] = $last;
        }
        if ($viewer !== null) {
            $shown['is_you'] = $row->student_id === $viewer->id;
        }

        return $shown;
    }

    /**
     * Accounts hold one full name, as in the import file, so it is split at the last space.
     *
     * @return array{0: string, 1: string}
     */
    private function nameParts(string $name): array
    {
        $name = trim($name);

        return str_contains($name, ' ')
            ? [Str::beforeLast($name, ' '), Str::afterLast($name, ' ')]
            : [$name, ''];
    }
}
