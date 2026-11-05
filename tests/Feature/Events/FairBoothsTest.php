<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Jobs\ImportTopicStudentsJob;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\PointTransaction;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use App\Services\LiveAttendanceQr;
use App\Services\PointLedger;
use App\Services\QrPassport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Task #90 (26 October, recording 28:25): for a fair, a sub-area per booth with students assigned
 * to it. "Points only go to students assigned to that area." Every route that gives points is
 * tried here with the students of a fair:
 *
 *   University > Fair > Robotics booth, Coding booth
 *
 *   robo: Robotics booth; code: Coding booth; both: the two booths;
 *   fairOnly: the fair but no booth; nobody: no role here at all.
 */
class FairBoothsTest extends EventTestCase
{
    private Topic $fair;

    private Topic $robotics;

    private Topic $coding;

    private Event $roboticsEvent;

    private Event $codingEvent;

    private User $organiser;

    private User $robo;

    private User $code;

    private User $both;

    private User $fairOnly;

    private User $nobody;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fair = $this->topic('Fair', $this->university);
        $this->robotics = $this->topic('Robotics booth', $this->fair);
        $this->coding = $this->topic('Coding booth', $this->fair);

        $this->organiser = $this->staff([Capability::EventsCreate, Capability::EventsManage, Capability::PointsCredit, Capability::QrCodesManage], $this->fair, 'Olga Organiser');

        $this->robo = $this->member('ROBO01', [$this->robotics]);
        $this->code = $this->member('CODE02', [$this->coding]);
        $this->both = $this->member('BOTH03', [$this->robotics, $this->coding]);
        $this->fairOnly = $this->member('FAIR04', [$this->fair]);
        $this->nobody = $this->member('NONE05', []);

        $this->roboticsEvent = $this->boothEvent($this->robotics, 15);
        $this->codingEvent = $this->boothEvent($this->coding, 10);
    }

    /**
     * @param  list<Topic>  $booths
     */
    private function member(string $neptun, array $booths): User
    {
        $student = $this->student("Student {$neptun}", $neptun);

        foreach ($booths as $booth) {
            $this->assign($student, $this->studentRole(), $booth);
        }

        return $student;
    }

    private function boothEvent(Topic $booth, int $points): Event
    {
        return $this->makeEvent([
            'owning_topic_id' => $booth->id,
            'audience_topic_id' => $booth->id,
            'points_value' => $points,
            'application_deadline' => now()->addHour(),
        ]);
    }

    private function liveCode(Event $event): string
    {
        $live = app(LiveAttendanceQr::class);

        return $live->payload($live->open($this->organiser, $event));
    }

    private function slipCode(Event $event): string
    {
        $url = $this->actingAs($this->organiser)
            ->postJson("/events/{$event->id}/qr-tokens", ['type' => 'slip'])
            ->assertCreated()
            ->json('url');

        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    private function scan(User $student, string $code): TestResponse
    {
        return $this->actingAs($student)->postJson('/qr/redeem', ['code' => $code]);
    }

    private function scanPassport(User $student, Event $event): TestResponse
    {
        return $this->actingAs($this->organiser)
            ->postJson("/events/{$event->id}/credit", ['identifier' => app(QrPassport::class)->issue($student)]);
    }

    private function importList(Event $event, string ...$codes): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('list.csv', "Neptun Code\n".implode("\n", $codes)."\n");

        return $this->actingAs($this->organiser)
            ->post("/events/{$event->id}/points-import", ['file' => $file], ['Accept' => 'application/json']);
    }

    /**
     * @return list<string> Neptun codes of the students credited for the event
     */
    private function credited(Event $event): array
    {
        return PointTransaction::query()
            ->where('event_id', $event->id)
            ->where('type', PointTransaction::CREDIT)
            ->join('users', 'users.id', '=', 'point_transactions.student_id')
            ->orderBy('users.neptun_code')
            ->pluck('users.neptun_code')
            ->all();
    }

    private function endRole(User $student, Topic $booth): void
    {
        RoleAssignment::query()->active()
            ->where(['user_id' => $student->id, 'topic_id' => $booth->id])
            ->firstOrFail()
            ->forceFill(['ended_at' => now(), 'ended_reason' => 'Taken off the booth'])
            ->save();
    }

    public function test_a_booth_event_is_seen_and_applied_to_only_by_the_students_of_that_booth(): void
    {
        $seen = fn (User $student, Event $event) => Gate::forUser($student)->allows('view', $event);
        $apply = fn (User $student, Event $event) => $this->actingAs($student)->post("/events/{$event->id}/apply");
        $hasApplied = fn (User $student, Event $event) => EventApplication::where(['event_id' => $event->id, 'student_id' => $student->id])->exists();

        foreach ([$this->robo, $this->both] as $student) {
            $this->assertTrue($seen($student, $this->roboticsEvent));
            $apply($student, $this->roboticsEvent);
            $this->assertTrue($hasApplied($student, $this->roboticsEvent));
        }

        // The other booth, the fair above the booths, and no role at all: nothing flows on its own.
        foreach ([$this->code, $this->fairOnly, $this->nobody] as $student) {
            $this->assertFalse($seen($student, $this->roboticsEvent));
            $apply($student, $this->roboticsEvent)->assertRedirect('/');
            $this->assertFalse($hasApplied($student, $this->roboticsEvent));
        }

        $this->assertTrue($seen($this->both, $this->codingEvent));
        $this->assertFalse($seen($this->robo, $this->codingEvent));
    }

    public function test_the_live_code_gives_points_only_to_the_students_of_the_booth(): void
    {
        $this->accepted($this->robo, $this->roboticsEvent);
        $code = $this->liveCode($this->roboticsEvent);

        foreach ([$this->code, $this->fairOnly, $this->nobody] as $student) {
            $this->scan($student, $code)->assertForbidden()->assertJsonPath('reason', 'not_registered');
        }

        $this->scan($this->robo, $code)->assertOk()->assertJsonPath('outcome', 'credited')->assertJsonPath('points', 15);

        $this->assertSame(['ROBO01'], $this->credited($this->roboticsEvent));
    }

    public function test_a_printed_slip_gives_points_only_to_the_students_of_the_booth(): void
    {
        $this->accepted($this->robo, $this->roboticsEvent);
        $code = $this->slipCode($this->roboticsEvent);

        foreach ([$this->code, $this->fairOnly, $this->nobody] as $student) {
            $this->scan($student, $code)->assertForbidden()->assertJsonPath('reason', 'not_registered');
        }

        // Those refusals left the slip unused.
        $this->scan($this->robo, $code)->assertOk()->assertJsonPath('points', 15);

        $this->assertSame(['ROBO01'], $this->credited($this->roboticsEvent));
    }

    public function test_a_passport_scan_gives_points_only_to_the_students_of_the_booth(): void
    {
        $this->accepted($this->robo, $this->roboticsEvent);

        foreach ([$this->code, $this->fairOnly, $this->nobody] as $student) {
            $this->scanPassport($student, $this->roboticsEvent)->assertJsonPath('status', 'not_accepted');
        }

        $this->scanPassport($this->robo, $this->roboticsEvent)->assertJsonPath('status', 'credited');

        $this->assertSame(['ROBO01'], $this->credited($this->roboticsEvent));
    }

    public function test_an_imported_list_gives_points_only_to_the_students_of_the_booth(): void
    {
        $response = $this->importList($this->roboticsEvent, 'ROBO01', 'BOTH03', 'CODE02', 'FAIR04', 'NONE05')->assertOk();

        $response->assertJsonPath('credited.0.neptun_code', 'ROBO01')->assertJsonPath('credited.1.neptun_code', 'BOTH03');
        $this->assertSame(
            array_fill(4, 3, 'Not assigned to Robotics booth, the area of this event.'),
            collect($response->json('skipped'))->pluck('reason', 'line')->all(),
        );

        // A repeat adds no second credit.
        $this->importList($this->roboticsEvent, 'ROBO01', 'BOTH03')->assertOk()->assertJsonCount(0, 'credited')->assertJsonCount(2, 'already');

        $this->assertSame(['BOTH03', 'ROBO01'], $this->credited($this->roboticsEvent));
    }

    public function test_a_student_of_both_booths_earns_at_each_booth_once(): void
    {
        $this->accepted($this->both, $this->roboticsEvent);
        $this->accepted($this->both, $this->codingEvent);

        $this->scan($this->both, $this->liveCode($this->roboticsEvent))->assertOk();
        $this->scan($this->both, $this->liveCode($this->codingEvent))->assertOk();
        $this->scan($this->both, $this->liveCode($this->roboticsEvent))->assertStatus(409)->assertJsonPath('reason', 'already_claimed');

        $this->assertSame(25, app(PointLedger::class)->totals($this->both)['total']);
    }

    public function test_a_student_taken_off_the_booth_gets_no_points_by_any_route(): void
    {
        $this->accepted($this->robo, $this->roboticsEvent);
        $this->endRole($this->robo, $this->robotics);

        $this->scan($this->robo, $this->liveCode($this->roboticsEvent))->assertForbidden()->assertJsonPath('reason', 'left_area');

        $slip = $this->slipCode($this->roboticsEvent);
        $this->scan($this->robo, $slip)->assertForbidden()->assertJsonPath('reason', 'left_area');

        $this->scanPassport($this->robo, $this->roboticsEvent)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Student ROBO01 is no longer assigned to Robotics booth, so this event gives them no points.');

        $this->importList($this->roboticsEvent, 'ROBO01')->assertOk()
            ->assertJsonPath('skipped.0.reason', 'Not assigned to Robotics booth, the area of this event.');

        $this->assertSame([], $this->credited($this->roboticsEvent));
        // Their application is kept, and the refused scan left the slip for someone else.
        $this->assertTrue(EventApplication::where(['event_id' => $this->roboticsEvent->id, 'student_id' => $this->robo->id])->firstOrFail()->isAccepted());
        $this->accepted($this->both, $this->roboticsEvent);
        $this->scan($this->both, $slip)->assertOk();
        $this->assertSame(['BOTH03'], $this->credited($this->roboticsEvent));
    }

    public function test_a_student_put_back_on_the_booth_can_earn_again(): void
    {
        $this->accepted($this->robo, $this->roboticsEvent);
        $this->endRole($this->robo, $this->robotics);
        $code = $this->liveCode($this->roboticsEvent);

        $this->scan($this->robo, $code)->assertForbidden();

        $this->assign($this->robo, $this->studentRole(), $this->robotics);

        $this->scan($this->robo, $code)->assertOk()->assertJsonPath('points', 15);
    }

    public function test_a_role_that_no_longer_lets_the_student_see_events_counts_as_having_left(): void
    {
        $this->accepted($this->robo, $this->roboticsEvent);
        $this->endRole($this->robo, $this->robotics);
        $this->assign($this->robo, $this->viewer(), $this->robotics);

        $this->scan($this->robo, $this->liveCode($this->roboticsEvent))->assertForbidden()->assertJsonPath('reason', 'left_area');
    }

    public function test_a_wider_audience_keeps_those_who_applied_before_it_changed(): void
    {
        // Announced to the booth first, then to the whole fair (Task #64: an audience can only widen).
        $this->accepted($this->robo, $this->roboticsEvent);
        $this->roboticsEvent->update(['audience_topic_id' => $this->fair->id]);
        $this->accepted($this->fairOnly, $this->roboticsEvent);

        $code = $this->liveCode($this->roboticsEvent);
        $this->scan($this->robo, $code)->assertOk();
        $this->scan($this->fairOnly, $code)->assertOk();

        // Leaving the fair counts as leaving, for an event announced to the fair.
        $this->accepted($this->both, $this->codingEvent);
        $this->codingEvent->update(['audience_topic_id' => $this->fair->id]);
        $this->accepted($this->fairOnly, $this->codingEvent);
        $this->endRole($this->fairOnly, $this->fair);

        $code = $this->liveCode($this->codingEvent);
        $this->scan($this->fairOnly, $code)->assertForbidden()->assertJsonPath('reason', 'left_area');
        $this->scan($this->both, $code)->assertOk();
    }

    public function test_an_application_from_before_the_assignment_rule_is_left_alone(): void
    {
        // Before Task #71 a student could apply without a role at the audience itself, and never held one.
        $this->accepted($this->fairOnly, $this->roboticsEvent);

        $this->scan($this->fairOnly, $this->liveCode($this->roboticsEvent))->assertOk();
    }

    public function test_a_student_imported_at_a_booth_and_ticked_for_the_fair_stays_out_of_the_other_booth(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/booth.csv', "Neptun Code\nNONE05\n");

        (new ImportTopicStudentsJob('imports/booth.csv', $this->admin->id, $this->robotics->id, $this->studentRole()->id, [$this->fair->id]))->handle();

        $this->assertSame(
            ['Fair', 'Robotics booth'],
            RoleAssignment::query()->active()->where('user_id', $this->nobody->id)
                ->join('topics', 'topics.id', '=', 'role_assignments.topic_id')
                ->orderBy('topics.title')->pluck('topics.title')->all(),
        );
        $this->assertTrue(Gate::forUser($this->nobody)->allows('view', $this->roboticsEvent));
        $this->assertFalse(Gate::forUser($this->nobody)->allows('view', $this->codingEvent));
    }

    public function test_a_teacher_who_runs_the_fair_may_run_any_booth_but_a_student_of_a_booth_may_not(): void
    {
        $this->actingAs($this->organiser)->get("/events/{$this->codingEvent->id}/points-import")->assertOk();

        $this->actingAs($this->robo)->get("/events/{$this->roboticsEvent->id}/points-import")->assertRedirect('/');

        $boothTeacher = $this->staff([Capability::PointsCredit], $this->robotics, 'Booth Teacher');
        $this->assertTrue(Gate::forUser($boothTeacher)->allows('credit', $this->roboticsEvent));
        $this->assertFalse(Gate::forUser($boothTeacher)->allows('credit', $this->codingEvent));
    }
}
