<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #38: the Event Form (create as draft or publish).
 */
class EventFormTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private Topic $otherCourse;

    private User $teacher;

    private TopicTag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);
        $this->otherCourse = $this->topic('Programming', $this->faculty);

        // A teacher who may create at Databases only.
        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->assign($this->teacher, $this->role('Teacher', [Capability::View, Capability::EventsView, Capability::EventsCreate]), $this->course);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->tag = TopicTag::create(['name' => 'SQL workshop', 'category' => 'scientific', 'is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return [
            'action' => 'publish',
            'title' => 'SQL night',
            'description' => 'Hands-on SQL practice.',
            'topic_tag_id' => $this->tag->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->faculty->id,
            'capacity' => 25,
            'application_type' => 'fcfs',
            'points_value' => 10,
            'event_starts_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(10)->addHours(2)->format('Y-m-d\TH:i'),
            'application_deadline' => now()->addDays(8)->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(17)->format('Y-m-d\TH:i'),
            'is_hidden' => false,
            'reveal_at' => null,
            'qr_enabled' => true,
            ...$overrides,
        ];
    }

    public function test_the_form_offers_only_topics_where_the_teacher_may_create_and_their_audiences(): void
    {
        $this->actingAs($this->teacher)
            ->get('/events/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Create')
                ->has('owningTopics', 1)
                ->where('owningTopics.0.id', $this->course->id)
                ->where('owningTopics.0.audiences.0.id', $this->course->id)
                ->where('owningTopics.0.audiences.1.id', $this->faculty->id)
                ->where('owningTopics.0.audiences.2.id', $this->university->id)
                ->has('tags', 1)
                ->where('hasActiveSemester', true));
    }

    public function test_a_teacher_can_publish_an_event(): void
    {
        $this->actingAs($this->teacher)
            ->post('/events', $this->valid())
            ->assertRedirect('/my-events')
            ->assertSessionHas('success', 'Your event is published.');

        $event = Event::sole();
        $this->assertSame('published', $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertSame('scientific', $event->category);
        $this->assertSame($this->teacher->id, $event->created_by_id);
        $this->assertSame($this->faculty->id, $event->audience_topic_id);
        $this->assertTrue($event->qr_enabled);
    }

    public function test_a_teacher_can_save_a_draft(): void
    {
        $this->actingAs($this->teacher)->post('/events', $this->valid(['action' => 'draft']));

        $event = Event::sole();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->published_at);
    }

    public function test_an_invitation_only_event_has_no_audience(): void
    {
        $this->actingAs($this->teacher)->post('/events', $this->valid(['audience_topic_id' => null]));

        $this->assertNull(Event::sole()->audience_topic_id);
    }

    public function test_the_owning_topic_must_be_one_where_the_teacher_may_create(): void
    {
        $this->actingAs($this->teacher)
            ->post('/events', $this->valid(['owning_topic_id' => $this->otherCourse->id, 'audience_topic_id' => null]))
            ->assertSessionHasErrors('owning_topic_id');

        $this->assertSame(0, Event::count());
    }

    public function test_the_audience_must_be_the_owning_topic_or_above_it(): void
    {
        $this->actingAs($this->teacher)
            ->post('/events', $this->valid(['audience_topic_id' => $this->otherCourse->id]))
            ->assertSessionHasErrors('audience_topic_id');
    }

    public function test_dates_must_be_in_a_sensible_order(): void
    {
        $this->actingAs($this->teacher)
            ->post('/events', $this->valid([
                'event_ends_at' => now()->addDays(9)->format('Y-m-d\TH:i'),
                'application_deadline' => now()->addDays(11)->format('Y-m-d\TH:i'),
                'completion_deadline' => now()->addDays(5)->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors(['event_ends_at', 'application_deadline', 'completion_deadline']);
    }

    public function test_a_surprise_event_needs_a_reveal_time_before_it_starts(): void
    {
        $this->actingAs($this->teacher)
            ->post('/events', $this->valid(['is_hidden' => true, 'reveal_at' => null]))
            ->assertSessionHasErrors('reveal_at');

        $this->actingAs($this->teacher)
            ->post('/events', $this->valid([
                'is_hidden' => true,
                'reveal_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Event::sole()->is_hidden);
    }

    public function test_an_inactive_tag_cannot_be_used(): void
    {
        $this->tag->update(['is_active' => false]);

        $this->actingAs($this->teacher)
            ->post('/events', $this->valid())
            ->assertSessionHasErrors('topic_tag_id');
    }

    public function test_students_cannot_open_or_submit_the_form(): void
    {
        $student = $this->makeUser(UserRole::Student);

        $this->actingAs($student)->get('/events/create')->assertRedirect('/');
        $this->actingAs($student)->post('/events', $this->valid())->assertRedirect('/');

        $this->assertSame(0, Event::count());
    }

    public function test_without_an_active_semester_nothing_is_saved(): void
    {
        Semester::query()->update(['status' => 'archived']);

        $this->actingAs($this->teacher)
            ->post('/events', $this->valid())
            ->assertSessionHasErrors('title');

        $this->assertSame(0, Event::count());
    }
}
