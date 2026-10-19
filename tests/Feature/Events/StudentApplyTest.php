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
 * Task #49: a student applies from "All events". The catalog and the
 * application endpoint follow the same rule (EventPolicy::apply).
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
            ->assertSessionHas('success', 'Your application has been accepted!');

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
            ->assertSessionHas('success', 'The event is at capacity. You have been placed on the waitlist.');
        $this->assertSame(EventApplication::WAITLISTED, EventApplication::where('student_id', $second->id)->value('status'));
    }

    public function test_a_student_of_the_whole_university_may_apply_to_a_faculty_event(): void
    {
        $event = $this->event();
        $student = $this->student($this->university, 'UNI001');

        $this->actingAs($student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.can_apply', true));

        $this->actingAs($student)->post("/events/{$event->id}/apply")->assertSessionHasNoErrors();
        $this->assertTrue(EventApplication::where('student_id', $student->id)->exists());
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
