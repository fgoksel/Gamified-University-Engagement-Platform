<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #37: "Organized by me" and its lifecycle actions.
 */
class OrganizedEventsTest extends TopicTestCase
{
    private Topic $course;

    private Topic $otherCourse;

    private User $teacher;

    private User $coTeacher;

    protected function setUp(): void
    {
        parent::setUp();

        $faculty = $this->topic('Faculty of Informatics');
        $this->course = $this->topic('Databases', $faculty);
        $this->otherCourse = $this->topic('Programming', $faculty);

        $role = $this->role('Teacher', [Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage, Capability::ApplicationsManage]);
        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->coTeacher = $this->makeUser(UserRole::Teacher, 'Teacher Two');
        $this->assign($this->teacher, $role, $this->course);
        $this->assign($this->coTeacher, $role, $this->course);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): Event
    {
        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'created_by_id' => $this->teacher->id,
            'topic_tag_id' => TopicTag::firstOrCreate(['name' => 'SQL'], ['category' => 'scientific', 'is_active' => true])->id,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
            ...$attributes,
        ]);
    }

    private function apply(Event $event, string $status = EventApplication::ACCEPTED): void
    {
        $student = $this->makeUser(UserRole::Student);

        EventApplication::create(['event_id' => $event->id, 'student_id' => $student->id, 'status' => $status]);
    }

    public function test_the_list_shows_upcoming_events_with_counts_per_tab(): void
    {
        $upcoming = $this->event(['title' => 'SQL night', 'capacity' => 20]);
        $this->apply($upcoming);
        $this->apply($upcoming, EventApplication::PENDING);
        $this->event(['title' => 'Draft one', 'status' => 'draft', 'published_at' => null]);
        $this->event([
            'title' => 'Last week',
            'event_starts_at' => now()->subDays(7),
            'event_ends_at' => now()->subDays(7)->addHour(),
            'application_deadline' => now()->subDays(8),
        ]);
        $this->event(['title' => 'Called off', 'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Room flooded']);

        $this->actingAs($this->teacher)
            ->get('/my-events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Organized')
                ->where('filter', 'upcoming')
                ->where('counts', ['upcoming' => 1, 'drafts' => 1, 'past' => 1, 'on_hold' => 0, 'cancelled' => 1])
                ->has('events', 1)
                ->where('events.0.title', 'SQL night')
                ->where('events.0.accepted', 1)
                ->where('events.0.waiting', 1)
                ->where('events.0.capacity', 20)
                ->where('events.0.can_delete', false));
    }

    public function test_each_tab_shows_its_own_events(): void
    {
        $this->event(['title' => 'Draft one', 'status' => 'draft', 'published_at' => null]);
        $this->event(['title' => 'Called off', 'status' => 'cancelled', 'cancellation_reason' => 'Room flooded']);

        $this->actingAs($this->teacher)->get('/my-events?filter=drafts')
            ->assertInertia(fn (Assert $page) => $page->has('events', 1)->where('events.0.title', 'Draft one'));

        $this->actingAs($this->teacher)->get('/my-events?filter=cancelled')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.cancellation_reason', 'Room flooded'));

        // An unknown filter falls back to "upcoming".
        $this->actingAs($this->teacher)->get('/my-events?filter=nonsense')
            ->assertInertia(fn (Assert $page) => $page->where('filter', 'upcoming'));
    }

    public function test_events_owned_elsewhere_are_not_listed(): void
    {
        $this->event(['owning_topic_id' => $this->otherCourse->id, 'audience_topic_id' => null]);

        $this->actingAs($this->teacher)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->has('events', 0)->where('counts.upcoming', 0));
    }

    public function test_a_colleague_at_the_same_topic_sees_and_manages_the_event(): void
    {
        $event = $this->event();

        $this->actingAs($this->coTeacher)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page->has('events', 1));

        $this->actingAs($this->coTeacher)->post("/events/{$event->id}/hold")->assertSessionHas('success');
        $this->assertSame('on_hold', $event->fresh()->status);
    }

    public function test_buttons_follow_the_capabilities_of_the_person(): void
    {
        $event = $this->event(['application_type' => 'teacher_select']);

        $this->actingAs($this->teacher)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.can_manage', true)
                ->where('events.0.can_review', true));

        // Someone who may only create drafts here sees the event but cannot run it.
        $drafter = $this->makeUser(UserRole::Teacher, 'Drafter');
        $this->assign($drafter, $this->role('Event drafter', [Capability::View, Capability::EventsView, Capability::EventsCreate]), $this->course);

        $this->actingAs($drafter)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 1)
                ->where('events.0.can_manage', false)
                ->where('events.0.can_review', false)
                ->where('events.0.can_credit', false));

        $crediter = $this->makeUser(UserRole::Teacher, 'Crediter');
        $this->assign($crediter, $this->role('Event crediter', [Capability::View, Capability::EventsView, Capability::PointsCredit]), $this->course);

        $this->actingAs($crediter)->get('/my-events')
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 1)
                ->where('events.0.can_credit', true));

        $this->actingAs($drafter)->post("/events/{$event->id}/hold")->assertRedirect('/');
        $this->assertSame('published', $event->fresh()->status);
    }

    public function test_a_draft_can_be_published(): void
    {
        $event = $this->event(['status' => 'draft', 'published_at' => null]);

        $this->actingAs($this->teacher)->post("/events/{$event->id}/publish")->assertSessionHas('success');

        $this->assertSame('published', $event->fresh()->status);
        $this->assertNotNull($event->fresh()->published_at);
    }

    public function test_a_draft_without_a_tag_can_be_published(): void
    {
        // The tag and the category are optional (D10, Task #116).
        $event = $this->event(['status' => 'draft', 'published_at' => null, 'topic_tag_id' => null, 'category' => null]);

        $this->actingAs($this->teacher)->post("/events/{$event->id}/publish")->assertSessionHas('success');
        $this->assertSame('published', $event->fresh()->status);
    }

    public function test_a_draft_with_past_dates_is_not_published(): void
    {
        $event = $this->event([
            'status' => 'draft',
            'published_at' => null,
            'event_starts_at' => now()->subDay(),
            'application_deadline' => now()->subDays(2),
        ]);

        $this->actingAs($this->teacher)->post("/events/{$event->id}/publish")->assertSessionHas('error');
        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_hold_and_restore(): void
    {
        $event = $this->event();

        $this->actingAs($this->teacher)->post("/events/{$event->id}/hold");
        $this->assertSame('on_hold', $event->fresh()->status);

        $this->actingAs($this->teacher)->post("/events/{$event->id}/restore", ['event_starts_at' => now()->addWeek()->format('Y-m-d\\TH:i'), 'event_ends_at' => now()->addWeek()->addHours(2)->format('Y-m-d\\TH:i')]);
        $this->assertSame('published', $event->fresh()->status);

        // Restoring something that is not on hold changes nothing.
        $this->actingAs($this->teacher)->post("/events/{$event->id}/restore")->assertSessionHas('error');
    }

    public function test_restoring_needs_a_new_date_in_the_future(): void
    {
        // Task #122 (C10, 13 October): an event on hold comes back only with a new date and time.
        $event = $this->event();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/hold");
        $url = "/events/{$event->id}/restore";

        $this->actingAs($this->teacher)->post($url)
            ->assertSessionHasErrors(['event_starts_at', 'event_ends_at']);
        $this->actingAs($this->teacher)->post($url, [
            'event_starts_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('event_starts_at');
        $this->actingAs($this->teacher)->post($url, [
            'event_starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(3)->subHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('event_ends_at');

        $this->assertSame('on_hold', $event->fresh()->status);
    }

    public function test_restoring_moves_the_other_dates_with_the_start_and_keeps_them_in_their_windows(): void
    {
        $this->travelTo(now()->startOfMinute());
        $event = $this->event([
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'applications_open_at' => now()->addDays(1),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
        ]);
        $this->actingAs($this->teacher)->post("/events/{$event->id}/hold");

        // Ten days later than planned, and an hour shorter.
        $starts = now()->addDays(15);
        $this->actingAs($this->teacher)->post("/events/{$event->id}/restore", [
            'event_starts_at' => $starts->format('Y-m-d\TH:i'),
            'event_ends_at' => $starts->copy()->addHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHas('success');

        $event->refresh();
        $this->assertSame('published', $event->status);
        $this->assertTrue($event->event_starts_at->equalTo($starts));
        $this->assertTrue($event->event_ends_at->equalTo($starts->copy()->addHour()));
        $this->assertTrue($event->applications_open_at->equalTo(now()->addDays(11)));
        $this->assertTrue($event->application_deadline->equalTo(now()->addDays(14)));
        $this->assertTrue($event->completion_deadline->equalTo(now()->addDays(22)));

        // The restore is in the edit history (Task #76), with who did it.
        $edit = $event->edits()->firstOrFail();
        $this->assertSame($this->teacher->id, $edit->actor_id);
        $this->assertSame(['old' => 'on_hold', 'new' => 'published'], $edit->changes['status']);
        $this->assertSame($starts->format('Y-m-d H:i'), $edit->changes['event_starts_at']['new']);
    }

    public function test_restoring_an_event_whose_dates_passed_keeps_applications_open_and_points_after_the_end(): void
    {
        $this->travelTo(now()->startOfMinute());
        // Put on hold long ago: every date has passed.
        $event = $this->event([
            'event_starts_at' => now()->subDays(10),
            'event_ends_at' => now()->subDays(10)->addHours(2),
            'applications_open_at' => now()->subDays(20),
            'application_deadline' => now()->subDays(11),
            'completion_deadline' => now()->subDays(10)->addHours(2),
            'status' => Event::STATUS_ON_HOLD,
        ]);

        // Moved forward so little that the moved deadline would still be in the past.
        $starts = now()->addHour();
        $this->actingAs($this->teacher)->post("/events/{$event->id}/restore", [
            'event_starts_at' => $starts->format('Y-m-d\TH:i'),
            'event_ends_at' => $starts->copy()->addDays(2)->format('Y-m-d\TH:i'),
        ])->assertSessionHas('success');

        $event->refresh();
        // Applications close when the event starts, never in the past.
        $this->assertTrue($event->application_deadline->equalTo($starts));
        // Points are possible until the end at least.
        $this->assertTrue($event->completion_deadline->equalTo($starts->copy()->addDays(2)));
        $this->assertTrue($event->applications_open_at->lt($event->application_deadline));
    }

    public function test_deleting_needs_the_exact_title(): void
    {
        // Task #122 (C11, 13 October): delete works only with the event's exact title.
        $event = $this->event(['title' => 'SQL night']);

        $this->actingAs($this->teacher)->delete("/events/{$event->id}")->assertSessionHasErrors('title');
        $this->actingAs($this->teacher)->delete("/events/{$event->id}", ['title' => 'sql night'])->assertSessionHasErrors('title');
        $this->actingAs($this->teacher)->delete("/events/{$event->id}", ['title' => 'SQL'])->assertSessionHasErrors('title');
        $this->assertNotNull(Event::find($event->id));

        $this->actingAs($this->teacher)->delete("/events/{$event->id}", ['title' => 'SQL night'])->assertSessionHas('success');
        $this->assertNull(Event::find($event->id));
    }

    public function test_cancelling_needs_a_reason_and_is_final(): void
    {
        $event = $this->event();

        $this->actingAs($this->teacher)->post("/events/{$event->id}/cancel", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame('published', $event->fresh()->status);

        $this->actingAs($this->teacher)->post("/events/{$event->id}/cancel", ['reason' => 'Speaker is ill']);
        $event->refresh();
        $this->assertSame('cancelled', $event->status);
        $this->assertSame('Speaker is ill', $event->cancellation_reason);
        $this->assertNotNull($event->cancelled_at);

        $this->actingAs($this->teacher)->post("/events/{$event->id}/publish")->assertSessionHas('error');
        $this->assertSame('cancelled', $event->fresh()->status);
    }

    public function test_an_event_without_applicants_can_be_deleted(): void
    {
        $event = $this->event();

        $this->actingAs($this->teacher)->delete("/events/{$event->id}", ['title' => $event->title])->assertSessionHas('success');

        $this->assertNull(Event::find($event->id));
    }

    public function test_an_event_with_applicants_cannot_be_deleted(): void
    {
        $event = $this->event();
        $this->apply($event, EventApplication::WITHDRAWN);

        $this->actingAs($this->teacher)->delete("/events/{$event->id}", ['title' => $event->title])->assertSessionHas('error');

        $this->assertNotNull(Event::find($event->id));
    }

    public function test_a_teacher_cannot_act_on_events_owned_elsewhere(): void
    {
        $event = $this->event(['owning_topic_id' => $this->otherCourse->id, 'audience_topic_id' => null]);

        // A refused action is sent home with the app's "no permission" message.
        $this->actingAs($this->teacher)->post("/events/{$event->id}/hold")->assertRedirect('/')->assertSessionHas('error');
        $this->actingAs($this->teacher)->post("/events/{$event->id}/cancel", ['reason' => 'x'])->assertRedirect('/');
        $this->actingAs($this->teacher)->delete("/events/{$event->id}")->assertRedirect('/');

        $this->assertNotNull(Event::find($event->id));

        $this->assertSame('published', $event->fresh()->status);
    }

    public function test_students_cannot_use_the_actions(): void
    {
        $event = $this->event();
        $student = $this->makeUser(UserRole::Student);

        $this->actingAs($student)->post("/events/{$event->id}/hold")->assertRedirect('/');
        $this->assertSame('published', $event->fresh()->status);
    }

    public function test_a_newly_created_event_shows_up_in_the_list(): void
    {
        $tag = TopicTag::firstOrCreate(['name' => 'SQL'], ['category' => 'scientific', 'is_active' => true]);

        $this->actingAs($this->teacher)->post('/events', [
            'action' => 'draft',
            'title' => 'Fresh draft',
            'description' => 'x',
            'topic_tag_id' => $tag->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'capacity' => 10,
            'application_type' => 'fcfs',
            'points_value' => 5,
            'event_starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(3)->addHour()->format('Y-m-d\TH:i'),
            'application_deadline' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(4)->format('Y-m-d\TH:i'),
        ]);

        $this->actingAs($this->teacher)->get('/my-events?filter=drafts')
            ->assertInertia(fn (Assert $page) => $page->where('events.0.title', 'Fresh draft'));
    }
}
