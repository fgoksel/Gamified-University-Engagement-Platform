<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventCheckinSession;
use App\Models\PointTransaction;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\LiveAttendanceQr;
use App\Services\QrTokenService;
use Illuminate\Support\Carbon;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #80 (meeting of 26 Oct, section 3): the live attendance code changes every 10 seconds.
 * The server accepts a scan only from an accepted student, inside the points window, with the
 * current or the previous code (about 20 seconds), once per student and event.
 */
class LiveAttendanceQrTest extends TopicTestCase
{
    private Topic $course;

    private Topic $otherCourse;

    private User $organiser;

    private RoleDefinition $studentRole;

    private TopicTag $tag;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $faculty = $this->topic('Faculty of Informatics', $university);
        $this->course = $this->topic('Databases', $faculty);
        $this->otherCourse = $this->topic('Programming', $faculty);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'scientific', 'is_active' => true]);
        $this->organiser = $this->makeUser(UserRole::Teacher, 'Olga Organiser');
        $this->assign($this->organiser, $this->role('Organiser', [Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage, Capability::QrCodesManage, Capability::PointsCredit]), $this->course);
        $this->studentRole = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);

        // An event that started an hour ago and ends in two: the middle of the event.
        $this->event = $this->event();

        // Stand still inside a 10-second step, three seconds in, so no test runs over a boundary.
        $this->travelTo(Carbon::createFromTimestamp(intdiv(time(), 10) * 10 + 3));
    }

    private function event(array $attributes = []): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'topic_tag_id' => $this->tag->id,
            'category' => 'scientific',
            'capacity' => 20,
            'points_value' => 15,
            'qr_enabled' => true,
            'event_starts_at' => now()->subHour(),
            'event_ends_at' => now()->addHours(2),
            'application_deadline' => now()->subHours(2),
            'completion_deadline' => now()->addDays(5),
            ...$attributes,
        ]);
    }

    private function student(string $neptun, ?string $status = EventApplication::ACCEPTED, ?Event $event = null): User
    {
        $student = $this->makeUser(UserRole::Student, "Student {$neptun}");
        $student->forceFill(['neptun_code' => $neptun])->save();
        $this->assign($student, $this->studentRole, $this->course);

        if ($status !== null) {
            EventApplication::create(['event_id' => ($event ?? $this->event)->id, 'student_id' => $student->id, 'status' => $status, 'applied_at' => now()]);
        }

        return $student;
    }

    private function live(): LiveAttendanceQr
    {
        return app(LiveAttendanceQr::class);
    }

    /** Check-in opened by the organiser; the code showing now. */
    private function open(?Event $event = null): string
    {
        $event ??= $this->event;
        $session = $this->live()->open($this->organiser, $event);

        return $this->live()->payload($session);
    }

    private function scan(User $student, string $code)
    {
        return $this->actingAs($student)->postJson('/qr/redeem', ['code' => $code]);
    }

    public function test_the_code_has_a_session_prefix_a_time_step_and_a_signature(): void
    {
        $code = $this->open();

        $this->assertMatchesRegularExpression('/^LV1\.\d+\.\d+\.[a-f0-9]{32}$/', $code);
        $this->assertSame(intdiv(now()->getTimestamp(), 10), (int) explode('.', $code)[2]);
    }

    public function test_the_code_changes_every_ten_seconds_and_not_before(): void
    {
        $session = $this->live()->open($this->organiser, $this->event);
        $first = $this->live()->payload($session);

        $this->travel(6)->seconds(); // still the same step
        $this->assertSame($first, $this->live()->payload($session));

        $this->travel(5)->seconds(); // into the next step
        $this->assertNotSame($first, $this->live()->payload($session));
    }

    public function test_a_student_checks_in_with_the_code_that_is_showing(): void
    {
        $student = $this->student('LIVE01');

        $this->scan($student, $this->open())->assertOk()
            ->assertJson(['outcome' => 'credited', 'points' => 15, 'category' => 'scientific', 'event' => $this->event->title]);

        $row = PointTransaction::query()->where('event_id', $this->event->id)->where('student_id', $student->id)->sole();
        $this->assertSame('qr_live', $row->source);
        $this->assertSame($this->organiser->id, $row->credited_by_id);
    }

    public function test_the_code_that_was_showing_a_moment_ago_still_works(): void
    {
        $student = $this->student('LIVE02');
        $code = $this->open();

        $this->travel(10)->seconds(); // one step later: about 10 to 20 seconds old

        $this->scan($student, $code)->assertOk()->assertJson(['outcome' => 'credited']);
    }

    public function test_a_code_older_than_about_twenty_seconds_is_refused(): void
    {
        $student = $this->student('LIVE03');
        $code = $this->open();

        $this->travel(20)->seconds(); // two steps later

        $this->scan($student, $code)->assertStatus(409)->assertJson(['reason' => 'expired']);
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_a_photo_of_the_code_stops_working_within_the_time_a_friend_needs_to_use_it(): void
    {
        $absent = $this->student('LIVE04');
        $code = $this->open();

        $this->travel(30)->seconds();

        $this->scan($absent, $code)->assertStatus(409)->assertJson(['reason' => 'expired']);
    }

    public function test_a_second_scan_adds_no_credit(): void
    {
        $student = $this->student('LIVE05');
        $code = $this->open();

        $this->scan($student, $code)->assertOk();
        $this->scan($student, $code)->assertStatus(409)->assertJson(['reason' => 'already_claimed']);

        // The next code does not give a second award either.
        $this->travel(10)->seconds();
        $this->scan($student, $this->live()->payload($this->live()->session($this->event)))->assertStatus(409);

        $this->assertSame(1, PointTransaction::query()->where('student_id', $student->id)->count());
    }

    public function test_a_made_up_altered_or_future_code_is_invalid(): void
    {
        $student = $this->student('LIVE06');
        $session = $this->live()->open($this->organiser, $this->event);
        $slot = intdiv(now()->getTimestamp(), 10);
        $real = $this->live()->payload($session);

        foreach ([
            'LV1.'.$session->id.'.'.$slot.'.'.str_repeat('0', 32), // not signed
            'LV1.'.$session->id.'.'.($slot - 1).'.'.explode('.', $real)[3], // signature of another step
            'LV1.999.'.$slot.'.'.explode('.', $real)[3], // another session
            'LV1.'.$session->id, // cut
            'LV1.x.y.z',
            $this->live()->payload($session, $slot + 5), // a code from the future
        ] as $code) {
            $this->scan($student, $code)->assertStatus(422)->assertJson(['reason' => 'invalid']);
        }
    }

    public function test_the_codes_of_one_event_cannot_be_used_for_another(): void
    {
        $other = $this->event();
        $student = $this->student('LIVE07', event: $other);
        $this->student('LIVE08'); // accepted for the first event only

        $code = $this->open($this->event);

        // Accepted for the other event only: the code belongs to the first one.
        $this->scan($student, $code)->assertStatus(403)->assertJson(['reason' => 'not_registered']);
    }

    public function test_only_accepted_students_get_points(): void
    {
        $code = $this->open();

        foreach ([EventApplication::PENDING, EventApplication::WAITLISTED, EventApplication::REJECTED, EventApplication::WITHDRAWN, null] as $n => $status) {
            $this->scan($this->student('NOT0'.$n.'A', $status), $code)
                ->assertStatus(403)->assertJson(['reason' => 'not_registered', 'message' => 'You are not registered or selected for this event.']);
        }

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_points_cannot_be_given_before_the_event_starts_or_after_the_points_end(): void
    {
        $student = $this->student('LIVE09', event: null);

        $future = $this->event(['event_starts_at' => now()->addHours(3), 'event_ends_at' => now()->addHours(5), 'application_deadline' => now()->addHour()]);
        EventApplication::create(['event_id' => $future->id, 'student_id' => $student->id, 'status' => 'accepted', 'applied_at' => now()]);
        $this->scan($student, $this->open($future))->assertStatus(409)->assertJson(['reason' => 'not_started']);

        $over = $this->event(['event_starts_at' => now()->subHours(9), 'event_ends_at' => now()->subHours(7), 'application_deadline' => now()->subHours(10), 'completion_deadline' => now()->subHour()]);
        EventApplication::create(['event_id' => $over->id, 'student_id' => $student->id, 'status' => 'accepted', 'applied_at' => now()]);
        $this->scan($student, $this->open($over))->assertStatus(409)->assertJson(['reason' => 'deadline_passed']);

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_an_event_on_hold_or_without_qr_codes_takes_no_check_in(): void
    {
        $student = $this->student('LIVE10');
        $code = $this->open();

        $this->event->update(['status' => Event::STATUS_ON_HOLD]);
        $this->scan($student, $code)->assertStatus(409);

        $plain = $this->event(['qr_enabled' => false]);
        $this->expectExceptionMessage('QR codes are not enabled for this event.');
        $this->live()->open($this->organiser, $plain);
    }

    public function test_the_person_showing_the_code_cannot_check_in_with_it(): void
    {
        // A student tutor who shows the code for their group is also an accepted student of the event.
        $tutor = $this->student('TUTOR1');
        $this->assign($tutor, $this->role('Tutor', [Capability::View, Capability::EventsView, Capability::QrCodesManage]), $this->course);
        $session = $this->live()->open($tutor, $this->event);

        $this->scan($tutor, $this->live()->payload($session))->assertStatus(403)->assertJson(['reason' => 'own_code']);
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_pausing_hides_the_code_and_stops_acceptance_until_it_is_shown_again(): void
    {
        $student = $this->student('LIVE11');
        $code = $this->open();

        $this->actingAs($this->organiser)->postJson("/events/{$this->event->id}/checkin/pause")
            ->assertOk()->assertJson(['open' => false, 'paused' => true, 'code' => null, 'url' => null, 'image' => null]);
        $this->scan($student, $code)->assertStatus(409)->assertJson(['reason' => 'paused']);

        $this->actingAs($this->organiser)->postJson("/events/{$this->event->id}/checkin/open")
            ->assertOk()->assertJson(['open' => true, 'paused' => false]);
        $this->scan($student, $this->live()->payload($this->live()->session($this->event)))->assertOk();
    }

    public function test_stopping_ends_the_codes_and_a_new_session_has_new_codes(): void
    {
        $student = $this->student('LIVE12');
        $code = $this->open();
        $old = $this->live()->session($this->event);

        $this->actingAs($this->organiser)->postJson("/events/{$this->event->id}/checkin/stop")->assertOk()->assertJson(['open' => false]);
        $this->scan($student, $code)->assertStatus(409)->assertJson(['reason' => 'closed']);

        $this->actingAs($this->organiser)->postJson("/events/{$this->event->id}/checkin/open")->assertOk();
        $new = $this->live()->session($this->event);

        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($old->secret, $new->secret);
        $this->scan($student, $code)->assertStatus(409)->assertJson(['reason' => 'closed']); // the old code is dead for good
        $this->assertSame(2, EventCheckinSession::count());
    }

    public function test_opening_twice_keeps_one_session(): void
    {
        $this->open();
        $this->open();

        $this->assertSame(1, EventCheckinSession::count());
    }

    public function test_the_organiser_screen_gets_the_code_the_time_left_and_the_counter(): void
    {
        $this->student('LIVE13');
        $checked = $this->student('LIVE14');
        $this->student('LIVE15');
        $code = $this->open();
        $this->scan($checked, $code)->assertOk();

        $this->actingAs($this->organiser)->getJson("/events/{$this->event->id}/checkin")
            ->assertOk()
            ->assertJson([
                'open' => true,
                'paused' => false,
                'code' => $code,
                'step_seconds' => 10,
                'seconds_left' => 7, // three seconds into the step
                'checked_in' => 1,
                'accepted' => 3,
            ])
            ->assertJsonPath('url', route('qr.show', ['code' => $code]))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_code_is_drawn_as_a_picture_while_check_in_is_open(): void
    {
        $this->open();

        $this->actingAs($this->organiser)->get("/events/{$this->event->id}/checkin/image.svg")
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);

        $this->actingAs($this->organiser)->postJson("/events/{$this->event->id}/checkin/pause");
        $this->actingAs($this->organiser)->get("/events/{$this->event->id}/checkin/image.svg")->assertNotFound();
    }

    public function test_only_people_with_the_qr_capability_at_the_topic_control_check_in(): void
    {
        $student = $this->student('LIVE16');
        $creator = $this->makeUser(UserRole::Teacher, 'Only creates');
        $this->assign($creator, $this->role('Creator', [Capability::View, Capability::EventsView, Capability::EventsCreate]), $this->course);
        $elsewhere = $this->makeUser(UserRole::Teacher, 'Other branch');
        $this->assign($elsewhere, $this->role('Other organiser', [Capability::View, Capability::EventsView, Capability::QrCodesManage]), $this->otherCourse);

        foreach ([$student, $creator, $elsewhere] as $person) {
            foreach (['open', 'pause', 'stop'] as $action) {
                $this->actingAs($person)->postJson("/events/{$this->event->id}/checkin/{$action}")->assertForbidden();
            }
            $this->actingAs($person)->getJson("/events/{$this->event->id}/checkin")->assertForbidden();
        }

        $this->assertSame(0, EventCheckinSession::count());
    }

    public function test_the_student_page_only_needs_to_know_whether_check_in_is_open(): void
    {
        $student = $this->student('LIVE17');
        $url = "/events/{$this->event->id}/checkin/status";

        $this->actingAs($student)->getJson($url)->assertOk()->assertExactJson(['open' => false, 'accepted' => true, 'credited' => false, 'step_seconds' => 10]);

        $code = $this->open();
        $this->actingAs($student)->getJson($url)->assertOk()->assertJson(['open' => true]);

        $this->scan($student, $code)->assertOk();
        $this->actingAs($student)->getJson($url)->assertJson(['open' => true, 'accepted' => true, 'credited' => true]);

        // The status never carries the code itself.
        $this->assertStringNotContainsString('LV1', $this->actingAs($student)->getJson($url)->getContent());
    }

    public function test_a_student_who_may_not_see_the_event_cannot_ask_about_it(): void
    {
        $outsider = $this->makeUser(UserRole::Student);
        $this->assign($outsider, $this->studentRole, $this->otherCourse);

        $this->actingAs($outsider)->getJson("/events/{$this->event->id}/checkin/status")->assertNotFound();
    }

    public function test_opening_the_link_of_a_live_code_shows_the_scan_page_and_gives_no_points(): void
    {
        $student = $this->student('LIVE18');
        $code = $this->open();

        $this->actingAs($student)->get(route('qr.show', ['code' => $code]))->assertOk();

        $this->assertSame(0, PointTransaction::count());
    }

    public function test_the_other_qr_codes_still_work_next_to_the_live_one(): void
    {
        $student = $this->student('LIVE19');
        $token = app(QrTokenService::class)->issue($this->organiser, $this->event);
        $poster = app(QrTokenService::class)->payload($token);

        // The confirmation shows the points and their category for every kind of code (Task #82).
        $this->scan($student, $poster)->assertOk()->assertJson(['outcome' => 'credited', 'points' => 15, 'category' => 'scientific']);
        $this->scan($student, $this->open())->assertStatus(409)->assertJson(['reason' => 'already_claimed']);
    }
}
