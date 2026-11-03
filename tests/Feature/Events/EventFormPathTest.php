<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\EventPlacement;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #74 (26 Oct, section 1): the event form shows where an event sits in the topic tree
 * (the full path) and, for each audience on that path, how many students will see it.
 * Task #79: the form sends "Applications open", so the three windows can be set in it.
 */
class EventFormPathTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private User $teacher;

    private TopicTag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('Demo University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);

        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->assign($this->teacher, $this->role('Teacher', [Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage]), $this->course);

        Semester::create(['name' => '2026/27 autumn', 'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->addMonths(3)->toDateString(), 'status' => 'active']);
        $this->tag = TopicTag::create(['name' => 'SQL', 'category' => 'academic', 'is_active' => true]);
    }

    private function students(int $count, Topic $at, array $capabilities = [Capability::View, Capability::EventsView]): void
    {
        $role = $this->role('Student '.uniqid(), $capabilities);

        foreach (range(1, $count) as $i) {
            $this->assign($this->makeUser(UserRole::Student), $role, $at);
        }
    }

    public function test_every_topic_and_audience_comes_with_its_full_path(): void
    {
        $this->actingAs($this->teacher)->get('/events/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('owningTopics.0.path', ['Demo University', 'Faculty of Informatics', 'Databases'])
                ->where('owningTopics.0.audiences.0.path', ['Demo University', 'Faculty of Informatics', 'Databases'])
                ->where('owningTopics.0.audiences.1.path', ['Demo University', 'Faculty of Informatics'])
                ->where('owningTopics.0.audiences.2.path', ['Demo University']));
    }

    public function test_each_audience_counts_the_students_assigned_to_that_topic_itself(): void
    {
        $this->students(3, $this->course);
        $this->students(2, $this->faculty);
        // Assigned below the faculty only: they do not see a faculty event (Task #71), so they do not count there.
        $this->students(1, $this->topic('Programming', $this->faculty));

        $this->actingAs($this->teacher)->get('/events/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('owningTopics.0.audiences.0.students', 3)
                ->where('owningTopics.0.audiences.1.students', 2)
                ->where('owningTopics.0.audiences.2.students', 0));
    }

    public function test_only_active_students_who_may_view_events_are_counted(): void
    {
        $this->students(2, $this->course);
        // A role without "View events"
        $this->students(1, $this->course, [Capability::View]);
        // A teacher with "View events" is not a student
        $this->assign($this->makeUser(UserRole::Teacher), $this->role('Helper', [Capability::View, Capability::EventsView]), $this->course);
        // An inactive student
        $this->assign($this->makeUser(UserRole::Student, status: 'inactive'), $this->role('Former', [Capability::View, Capability::EventsView]), $this->course);
        // A student with two roles on the same topic counts once
        $twice = $this->makeUser(UserRole::Student);
        $this->assign($twice, $this->role('A', [Capability::View, Capability::EventsView]), $this->course);
        $this->assign($twice, $this->role('B', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);

        $this->assertSame([$this->course->id => 3], app(EventPlacement::class)->studentCounts([$this->course->id]));
    }

    public function test_the_counts_take_two_queries_whatever_the_number_of_topics(): void
    {
        foreach (range(1, 5) as $i) {
            $topic = $this->topic("Course {$i}", $this->faculty);
            $this->assign($this->teacher, $this->role("Teacher {$i}", [Capability::View, Capability::EventsView, Capability::EventsCreate]), $topic);
            $this->students(1, $topic);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $options = app(EventPlacement::class)->formOptions($this->teacher->fresh());
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'role_assignments') && str_contains($q['query'], 'count(distinct'));
        DB::disableQueryLog();

        $this->assertCount(6, $options);
        $this->assertCount(1, $queries);
    }

    public function test_the_list_of_topics_reads_like_the_tree(): void
    {
        $sport = $this->topic('Faculty of Sport', $this->university);
        $athletics = $this->topic('Athletics', $sport);
        $this->assign($this->teacher, $this->role('Coach', [Capability::View, Capability::EventsView, Capability::EventsCreate]), $athletics);

        $this->actingAs($this->teacher)->get('/events/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('owningTopics.0.title', 'Databases')
                ->where('owningTopics.1.title', 'Athletics')
                ->where('owningTopics.1.path', ['Demo University', 'Faculty of Sport', 'Athletics']));
    }

    public function test_the_edit_page_of_a_published_event_shows_its_fixed_topic_with_path_and_counts(): void
    {
        $this->students(4, $this->course);
        $event = Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'topic_tag_id' => $this->tag->id,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
        ]);

        $this->actingAs($this->teacher)->get("/events/{$event->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->has('topics', 1)
                ->where('topics.0.path', ['Demo University', 'Faculty of Informatics', 'Databases'])
                ->where('topics.0.audiences.0.students', 4));
    }

    public function test_applications_open_can_be_set_when_creating(): void
    {
        $opens = now()->addDay()->startOfHour();

        $this->actingAs($this->teacher)->post('/events', [
            'action' => 'publish',
            'title' => 'SQL night',
            'description' => 'Practice.',
            'topic_tag_id' => $this->tag->id,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'capacity' => 20,
            'application_type' => 'fcfs',
            'points_value' => 10,
            'event_starts_at' => now()->addDays(10)->startOfHour()->format('Y-m-d\TH:i'),
            'event_ends_at' => now()->addDays(10)->startOfHour()->addHours(2)->format('Y-m-d\TH:i'),
            'applications_open_at' => $opens->format('Y-m-d\TH:i'),
            // Pre-filled by the form: applications close when the event starts
            'application_deadline' => now()->addDays(10)->startOfHour()->format('Y-m-d\TH:i'),
            'completion_deadline' => now()->addDays(17)->startOfHour()->format('Y-m-d\TH:i'),
            'is_hidden' => false,
            'qr_enabled' => true,
        ])->assertSessionHasNoErrors();

        $event = Event::query()->where('title', 'SQL night')->sole();
        $this->assertTrue($event->applications_open_at->equalTo($opens));
        $this->assertTrue($event->application_deadline->equalTo($event->event_starts_at));
    }
}
