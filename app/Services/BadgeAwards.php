<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Models\User;

/**
 * Who holds which badge (Task #103, N10).
 *
 * A badge hangs on an event, and its condition is the event's points: a student who is credited
 * for the event gets the badge, once, by whichever route the credit came (a passport scan, a QR
 * code, an imported list). A credit that is taken back takes the badge back.
 *
 * A badge is only a mark. This class never writes a ledger row and never changes one; it only reads
 * the ledger to see who was credited. So a badge cannot change anybody's points or rank.
 */
class BadgeAwards
{
    /** How many badges Home shows; the profile lists all of them. */
    public const HOME_LIMIT = 6;

    /**
     * The student was credited for the event: they met the condition. Gives them its badge, when it
     * has one. Called inside the transaction that writes the credit (PointLedger::write), so the
     * badge exists for exactly the credits that were written. A student who has it already is not
     * an error: the second row is ignored.
     */
    public function awardForCredit(Event $event, User $student): void
    {
        $badgeId = Badge::query()->where('event_id', $event->id)->value('id');

        if ($badgeId === null) {
            return;
        }

        BadgeAward::query()->insertOrIgnore([
            'badge_id' => $badgeId,
            'student_id' => $student->id,
            'awarded_at' => now(),
        ]);
    }

    /**
     * A badge made after some students were credited: they met its condition already, so they get
     * it now. A credit that was taken back does not count.
     *
     * @return int How many students got it
     */
    public function awardToCredited(Badge $badge): int
    {
        $reversed = PointTransaction::query()
            ->where('event_id', $badge->event_id)
            ->where('type', PointTransaction::REVERSAL)
            ->whereNotNull('reversal_of_id')
            ->select('reversal_of_id');

        $students = PointTransaction::query()
            ->where('event_id', $badge->event_id)
            ->where('type', PointTransaction::CREDIT)
            ->whereNotIn('id', $reversed)
            ->pluck('student_id')
            ->unique();

        $awarded = 0;

        foreach ($students->chunk(500) as $chunk) {
            $awarded += BadgeAward::query()->insertOrIgnore(
                $chunk->map(fn (int $studentId) => [
                    'badge_id' => $badge->id,
                    'student_id' => $studentId,
                    'awarded_at' => now(),
                ])->all()
            );
        }

        return $awarded;
    }

    /**
     * A credit was taken back (a reversal by a system admin, or the removal of the event): the
     * student no longer met the condition, so the event's badge goes too.
     */
    public function revokeForReversedCredit(PointTransaction $credit): void
    {
        BadgeAward::query()
            ->where('student_id', $credit->student_id)
            ->whereIn('badge_id', Badge::query()->where('event_id', $credit->event_id)->select('id'))
            ->delete();
    }

    /**
     * The badges a student holds, newest first, for Home and the profile.
     *
     * @return list<array{id: int, name: string, description: ?string, icon: string, event: ?string, at: string}>
     */
    public function heldBy(User $student, ?int $limit = null): array
    {
        return BadgeAward::query()
            ->where('student_id', $student->id)
            ->with('badge.event:id,title')
            ->latest('awarded_at')
            ->latest('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (BadgeAward $award) => [
                'id' => $award->badge_id,
                'name' => $award->badge->name,
                'description' => $award->badge->description,
                'icon' => Badge::iconOf($award->badge->icon),
                'event' => $award->badge->event?->title,
                'at' => $award->awarded_at->translatedFormat('D j M Y'),
            ])
            ->all();
    }

    public function countHeldBy(User $student): int
    {
        return BadgeAward::query()->where('student_id', $student->id)->count();
    }
}
