<?php

namespace Tests\Feature\Badges;

use App\Enums\Capability;
use App\Exceptions\EventActionRefused;
use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\BadgeAwards;
use App\Services\EventBadges;
use App\Services\EventModerator;
use App\Services\PointLedger;
use App\Services\QrPassport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Events\EventTestCase;

/**
 * Task #103 (N10): a badge hangs on an event. A student who is credited for the event gets it,
 * once, by whichever route the credit came, and loses it when the credit is taken back. A badge
 * adds no points and changes no rank.
 */
class BadgeAwardingTest extends EventTestCase
{
    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organiser = $this->staff([Capability::PointsCredit, Capability::EventsCreate, Capability::EventsManage], null, 'Olga Organiser');
    }

    private function ledger(): PointLedger
    {
        return app(PointLedger::class);
    }

    private function badgeFor(?Event $event = null, string $name = 'Database Pro'): Badge
    {
        return Badge::create([
            'event_id' => ($event ?? $this->event)->id,
            'name' => $name,
            'created_by_id' => $this->organiser->id,
        ]);
    }

    /** A student of the course. The scan route needs an accepted application as well. */
    private function enrolled(bool $accepted = true, ?string $neptun = null, ?Event $event = null): User
    {
        $student = $this->student(null, $neptun);
        $this->assign($student, $this->studentRole(), $this->course);

        if ($accepted) {
            $this->accepted($student, $event);
        }

        return $student;
    }

    private function scan(User $student, ?Event $event = null): PointTransaction
    {
        return $this->ledger()->credit($event ?? $this->event, $student, $this->organiser, PointLedger::PASSPORT);
    }

    private function holds(User $student, ?Badge $badge = null): bool
    {
        return BadgeAward::query()
            ->where('student_id', $student->id)
            ->when($badge !== null, fn ($query) => $query->where('badge_id', $badge->id))
            ->exists();
    }

    public function test_a_credit_by_a_scan_gives_the_students_the_badge(): void
    {
        $badge = $this->badgeFor();
        $student = $this->enrolled();

        $this->scan($student);

        $this->assertTrue($this->holds($student, $badge));
        $this->assertNotNull(BadgeAward::query()->where('student_id', $student->id)->value('awarded_at'));
    }

    public function test_a_credit_by_an_imported_list_gives_it_too(): void
    {
        $badge = $this->badgeFor();
        $student = $this->enrolled(accepted: false);

        $this->ledger()->creditImported($this->event, $student, $this->organiser);

        $this->assertTrue($this->holds($student, $badge));
    }

    public function test_the_organisers_scan_page_and_import_page_give_it_through_the_real_routes(): void
    {
        $badge = $this->badgeFor();
        $scanned = $this->enrolled();
        $listed = $this->enrolled(accepted: false, neptun: 'BADG01');

        $this->actingAs($this->organiser)
            ->postJson("/events/{$this->event->id}/credit", ['identifier' => app(QrPassport::class)->issue($scanned)])
            ->assertOk()
            ->assertJsonPath('status', 'credited');

        $this->actingAs($this->organiser)
            ->post("/events/{$this->event->id}/points-import", [
                'file' => UploadedFile::fake()->createWithContent('list.csv', "Neptun Code\nBADG01\n"),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(1, 'credited');

        $this->assertTrue($this->holds($scanned, $badge));
        $this->assertTrue($this->holds($listed, $badge));
    }

    public function test_the_badge_is_given_once(): void
    {
        $badge = $this->badgeFor();
        $student = $this->enrolled();
        $this->scan($student);

        // A second credit for the same student and event is refused, so no second award follows.
        try {
            $this->scan($student);
            $this->fail('A second credit must be refused.');
        } catch (EventActionRefused $refused) {
            $this->assertSame('already_credited', $refused->reason);
        }

        // And the awarding itself is safe to repeat: the database holds one row per student and badge.
        app(BadgeAwards::class)->awardForCredit($this->event, $student);
        app(BadgeAwards::class)->awardForCredit($this->event, $student);

        $this->assertSame(1, BadgeAward::query()->where('badge_id', $badge->id)->count());
    }

    public function test_an_event_without_a_badge_gives_none_and_neither_does_another_event(): void
    {
        $this->badgeFor();
        $other = $this->makeEvent();
        $student = $this->enrolled(event: $other);

        $this->scan($student, $other);

        $this->assertSame(0, BadgeAward::query()->count());
    }

    public function test_a_refused_credit_gives_no_badge(): void
    {
        $badge = $this->badgeFor();
        $stranger = $this->enrolled(accepted: false);

        try {
            $this->scan($stranger);
            $this->fail('A student without a place must be refused.');
        } catch (EventActionRefused $refused) {
            $this->assertSame('not_registered', $refused->reason);
        }

        $this->assertFalse($this->holds($stranger, $badge));
        $this->assertSame(0, PointTransaction::query()->count());
    }

    public function test_a_badge_adds_no_ledger_row_no_points_and_changes_no_rank(): void
    {
        $this->badgeFor();
        $plain = $this->makeEvent();
        $holder = $this->enrolled();
        $other = $this->enrolled(event: $plain);

        $this->scan($holder);
        $this->scan($other, $plain);

        // One ledger row each, with the event's points and nothing more.
        $this->assertSame(2, PointTransaction::query()->count());
        $this->assertSame(1, PointTransaction::query()->where('student_id', $holder->id)->count());
        $this->assertSame($this->event->points_value, $this->ledger()->totals($holder)['total']);
        // Same points as a student of an event without a badge: the same place on any leaderboard.
        $this->assertSame($this->ledger()->totals($other)['total'], $this->ledger()->totals($holder)['total']);

        // Awarding a badge to someone who has no points writes nothing and gives no points.
        $nobody = $this->enrolled(accepted: false);
        BadgeAward::create(['badge_id' => Badge::query()->value('id'), 'student_id' => $nobody->id, 'awarded_at' => now()]);

        $this->assertSame(0, $this->ledger()->totals($nobody)['total']);
        $this->assertSame(2, PointTransaction::query()->count());
    }

    public function test_a_badge_made_after_the_credits_goes_to_the_students_already_credited(): void
    {
        $credited = $this->enrolled();
        $takenBack = $this->enrolled();
        $notCredited = $this->enrolled();
        $this->scan($credited);
        $reversed = $this->scan($takenBack);
        $this->ledger()->reverse($reversed, $this->admin, 'Not there');

        $result = app(EventBadges::class)->save($this->organiser, $this->event, 'Late badge', null);

        $this->assertTrue($result['created']);
        $this->assertSame(1, $result['awarded']);
        $this->assertTrue($this->holds($credited, $result['badge']));
        $this->assertFalse($this->holds($takenBack, $result['badge']));
        $this->assertFalse($this->holds($notCredited, $result['badge']));
    }

    public function test_a_credit_taken_back_takes_the_badge_back_and_only_that_one(): void
    {
        $badge = $this->badgeFor();
        $wrong = $this->enrolled();
        $right = $this->enrolled();
        $credit = $this->scan($wrong);
        $this->scan($right);

        $this->ledger()->reverse($credit, $this->admin, 'Was not there');

        $this->assertFalse($this->holds($wrong, $badge));
        $this->assertTrue($this->holds($right, $badge));
    }

    public function test_a_reversal_takes_only_the_badge_of_that_event(): void
    {
        $first = $this->badgeFor($this->event, 'First');
        $other = $this->makeEvent();
        $second = $this->badgeFor($other, 'Second');
        $student = $this->enrolled();
        $this->accepted($student, $other);
        $credit = $this->scan($student);
        $this->scan($student, $other);

        $this->ledger()->reverse($credit, $this->admin, 'Was not there');

        $this->assertFalse($this->holds($student, $first));
        $this->assertTrue($this->holds($student, $second));
    }

    public function test_removing_the_event_takes_every_badge_back_with_the_points(): void
    {
        $badge = $this->badgeFor();
        $first = $this->enrolled();
        $second = $this->enrolled();
        $this->scan($first);
        $this->scan($second);

        app(EventModerator::class)->remove($this->event, $this->admin, 'Fake attendance');

        $this->assertSame(0, BadgeAward::query()->where('badge_id', $badge->id)->count());
        $this->assertSame(0, $this->ledger()->totals($first)['total']);
    }

    public function test_a_points_reset_keeps_the_badges(): void
    {
        $badge = $this->badgeFor();
        $student = $this->enrolled();
        $this->scan($student);

        $this->ledger()->resetAll($this->admin);

        $this->assertSame(0, $this->ledger()->totals($student)['total']);
        $this->assertTrue($this->holds($student, $badge));
    }

    public function test_removing_the_badge_takes_it_from_everybody_and_leaves_the_points(): void
    {
        $badge = $this->badgeFor();
        $student = $this->enrolled();
        $this->scan($student);

        $lost = app(EventBadges::class)->remove($this->event);

        $this->assertSame(1, $lost);
        $this->assertNull(Badge::query()->find($badge->id));
        $this->assertSame(0, BadgeAward::query()->count());
        $this->assertSame(10, $this->ledger()->totals($student)['total']);
        $this->assertSame(0, app(EventBadges::class)->remove($this->event), 'Removing a badge that is not there is not an error.');
    }

    public function test_the_database_keeps_one_badge_per_event(): void
    {
        $this->badgeFor();

        $this->expectException(UniqueConstraintViolationException::class);

        Badge::create(['event_id' => $this->event->id, 'name' => 'Another']);
    }

    public function test_the_database_keeps_one_award_per_student_and_badge(): void
    {
        $badge = $this->badgeFor();
        $student = $this->enrolled(accepted: false);
        BadgeAward::create(['badge_id' => $badge->id, 'student_id' => $student->id, 'awarded_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);

        BadgeAward::create(['badge_id' => $badge->id, 'student_id' => $student->id, 'awarded_at' => now()]);
    }

    public function test_deleting_a_draft_event_takes_its_badge_with_it(): void
    {
        $draft = Event::factory()->draft()->create(['owning_topic_id' => $this->course->id]);
        $this->badgeFor($draft);

        $draft->delete();

        $this->assertSame(0, Badge::query()->count());
    }
}
