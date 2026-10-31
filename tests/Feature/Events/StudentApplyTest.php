<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #49: a student applies from "All events" or the event's page. The Apply
 * button and the application endpoint use the same checks; applying needs
 * explicit membership of the audience topic itself (meeting of 16 Oct).
 */
class StudentApplyTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private RoleDefinition $studentRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->studentRole = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
    }

    private function student(Topic $at, string $neptun): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => $neptun])->save();
        $this->assign($student, $this->studentRole, $at);

        return $student;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->faculty->id,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
            ...$attributes,
        ]);
    }

    public function test_a_student_applies_from_the_catalog_and_sees_their_place(): void
    {
        $event = $this->event(['capacity' => 10]);
        $student = $this->student($this->faculty, 'ABC123');

        $this->actingAs($student)->from('/events')
            ->post("/events/{$event->id}/apply")
            ->assertRedirect('/events')
            ->assertSessionHas('success', "You're in!");

        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.my_status', 'accepted')
                ->where('events.data.0.places_left', 9)
                ->where('events.data.0.can_apply', false));
    }

    public function test_a_full_first_come_event_puts_the_student_on_the_waitlist(): void
    {
        $event = $this->event(['capacity' => 1]);
        $this->actingAs($this->student($this->faculty, 'AAA111'))->post("/events/{$event->id}/apply");

        $second = $this->student($this->faculty, 'BBB222');
        $this->actingAs($second)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.places_left', 0)->where('events.data.0.can_apply', true));

        $this->actingAs($second)->post("/events/{$event->id}/apply")
            ->assertSessionHas('success', "The event is full, so you're on the waitlist. We'll tell you if a place opens.");
        $this->assertSame(EventApplication::WAITLISTED, EventApplication::where('student_id', $second->id)->value('status'));
    }

    public function test_membership_of_a_topic_above_the_audience_is_not_enough(): void
    {
        // Meeting of 16 Oct: explicit audience-node membership; nothing flows down.
        $event = $this->event();
        $student = $this->student($this->university, 'UNI001');

        // Task #71: the event is not even listed for someone who is not assigned to its node.
        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 0));

        $this->actingAs($student)->post("/events/{$event->id}/apply")
            ->assertSessionHasErrors(['event' => 'You do not belong to the audience for this event.']);
        $this->assertSame(0, EventApplication::count());
    }

    public function test_membership_of_a_topic_below_the_audience_is_not_enough(): void
    {
        $event = $this->event();
        $student = $this->student($this->course, 'CRS001');

        $this->actingAs($student)->post("/events/{$event->id}/apply")
            ->assertSessionHasErrors(['event' => 'You do not belong to the audience for this event.']);
    }

    public function test_an_overlapping_event_needs_confirmation_before_applying(): void
    {
        $student = $this->student($this->faculty, 'OVL001');
        $first = $this->event(['title' => 'SQL night']);
        $this->actingAs($student)->post("/events/{$first->id}/apply")->assertSessionHasNoErrors();

        // Starts one hour into the first one.
        $second = $this->event([
            'title' => 'Robotics demo',
            'event_starts_at' => now()->addDays(5)->addHour(),
            'event_ends_at' => now()->addDays(5)->addHours(3),
        ]);

        $this->actingAs($student)->get("/events/{$second->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.can_apply', true)
                ->where('event.overlaps_with', 'SQL night'));

        // Without confirming: the warning, and nothing is saved.
        $this->actingAs($student)->post("/events/{$second->id}/apply")
            ->assertSessionHasErrors(['overlap' => 'This event overlaps in time with SQL night. Are you sure you want to apply?']);
        $this->assertFalse(EventApplication::where('event_id', $second->id)->exists());

        // After confirming: applied.
        $this->actingAs($student)->post("/events/{$second->id}/apply", ['confirm_overlap' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(EventApplication::where('event_id', $second->id)->exists());
    }

    public function test_no_warning_for_events_that_do_not_overlap_or_where_the_student_has_no_place(): void
    {
        $student = $this->student($this->faculty, 'OVL002');
        $first = $this->event(['title' => 'Morning talk', 'capacity' => 1]);
        // Someone else takes the only place: the student only waits, so there is no clash.
        $this->actingAs($this->student($this->faculty, 'OTHER1'))->post("/events/{$first->id}/apply");
        $this->actingAs($student)->post("/events/{$first->id}/apply");
        $this->assertSame(EventApplication::WAITLISTED, EventApplication::where('student_id', $student->id)->value('status'));

        $same = $this->event(['title' => 'Same time']);
        $later = $this->event([
            'title' => 'Later the same day',
            'event_starts_at' => now()->addDays(5)->addHours(2),
            'event_ends_at' => now()->addDays(5)->addHours(4),
        ]);

        $this->actingAs($student)->post("/events/{$same->id}/apply")->assertSessionHasNoErrors();
        // Back to back (ends exactly when the next one starts) is not an overlap.
        $this->actingAs($student)->post("/events/{$later->id}/apply")->assertSessionHasNoErrors();
    }

    public function test_the_card_says_already_applied(): void
    {
        $event = $this->event();
        $student = $this->student($this->faculty, 'ALR001');

        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.already_applied', false));

        $this->actingAs($student)->post("/events/{$event->id}/apply");

        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.already_applied', true)
                ->where('events.data.0.can_apply', false));
        $this->actingAs($student)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page->where('event.already_applied', true));

        // After withdrawing it is no longer "already applied", and the student may apply again.
        $this->actingAs($student)->post("/events/{$event->id}/withdraw");
        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.already_applied', false)
                ->where('events.data.0.can_apply', true));
    }

    public function test_a_student_of_only_a_sibling_course_may_not_apply(): void
    {
        $event = $this->event(['audience_topic_id' => $this->course->id]);
        $other = $this->topic('Programming', $this->faculty);
        $student = $this->student($other, 'PRG001');

        $this->actingAs($student)->post("/events/{$event->id}/apply")
            ->assertSessionHasErrors(['event' => 'You do not belong to the audience for this event.']);
    }

    public function test_archiving_a_topic_above_the_event_freezes_applications(): void
    {
        $event = $this->event(['audience_topic_id' => $this->course->id]);
        $student = $this->student($this->course, 'ARC001');
        $this->faculty->forceFill(['archived_at' => now()])->save();

        $this->actingAs($student)->post("/events/{$event->id}/apply")
            ->assertSessionHasErrors(['event' => 'Applications are frozen because this topic is archived.']);
    }

    public function test_without_the_apply_permission_there_is_no_button_and_no_application(): void
    {
        $event = $this->event();
        $viewer = $this->makeUser(UserRole::Student);
        $viewer->forceFill(['neptun_code' => 'VIE001'])->save();
        $this->assign($viewer, $this->role('Viewer', [Capability::View, Capability::EventsView]), $this->faculty);

        $this->actingAs($viewer)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.can_apply', false));

        $this->actingAs($viewer)->post("/events/{$event->id}/apply")->assertSessionHasErrors('event');
        $this->assertSame(0, EventApplication::count());
    }

    public function test_after_withdrawing_the_student_can_apply_again(): void
    {
        $event = $this->event();
        $student = $this->student($this->faculty, 'WDR001');

        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $this->actingAs($student)->post("/events/{$event->id}/withdraw");

        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.my_status', 'withdrawn')
                ->where('events.data.0.can_apply', true));

        $this->actingAs($student)->post("/events/{$event->id}/apply")->assertSessionHasNoErrors();
        $this->assertSame(EventApplication::ACCEPTED, EventApplication::where('student_id', $student->id)->value('status'));
    }

    public function test_applying_twice_is_refused(): void
    {
        $event = $this->event();
        $student = $this->student($this->faculty, 'DUP001');

        $this->actingAs($student)->post("/events/{$event->id}/apply");
        $this->actingAs($student)->post("/events/{$event->id}/apply")
            ->assertSessionHasErrors(['event' => 'You have already applied to this event.']);

        $this->assertSame(1, EventApplication::count());
    }
}
