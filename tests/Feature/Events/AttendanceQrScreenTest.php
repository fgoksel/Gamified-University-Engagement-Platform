<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventCheckinSession;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\LiveAttendanceQr;
use App\Services\PointLedger;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #81 (meeting of 26 Oct, section 3): the organiser presses "Show attendance QR" at the moment
 * they choose and a full-screen code appears for a projector or their own phone. The code changes
 * every few seconds with a visible countdown, a live counter shows how many accepted students have
 * checked in, and the organiser can pause or stop at any time. Anyone with "Manage event QR codes"
 * on the event's node can show the code.
 */
class AttendanceQrScreenTest extends TopicTestCase
{
    private Topic $faculty;

    private Topic $course;

    private User $organiser;

    private RoleDefinition $studentRole;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $university);
        $this->course = $this->topic('Databases', $this->faculty);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->organiser = $this->makeUser(UserRole::Teacher, 'Olga Organiser');
        $this->assign($this->organiser, $this->role('Organiser', [Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage, Capability::QrCodesManage, Capability::PointsCredit]), $this->course);
        $this->studentRole = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);

        // An event that started an hour ago and ends in two: the middle of the event.
        $this->event = $this->event();

        // Three seconds into a five-second step, so no test runs over the moment a code changes.
        $step = app(LiveAttendanceQr::class)->step();
        $this->travelTo(Carbon::createFromTimestamp(intdiv(time(), $step) * $step + 3));
    }

    private function event(array $attributes = []): Event
    {
        $tag = TopicTag::firstOrCreate(['name' => 'SQL'], ['category' => 'scientific', 'is_active' => true]);

        return Event::factory()->create([
            'title' => 'Database lab',
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'topic_tag_id' => $tag->id,
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

    private function student(string $neptun, ?string $status = EventApplication::ACCEPTED): User
    {
        $student = $this->makeUser(UserRole::Student, "Student {$neptun}");
        $student->forceFill(['neptun_code' => $neptun])->save();
        $this->assign($student, $this->studentRole, $this->course);

        if ($status !== null) {
            EventApplication::create(['event_id' => $this->event->id, 'student_id' => $student->id, 'status' => $status, 'applied_at' => now()]);
        }

        return $student;
    }

    private function screen(): string
    {
        return "/events/{$this->event->id}/checkin/screen";
    }

    public function test_the_screen_opens_without_showing_a_code(): void
    {
        $this->actingAs($this->organiser)->get($this->screen())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Screen/AttendanceQr')
                ->where('event.title', 'Database lab')
                ->where('event.points', 15)
                ->where('checkin.open', false)
                ->where('checkin.paused', false)
                ->where('checkin.code', null)
                ->where('checkin.step_seconds', 5));

        $this->assertSame(0, EventCheckinSession::count());
    }

    public function test_show_attendance_qr_opens_check_in_and_goes_to_the_screen(): void
    {
        $this->actingAs($this->organiser)->post($this->screen())
            ->assertRedirect(route('events.checkin.screen', $this->event));

        $session = EventCheckinSession::sole();
        $this->assertTrue($session->isOpen());
        $this->assertTrue($session->issuer->is($this->organiser));

        $this->actingAs($this->organiser)->get($this->screen())
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkin.open', true)
                ->where('checkin.code', app(LiveAttendanceQr::class)->payload($session))
                ->where('checkin.ms_left', 2000) // three seconds into a five-second step
                ->where('checkin.refusal', null));
    }

    public function test_show_attendance_qr_shows_a_paused_code_again(): void
    {
        $live = app(LiveAttendanceQr::class);
        $session = $live->open($this->organiser, $this->event);
        $live->pause($this->organiser, $this->event);

        $this->actingAs($this->organiser)->post($this->screen())->assertRedirect();

        $this->assertTrue($session->fresh()->isOpen());
        $this->assertSame(1, EventCheckinSession::count());
    }

    public function test_pause_and_stop_on_the_screen_hide_the_code(): void
    {
        $this->actingAs($this->organiser)->post($this->screen());
        $base = "/events/{$this->event->id}/checkin";

        $this->postJson("{$base}/pause")->assertOk()->assertJson(['open' => false, 'paused' => true, 'code' => null, 'ms_left' => null]);
        $this->get("{$base}/image.svg")->assertNotFound();

        $this->postJson("{$base}/open")->assertOk()->assertJson(['open' => true, 'ms_left' => 2000]);
        $this->postJson("{$base}/stop")->assertOk()->assertJson(['open' => false, 'paused' => false, 'code' => null]);
        $this->assertNotNull(EventCheckinSession::sole()->closed_at);
    }

    public function test_someone_else_with_the_qr_right_on_a_parent_node_can_show_the_code(): void
    {
        // A tutor can stand in for the creator, who has no special rights; a right on a node covers the nodes below.
        $tutor = $this->makeUser(UserRole::Teacher, 'Tom Tutor');
        $this->assign($tutor, $this->role('QR helper', [Capability::View, Capability::EventsView, Capability::QrCodesManage]), $this->faculty);

        $this->actingAs($tutor)->get($this->screen())->assertOk();
        $this->actingAs($tutor)->post($this->screen())->assertRedirect(route('events.checkin.screen', $this->event));

        $this->assertTrue(EventCheckinSession::sole()->issuer->is($tutor));
    }

    public function test_staff_without_the_qr_right_are_refused(): void
    {
        $crediter = $this->makeUser(UserRole::Teacher, 'Cora Crediter');
        $this->assign($crediter, $this->role('Crediter', [Capability::View, Capability::EventsView, Capability::PointsCredit]), $this->course);

        // A refused page goes back home with a message (bootstrap/app.php); the JSON behind it says 403.
        $this->actingAs($crediter)->get($this->screen())
            ->assertRedirect('/')->assertSessionHas('error', 'You do not have permission to view this page.');
        $this->actingAs($crediter)->post($this->screen())->assertRedirect('/');
        $this->actingAs($crediter)->getJson("/events/{$this->event->id}/checkin")->assertForbidden();

        $this->assertSame(0, EventCheckinSession::count());
    }

    public function test_students_and_teachers_of_other_topics_do_not_find_the_screen(): void
    {
        $student = $this->student('SCRN01');
        $other = $this->makeUser(UserRole::Teacher, 'Otto Other');
        $this->assign($other, $this->role('Other organiser', [Capability::View, Capability::EventsView, Capability::QrCodesManage]), $this->topic('Programming', $this->faculty));

        foreach ([$student, $other] as $user) {
            $this->actingAs($user)->get($this->screen())->assertNotFound();
            $this->actingAs($user)->post($this->screen())->assertNotFound();
        }

        $this->assertSame(0, EventCheckinSession::count());
    }

    public function test_a_refused_start_goes_back_with_the_reason(): void
    {
        $this->event->forceFill(['qr_enabled' => false])->save();

        $this->actingAs($this->organiser)->from('/my-events')->post($this->screen())
            ->assertRedirect('/my-events')
            ->assertSessionHas('error', 'QR codes are not enabled for this event.');

        $this->assertSame(0, EventCheckinSession::count());
    }

    public function test_the_screen_says_when_scans_do_not_count(): void
    {
        $this->actingAs($this->organiser)->getJson("/events/{$this->event->id}/checkin")->assertJson(['refusal' => null]);

        $this->event = $this->event(['event_starts_at' => now()->addHours(3), 'event_ends_at' => now()->addHours(5), 'application_deadline' => now()->addHour()]);
        $this->actingAs($this->organiser)->get($this->screen())
            ->assertInertia(fn (Assert $page) => $page->where('checkin.refusal', 'The event has not started yet.'));

        $this->event = $this->event(['event_starts_at' => now()->subHours(9), 'event_ends_at' => now()->subHours(7), 'application_deadline' => now()->subHours(10), 'completion_deadline' => now()->subHour()]);
        $this->actingAs($this->organiser)->getJson("/events/{$this->event->id}/checkin")
            ->assertJson(['refusal' => "This event's completion deadline has passed."]);
    }

    public function test_the_counter_counts_accepted_students_who_checked_in(): void
    {
        $checked = $this->student('SCRN02');
        $this->student('SCRN03');
        $this->student('SCRN04', EventApplication::REJECTED);
        $imported = $this->student('SCRN05', null);

        $code = app(LiveAttendanceQr::class)->payload(app(LiveAttendanceQr::class)->open($this->organiser, $this->event));
        $this->actingAs($checked)->postJson('/qr/redeem', ['code' => $code])->assertOk();

        // Credited by an import without applying: not one of the accepted students.
        app(PointLedger::class)->creditImported($this->event, $imported, $this->organiser);

        $this->actingAs($this->organiser)->getJson("/events/{$this->event->id}/checkin")
            ->assertOk()
            ->assertJson(['checked_in' => 1, 'accepted' => 2]);
    }
}
