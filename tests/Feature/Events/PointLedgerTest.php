<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\Topic;
use App\Models\User;
use App\Services\PointLedger;
use App\Services\TopicService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #59: one shared, append-only way of writing points.
 */
class PointLedgerTest extends TopicTestCase
{
    private Topic $faculty;

    private Topic $course;

    private Event $event;

    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculty = $this->topic('Faculty', $this->topic('University'));
        $this->course = $this->topic('Databases', $this->faculty);
        $this->event = $this->makeEvent();
        $this->organiser = $this->makeUser(UserRole::Teacher, 'Olga Organiser');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeEvent(array $data = []): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            ...$data,
        ]);
    }

    private function accepted(?Event $event = null, string $status = EventApplication::ACCEPTED): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => strtoupper(substr(md5(uniqid()), 0, 6))])->save();
        EventApplication::create([
            'event_id' => ($event ?? $this->event)->id,
            'student_id' => $student->id,
            'status' => $status,
            'applied_at' => now(),
        ]);

        return $student;
    }

    private function ledger(): PointLedger
    {
        return app(PointLedger::class);
    }

    private function refused(callable $do, string $reason): void
    {
        try {
            $do();
            $this->fail("Expected the refusal '{$reason}'.");
        } catch (EventActionRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_an_accepted_student_is_credited_with_the_event_points_and_a_category_snapshot(): void
    {
        $student = $this->accepted();

        $row = $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);

        $this->assertSame(10, $row->points);
        $this->assertSame('credit', $row->type);
        $this->assertSame('academic', $row->category);
        $this->assertSame($this->event->semester_id, $row->semester_id);
        $this->assertSame($this->organiser->id, $row->credited_by_id);
        $application = EventApplication::where('student_id', $student->id)->first();
        $this->assertNotNull($application->scored_at);
        $this->assertNotNull($application->completed_at);
    }

    public function test_a_later_category_change_never_rewrites_history(): void
    {
        $student = $this->accepted();
        $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);

        $this->event->update(['category' => 'sports']);

        $this->assertEquals(['total' => 10, 'academic' => 10, 'scientific' => 0, 'sports' => 0, 'community' => 0], $this->ledger()->totals($student));
    }

    public function test_a_second_credit_for_the_same_student_and_event_is_refused_whatever_the_route(): void
    {
        $student = $this->accepted();
        $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);

        foreach ([PointLedger::MANUAL, PointLedger::PASSPORT, PointLedger::QR_POSTER, PointLedger::QR_SLIP, PointLedger::MILESTONE] as $source) {
            $this->refused(fn () => $this->ledger()->credit($this->event, $student, $this->organiser, $source), 'already_credited');
        }

        $this->assertSame(1, PointTransaction::count());
    }

    public function test_the_database_guard_catches_a_credit_that_slips_past_the_application_check(): void
    {
        $student = $this->accepted();
        $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);

        // Simulate a lost race: the application row does not yet show the credit.
        EventApplication::where('student_id', $student->id)->update(['scored_at' => null]);

        $this->refused(fn () => $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL), 'already_credited');
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_nobody_can_credit_themselves(): void
    {
        $tutor = $this->accepted();

        $this->refused(fn () => $this->ledger()->credit($this->event, $tutor, $tutor, PointLedger::MANUAL), 'self_credit');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_whoever_helps_run_the_event_cannot_receive_its_points_by_any_route(): void
    {
        // A student tutor (team rule): accepted for the course's event, and able to credit its points.
        $tutor = $this->accepted();
        $this->assign($tutor, $this->role('Tutor', [Capability::View, Capability::EventsView, Capability::PointsCredit]), $this->course);
        // A role above the course that lets them create its events reaches the event too.
        $helper = $this->accepted();
        $this->assign($helper, $this->role('Event helper', [Capability::View, Capability::EventsView, Capability::EventsCreate]), $this->faculty);

        foreach ([$tutor, $helper] as $person) {
            foreach ([PointLedger::MANUAL, PointLedger::PASSPORT, PointLedger::QR_POSTER, PointLedger::QR_SLIP, PointLedger::MILESTONE] as $source) {
                $this->refused(fn () => $this->ledger()->credit($this->event, $person, $this->organiser, $source), 'runs_event');
            }

            $this->refused(fn () => $this->ledger()->creditImported($this->event, $person, $this->organiser), 'runs_event');
        }

        $this->assertSame(0, PointTransaction::count());
        $this->assertSame(0, EventApplication::query()->whereNotNull('scored_at')->count());
    }

    public function test_only_accepted_participants_can_be_credited(): void
    {
        foreach (['pending', 'waitlisted', 'rejected', 'withdrawn'] as $status) {
            $student = $this->accepted(status: $status);
            $this->refused(fn () => $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL), 'not_registered');
        }

        $stranger = $this->makeUser(UserRole::Student);
        $this->refused(fn () => $this->ledger()->credit($this->event, $stranger, $this->organiser, PointLedger::MANUAL), 'not_registered');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_credit_follows_the_event_state_and_window(): void
    {
        $cases = [
            'on_hold' => ['status' => 'on_hold'],
            'inactive' => ['status' => 'cancelled'],
            'not_started' => ['event_starts_at' => now()->addHour()],
            'deadline_passed' => ['completion_deadline' => now()->subMinute()],
        ];

        foreach ($cases as $reason => $changes) {
            $event = $this->makeEvent($changes);
            $student = $this->accepted($event);

            $this->refused(fn () => $this->ledger()->credit($event, $student, $this->organiser, PointLedger::MANUAL), $reason);
        }

        $draft = $this->makeEvent(['status' => 'draft']);
        $student = $this->accepted($draft);
        $this->refused(fn () => $this->ledger()->credit($draft, $student, $this->organiser, PointLedger::MANUAL), 'inactive');

        $completed = $this->makeEvent(['status' => 'completed']);
        $student = $this->accepted($completed);
        $this->assertSame(10, $this->ledger()->credit($completed, $student, $this->organiser, PointLedger::MANUAL)->points);
    }

    public function test_an_archived_topic_freezes_crediting(): void
    {
        $student = $this->accepted();
        app(TopicService::class)->archive($this->admin, $this->faculty);

        $this->refused(fn () => $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL), 'topic_archived');
    }

    public function test_an_inactive_student_cannot_be_credited(): void
    {
        $student = $this->accepted();
        $student->forceFill(['status' => 'inactive'])->save();

        $this->refused(fn () => $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL), 'student_inactive');
    }

    public function test_totals_are_summed_per_category_and_semester_from_the_ledger(): void
    {
        $student = $this->accepted();
        $sports = $this->makeEvent(['category' => 'sports', 'points_value' => 5]);
        EventApplication::create(['event_id' => $sports->id, 'student_id' => $student->id, 'status' => 'accepted', 'applied_at' => now()]);
        $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);
        $this->ledger()->credit($sports, $student, $this->organiser, PointLedger::MANUAL);

        $totals = $this->ledger()->totals($student);
        $this->assertSame(15, $totals['total']);
        $this->assertSame(10, $totals['academic']);
        $this->assertSame(5, $totals['sports']);
        $this->assertSame(0, $this->ledger()->totals($student, 999)['total']);
        $this->assertSame(15, $this->ledger()->totals($student, $this->event->semester_id)['total']);
        $this->assertFalse(Schema::hasColumn('users', 'points'), 'No balance column exists.');
    }

    public function test_a_system_admin_can_reverse_a_credit_once_and_the_original_stays(): void
    {
        $student = $this->accepted();
        $credit = $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);

        $reversal = $this->ledger()->reverse($credit, $this->admin, 'Entered by mistake');

        $this->assertSame(-10, $reversal->points);
        $this->assertSame('reversal', $reversal->type);
        $this->assertSame($credit->id, $reversal->reversal_of_id);
        $this->assertSame('Entered by mistake', $reversal->reversal_reason);
        $this->assertSame(10, $credit->fresh()->points);
        $this->assertSame(0, $this->ledger()->totals($student)['total']);

        $this->refused(fn () => $this->ledger()->reverse($credit, $this->admin, 'Again'), 'already_reversed');
        $this->refused(fn () => $this->ledger()->reverse($reversal, $this->admin, 'Reverse the reversal'), 'not_a_credit');
        // The base credit is still on record, so the student cannot be credited a second time either.
        $this->refused(fn () => $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL), 'already_credited');
    }

    public function test_only_a_system_admin_with_a_reason_can_reverse_and_never_in_a_closed_semester(): void
    {
        $student = $this->accepted();
        $credit = $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);

        $this->refused(fn () => $this->ledger()->reverse($credit, $this->organiser, 'Nope'), 'forbidden');
        $this->refused(fn () => $this->ledger()->reverse($credit, $this->admin, '  '), 'reason_required');

        DB::table('semesters')->where('id', $this->event->semester_id)->update(['status' => 'archived']);
        $this->refused(fn () => $this->ledger()->reverse($credit, $this->admin, 'Too late'), 'semester_closed');
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_ledger_rows_cannot_be_changed_or_deleted(): void
    {
        $student = $this->accepted();
        $row = $this->ledger()->credit($this->event, $student, $this->organiser, PointLedger::MANUAL);

        foreach ([fn () => $row->update(['points' => 99]), fn () => $row->delete(), fn () => $row->forceFill(['category' => 'sports'])->save()] as $change) {
            try {
                $change();
                $this->fail('A ledger row was changed.');
            } catch (LogicException) {
            }
        }

        $this->assertSame(10, $row->fresh()->points);
        $this->assertSame('academic', $row->fresh()->category);
    }

    public function test_credits_must_be_positive_and_reversals_negative(): void
    {
        $student = $this->accepted();
        $base = [
            'student_id' => $student->id, 'event_id' => $this->event->id, 'semester_id' => $this->event->semester_id,
            'credited_by_id' => $this->admin->id, 'source' => 'manual', 'category' => 'academic',
        ];

        foreach ([
            [...$base, 'type' => 'credit', 'points' => 0],
            [...$base, 'type' => 'credit', 'points' => -5],
            [...$base, 'type' => 'reversal', 'points' => 5, 'reversal_of_id' => 1],
            [...$base, 'type' => 'reversal', 'points' => 0, 'reversal_of_id' => 1],
        ] as $invalid) {
            try {
                PointTransaction::create($invalid);
                $this->fail('An invalid ledger row was written.');
            } catch (LogicException) {
            }
        }

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_the_database_allows_only_one_base_credit_even_when_written_directly(): void
    {
        $student = $this->accepted();
        $row = [
            'student_id' => $student->id, 'event_id' => $this->event->id, 'semester_id' => $this->event->semester_id,
            'credited_by_id' => $this->admin->id, 'points' => 10, 'type' => 'credit', 'source' => 'manual', 'category' => 'academic',
        ];
        DB::table('point_transactions')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('point_transactions')->insert([...$row, 'source' => 'qr_poster']);
    }
}
