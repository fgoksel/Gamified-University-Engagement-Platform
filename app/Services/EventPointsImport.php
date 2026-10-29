<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Notifications\PointsCreditedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * "Give points by import" (Task #85): an organiser gives an event's points to a list of
 * students, for example a Moodle or Neptun export. It works for any event, and a surprise
 * event is credited only this way (26 Oct, Q8).
 *
 * Team decision of 27 Oct: the file has one column of Neptun codes; students not assigned
 * to the event's area are skipped and listed with the reason; importing the same list again
 * adds only the students who are new on it. The organiser first sees what would happen
 * (assess()), then gives the points (import()). Every credit goes through the ledger, so
 * the rules and the one-credit-per-student guard are the same as for a QR scan.
 */
class EventPointsImport
{
    /** Neptun codes are six letters or digits, as checked by the student import. */
    private const NEPTUN = '/^[A-Z0-9]{6}$/';

    public function __construct(private PointLedger $ledger) {}

    /**
     * What importing these rows would do, without changing anything.
     *
     * @param  list<array{line: int, code: string}>  $rows  As read by NeptunCodeFile
     * @return array{refusal: ?string, credit: list<array<string, mixed>>, already: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function assess(Event $event, User $organiser, array $rows): array
    {
        $area = $this->ledger->importArea($event);
        $codes = collect($rows)->pluck('code')->filter(fn (string $code) => preg_match(self::NEPTUN, $code) === 1)->unique()->values();

        $students = User::role(UserRole::Student)->whereIn('neptun_code', $codes)->get()
            ->keyBy(fn (User $student) => strtoupper((string) $student->neptun_code));
        $ids = $students->pluck('id');
        $inArea = RoleAssignment::query()->active()
            ->where('topic_id', $area->id)
            ->whereIn('user_id', $ids)
            ->with('role')
            ->get()
            ->groupBy('user_id');
        // As for seeing an event announced there (Task #71), a role at the area must include "View events".
        $seesEvents = $inArea->filter(fn (Collection $roles) => $roles->contains(
            fn (RoleAssignment $assignment) => $assignment->role->has(Capability::EventsView)
        ));
        $credits = PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('type', PointTransaction::CREDIT)
            ->whereIn('student_id', $ids)
            ->pluck('id', 'student_id');
        $reversed = PointTransaction::query()->whereIn('reversal_of_id', $credits->values())->pluck('reversal_of_id')->flip();

        $result = ['credit' => [], 'already' => [], 'skipped' => []];
        $firstLine = [];

        foreach ($rows as ['line' => $line, 'code' => $code]) {
            $student = $students->get($code);
            $row = ['line' => $line, 'neptun_code' => $code, 'name' => $student?->name];

            [$list, $reason] = match (true) {
                $code === '' => ['skipped', 'The Neptun code is empty.'],
                preg_match(self::NEPTUN, $code) !== 1 => ['skipped', 'This is not a Neptun code: it should be 6 letters or digits.'],
                isset($firstLine[$code]) => ['skipped', "Listed already on row {$firstLine[$code]}."],
                $student === null => ['skipped', 'No student account has this Neptun code.'],
                $student->is($organiser) => ['skipped', 'You cannot give points to yourself.'],
                $credits->has($student->id) => ['already', $reversed->has($credits[$student->id])
                    ? 'Their points for this event were taken back, so they cannot be given again.'
                    : 'Already has the points for this event.'],
                $student->status === 'invited' => ['skipped', 'Has not activated their account yet. Import them again once they have.'],
                $student->status !== 'active' => ['skipped', 'Their account is deactivated.'],
                ! $inArea->has($student->id) => ['skipped', "Not assigned to {$area->title}, the area of this event."],
                ! $seesEvents->has($student->id) => ['skipped', "Their role in {$area->title} does not include \"View events\"."],
                default => ['credit', null],
            };

            if ($code !== '' && ! isset($firstLine[$code])) {
                $firstLine[$code] = $line;
            }

            $result[$list][] = $list === 'credit' ? $row + ['id' => $student->id] : $row + ['reason' => $reason];
        }

        return ['refusal' => $this->ledger->refusalFor($event)?->getMessage(), ...$result];
    }

    /**
     * Give the event's points to every student the file lists who may get them now. Each
     * credit stands on its own: a student credited a moment ago by someone else, or one who
     * lost their place in the area meanwhile, is reported instead of stopping the rest.
     *
     * @param  list<array{line: int, code: string}>  $rows
     * @return array{credited: list<array<string, mixed>>, already: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     *
     * @throws EventActionRefused when the event takes no points at the moment
     */
    public function import(Event $event, User $organiser, array $rows): array
    {
        $plan = $this->assess($event, $organiser, $rows);

        if ($plan['refusal'] !== null) {
            throw new EventActionRefused('event_refused', $plan['refusal'], 409);
        }

        $students = User::query()->with('roles')->whereIn('id', array_column($plan['credit'], 'id'))->get()->keyBy('id');
        $credited = [];
        $creditedIds = [];
        $already = $plan['already'];
        $skipped = $plan['skipped'];

        foreach ($plan['credit'] as $row) {
            $entry = array_diff_key($row, ['id' => true]);

            try {
                $this->ledger->creditImported($event, $students[$row['id']], $organiser);
                $credited[] = $entry;
                $creditedIds[] = $row['id'];
            } catch (EventActionRefused $refused) {
                if ($refused->reason === 'already_credited') {
                    $already[] = $entry + ['reason' => 'Already has the points for this event.'];
                } else {
                    $skipped[] = $entry + ['reason' => $refused->getMessage()];
                }
            }
        }

        $this->notify($event, $students->only($creditedIds));

        return [
            'credited' => $credited,
            'already' => $this->inFileOrder($already),
            'skipped' => $this->inFileOrder($skipped),
        ];
    }

    /**
     * Students learn about points they did not scan for from a notification (27 Oct): for a
     * surprise event this is how they find out about the event at all.
     *
     * @param  Collection<int, User>  $students
     */
    private function notify(Event $event, Collection $students): void
    {
        if ($students->isEmpty()) {
            return;
        }

        Notification::send($students->values(), new PointsCreditedNotification(
            $event->id,
            $event->title,
            $event->points_value,
            $event->category,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function inFileOrder(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        return $rows;
    }
}
