<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Leaderboard;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ranking of a leaderboard, always worked out from the ledger (Task #89).
 *
 * A leaderboard sums the points from events owned in its topic and every topic below it
 * (L1, 27 October), with no periods or semesters (L3). Only students appear, equal points
 * share a rank (1, 2, 2, 4) and reversed points drop out, because a reversal is a negative
 * ledger row. Caching belongs to #55, around ranked().
 */
class LeaderboardStandings
{
    /** The top places everybody sees (L5, 27 October). */
    public const TOP = 10;

    /** Places above and below the person, for "about 10 places around the student" (L5). */
    public const AROUND = 5;

    /**
     * Every student on the leaderboard, best first.
     *
     * @return Collection<int, object{student_id: int, name: string, neptun_code: ?string, points: int, position: int, rank: int}>
     */
    public function ranked(Leaderboard $board): Collection
    {
        $rows = DB::table('point_transactions as ledger')
            ->join('events', 'events.id', '=', 'ledger.event_id')
            ->join('topic_closure', 'topic_closure.descendant_id', '=', 'events.owning_topic_id')
            ->join('users', 'users.id', '=', 'ledger.student_id')
            ->where('topic_closure.ancestor_id', $board->topic_id)
            ->whereIn('ledger.student_id', User::role(UserRole::Student)->select('users.id'))
            // A banned account stays out of the lists. An invited one counts: an import can credit it.
            ->where('users.status', '!=', 'inactive')
            ->groupBy('ledger.student_id', 'users.name', 'users.neptun_code')
            ->havingRaw('sum(ledger.points) > 0')
            ->selectRaw('ledger.student_id, users.name, users.neptun_code, sum(ledger.points) as points')
            ->orderByDesc('points')
            ->orderBy('ledger.student_id')
            ->get();

        $rank = 0;
        $previous = null;

        return $rows->map(function (object $row, int $index) use (&$rank, &$previous) {
            $row->student_id = (int) $row->student_id;
            $row->points = (int) $row->points;

            if ($row->points !== $previous) {
                $rank = $index + 1;
                $previous = $row->points;
            }

            $row->position = $index + 1;
            $row->rank = $rank;

            return $row;
        });
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
