<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\EventEditor;
use App\Services\PointLedger;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #78 (meeting of 26 Oct, Q3): every event has three windows. Applications open on a date
 * the organiser may set and close any time up to the end of the event; the event runs from its
 * start to its end; points can be given from the start until a date the organiser sets.
 */
class EventDateWindowsTest extends TopicTestCase
{
    private Topic $course;

    private User $organiser;

    private RoleDefinition $studentRole;

    private TopicTag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $faculty = $this->topic('Faculty of Informatics', $university);
        $this->course = $this->topic('Databases', $faculty);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'scientific', 'is_active' => true]);
        $this->organiser = $this->makeUser(UserRole::Teacher, 'Organiser');
        $this->assign($this->organiser, $this->role('Organiser', [Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage, Capability::PointsCredit]), $this->course);
        $this->studentRole = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
    }

    private function student(string $neptun): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => $neptun])->save();
        $this->assign($student, $this->studentRole, $this->course);

        return $student;
    }

    /** An event with its three windows given relative to now, in hours. */
    private function event(array $hours, array $attributes = []): Event
    {
        $at = fn (string $key) => isset($hours[$key]) ? now()->addHours($hours[$key])->startOfMinute() : null;

        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'topic_tag_id' => $this->tag->id,
            'category' => 'scientific',
            'capacity' => 10,
            'application_type' => 'fcfs',
            'applications_open_at' => $at('open'),
            'event_starts_at' => $at('start'),
            'event_ends_at' => $at('end'),
            'application_deadline' => $at('close'),
            'completion_deadline' => $at('points_end'),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        $fmt = fn (int $hours) => now()->addHours($hours)->format('Y-m-d\TH:i');

        return [
            'action' => 'publish',
            'title' => 'Open day',
            'description' => 'People can still register while it runs.',
            'topic_tag_id' => $this->tag->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'capacity' => 25,
            'application_type' => 'fcfs',
            'points_value' => 10,
            'applications_open_at' => $fmt(2),
            'event_starts_at' => $fmt(10),
            'event_ends_at' => $fmt(14),
            'application_deadline' => $fmt(12),
            'completion_deadline' => $fmt(100),
            'is_hidden' => false,
            'reveal_at' => null,
            'qr_enabled' => true,
            ...$overrides,
        ];
    }

    private function apply(User $student, Event $event)
    {
        return $this->actingAs($student)->post("/events/{$event->id}/apply");
    }

    public function test_the_form_accepts_applications_that_stay_open_while_the_event_runs(): void
    {
        $this->actingAs($this->organiser)->post('/events', $this->form())->assertSessionHasNoErrors();

        $event = Event::query()->latest('id')->first();
        $this->assertNotNull($event->applications_open_at);
        $this->assertTrue($event->application_deadline->gt($event->event_starts_at));
        $this->assertTrue($event->application_deadline->lte($event->event_ends_at));
    }

    public function test_the_date_to_open_applications_is_optional(): void
    {
        $this->actingAs($this->organiser)->post('/events', $this->form(['applications_open_at' => null]))->assertSessionHasNoErrors();

        $this->assertNull(Event::query()->latest('id')->first()->applications_open_at);
    }

    public function test_impossible_orders_of_the_dates_are_refused_by_the_server(): void
    {
        $fmt = fn (int $hours) => now()->addHours($hours)->format('Y-m-d\TH:i');
        $post = fn (array $changes) => $this->actingAs($this->organiser)->post('/events', $this->form($changes));

        // Applications cannot close after the event ended.
        $post(['application_deadline' => $fmt(15)])->assertSessionHasErrors(['application_deadline' => 'Applications must close before the event ends.']);
        // They cannot open at or after the moment they close.
        $post(['applications_open_at' => $fmt(12)])->assertSessionHasErrors(['applications_open_at' => 'Applications must open before they close.']);
        $post(['applications_open_at' => $fmt(13)])->assertSessionHasErrors('applications_open_at');
        // The event must end after it starts, and points must not end before the event does.
        $post(['event_ends_at' => $fmt(10)])->assertSessionHasErrors('event_ends_at');
        $post(['completion_deadline' => $fmt(13)])->assertSessionHasErrors('completion_deadline');

        $this->assertSame(0, Event::count());
    }

    public function test_the_end_of_the_points_window_has_no_default_and_no_link_to_the_semester(): void
    {
        $post = fn (array $changes) => $this->actingAs($this->organiser)->post('/events', $this->form($changes));

        // The organiser must choose it...
        $post(['completion_deadline' => null])->assertSessionHasErrors('completion_deadline');

        // ...and it may lie after the end of the semester (the semester in this test ends in three months).
        $post(['completion_deadline' => now()->addMonths(8)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertTrue(Event::query()->latest('id')->first()->completion_deadline->gt(Semester::first()->ends_at));
    }

    public function test_the_edit_page_keeps_the_same_rules(): void
    {
        $event = $this->event(['open' => -5, 'start' => 24, 'end' => 28, 'close' => 26, 'points_end' => 200]);
        $send = fn (array $changes) => $this->actingAs($this->organiser)->put("/events/{$event->id}", [
            ...$this->form(),
            'version' => app(EventEditor::class)->version($event->refresh()),
            'applications_open_at' => now()->subHours(5)->format('Y-m-d\TH:i'),
            'event_starts_at' => $event->event_starts_at->format('Y-m-d\TH:i'),
            'event_ends_at' => $event->event_ends_at->format('Y-m-d\TH:i'),
            'application_deadline' => $event->application_deadline->format('Y-m-d\TH:i'),
            'completion_deadline' => $event->completion_deadline->format('Y-m-d\TH:i'),
            ...$changes,
        ]);

        $send(['application_deadline' => now()->addHours(29)->format('Y-m-d\TH:i')])->assertSessionHasErrors('application_deadline');
        $send(['applications_open_at' => now()->addHours(26)->format('Y-m-d\TH:i')])->assertSessionHasErrors('applications_open_at');
        $send(['applications_open_at' => now()->addHours(1)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();

        $this->assertTrue($event->refresh()->applications_open_at->isFuture());
    }

    public function test_a_page_opened_before_the_field_existed_keeps_the_opening_date(): void
    {
        $event = $this->event(['open' => 3, 'start' => 24, 'end' => 28, 'close' => 26, 'points_end' => 200]);
        $payload = $this->form();
        unset($payload['applications_open_at'], $payload['version']);
        $payload['event_starts_at'] = $event->event_starts_at->format('Y-m-d\TH:i');
        $payload['event_ends_at'] = $event->event_ends_at->format('Y-m-d\TH:i');
        $payload['application_deadline'] = $event->application_deadline->format('Y-m-d\TH:i');
        $payload['completion_deadline'] = $event->completion_deadline->format('Y-m-d\TH:i');

        $before = $event->applications_open_at;
        $this->actingAs($this->organiser)->put("/events/{$event->id}", $payload)->assertSessionHasNoErrors();

        $this->assertTrue($event->refresh()->applications_open_at->equalTo($before));
    }

    public function test_nobody_can_apply_before_applications_open(): void
    {
        $event = $this->event(['open' => 3, 'start' => 24, 'end' => 28, 'close' => 26, 'points_end' => 200]);
        $student = $this->student('OPEN01');

        $this->apply($student, $event)->assertSessionHasErrors(['event' => 'Applications for this event are not open yet.']);
        $this->assertSame(0, EventApplication::count());

        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.can_apply', false));
    }

    public function test_an_event_without_an_opening_date_is_open_from_publishing(): void
    {
        // Every event made before this task has no opening date and keeps working as it did.
        $event = $this->event(['start' => 24, 'end' => 28, 'close' => 26, 'points_end' => 200], ['published_at' => now()->subDay()]);
        $this->assertNull($event->applications_open_at);

        $this->apply($this->student('OLD001'), $event)->assertSessionHasNoErrors();
        $this->assertSame(1, EventApplication::count());
    }

    public function test_a_student_can_apply_while_the_event_runs_and_is_accepted_at_once(): void
    {
        // The open day: the event started an hour ago, applications stay open for two more hours.
        $event = $this->event(['open' => -48, 'start' => -1, 'end' => 3, 'close' => 2, 'points_end' => 200]);
        $student = $this->student('LATE01');

        $this->apply($student, $event)->assertSessionHasNoErrors();

        $this->assertSame(EventApplication::ACCEPTED, EventApplication::first()->status);
        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.my_status', 'accepted'));
    }

    public function test_on_a_selection_event_a_late_applicant_is_pending_until_accepted(): void
    {
        $event = $this->event(['open' => -48, 'start' => -1, 'end' => 3, 'close' => 2, 'points_end' => 200], ['application_type' => 'teacher_select']);

        $this->apply($this->student('SEL001'), $event)->assertSessionHasNoErrors();

        $this->assertSame(EventApplication::PENDING, EventApplication::first()->status);
    }

    public function test_a_student_who_applied_during_the_event_can_be_credited_straight_away(): void
    {
        $event = $this->event(['open' => -48, 'start' => -1, 'end' => 3, 'close' => 2, 'points_end' => 200]);
        $student = $this->student('LATE02');
        $this->apply($student, $event);

        $row = app(PointLedger::class)->credit($event, $student, $this->organiser, PointLedger::PASSPORT);

        $this->assertSame($event->points_value, $row->points);
    }

    public function test_applications_close_at_the_deadline_even_while_the_event_runs(): void
    {
        $event = $this->event(['open' => -48, 'start' => -3, 'end' => 3, 'close' => -1, 'points_end' => 200]);

        $this->apply($this->student('LATE03'), $event)
            ->assertSessionHasErrors(['event' => 'The application deadline for this event has passed.']);
    }

    public function test_nobody_can_apply_after_the_event_ended(): void
    {
        $event = $this->event(['open' => -48, 'start' => -5, 'end' => -1, 'close' => 5, 'points_end' => 200]);

        $this->apply($this->student('END001'), $event)
            ->assertSessionHasErrors(['event' => 'This event has already ended.']);
    }

    public function test_withdrawing_is_still_possible_only_until_the_event_starts(): void
    {
        $before = $this->event(['open' => -48, 'start' => 5, 'end' => 9, 'close' => 8, 'points_end' => 200]);
        $student = $this->student('WTH001');
        $this->apply($student, $before);
        $this->actingAs($student)->post("/events/{$before->id}/withdraw")->assertSessionHasNoErrors();

        $running = $this->event(['open' => -48, 'start' => -1, 'end' => 3, 'close' => 2, 'points_end' => 200]);
        $other = $this->student('WTH002');
        $this->apply($other, $running);
        $this->actingAs($other)->post("/events/{$running->id}/withdraw")
            ->assertSessionHasErrors(['event' => 'You cannot withdraw from an event that has already started.']);
    }

    public function test_points_start_with_the_event_and_end_when_the_organiser_says(): void
    {
        $ledger = app(PointLedger::class);
        $student = $this->student('PTS001');

        $notStarted = $this->event(['open' => -48, 'start' => 5, 'end' => 9, 'close' => 8, 'points_end' => 200]);
        $this->assertSame('not_started', $ledger->refusalFor($notStarted)->reason);

        $running = $this->event(['open' => -48, 'start' => -1, 'end' => 3, 'close' => 2, 'points_end' => 200]);
        $this->assertNull($ledger->refusalFor($running));

        $over = $this->event(['open' => -48, 'start' => -10, 'end' => -8, 'close' => -9, 'points_end' => -1]);
        $this->assertSame('deadline_passed', $ledger->refusalFor($over)->reason);
    }

    public function test_the_event_page_says_when_applications_open(): void
    {
        $event = $this->event(['open' => 3, 'start' => 24, 'end' => 28, 'close' => 26, 'points_end' => 200]);

        $this->actingAs($this->student('PAGE01'))->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.applications_open_full', $event->applications_open_at->format('D j M Y, H:i')));
    }
}
