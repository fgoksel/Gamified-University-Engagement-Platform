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
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #40: "All events", the published events a person may see.
 */
class EventCatalogTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private Topic $otherFaculty;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);
        $this->otherFaculty = $this->topic('Faculty of Medicine', $this->university);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        // A student of the Faculty of Informatics: sees events announced there and below.
        $this->student = $this->makeUser(UserRole::Student, 'Student One');
        $this->student->forceFill(['neptun_code' => 'ABC123'])->save();
        $this->assign($this->student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->faculty);

        // A teacher who runs the Databases course.
        $this->teacher = $this->makeUser(UserRole::Teacher, 'Teacher One');
        $this->assign($this->teacher, $this->role('Teacher', [Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage]), $this->course);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(string $title, array $attributes = []): Event
    {
        return Event::factory()->create([
            'title' => $title,
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->faculty->id,
            'topic_tag_id' => TopicTag::firstOrCreate(['name' => 'SQL'], ['category' => 'scientific', 'is_active' => true])->id,
            'category' => 'scientific',
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
            ...$attributes,
        ]);
    }

    /**
     * @return list<string>
     */
    private function titlesFor(User $user, string $query = ''): array
    {
        $titles = [];

        $this->actingAs($user)->get('/events'.$query)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$titles) {
                $page->component('Events/Index');
                $titles = collect($page->toArray()['props']['events']['data'])->pluck('title')->sort()->values()->all();
            });

        return $titles;
    }

    public function test_a_student_sees_only_published_revealed_events_announced_to_them(): void
    {
        $this->event('Faculty talk');
        $this->event('Course lab', ['audience_topic_id' => $this->course->id]);
        $this->event('Medicine only', ['owning_topic_id' => $this->otherFaculty->id, 'audience_topic_id' => $this->otherFaculty->id]);
        $this->event('Invitation only', ['audience_topic_id' => null]);
        $this->event('Still a draft', ['status' => 'draft', 'published_at' => null]);
        $this->event('Paused', ['status' => 'on_hold']);
        $this->event('Called off', ['status' => 'cancelled']);
        $this->event('Surprise later', ['is_hidden' => true, 'reveal_at' => now()->addDay()]);
        $this->event('Surprise now', ['is_hidden' => true, 'reveal_at' => now()->subHour()]);

        $this->assertSame(['Course lab', 'Faculty talk', 'Surprise now'], $this->titlesFor($this->student));
    }

    public function test_the_list_matches_the_event_policy(): void
    {
        $events = collect([
            $this->event('A'),
            $this->event('B', ['audience_topic_id' => $this->university->id, 'owning_topic_id' => $this->course->id]),
            $this->event('C', ['owning_topic_id' => $this->otherFaculty->id, 'audience_topic_id' => $this->otherFaculty->id]),
            $this->event('D', ['is_hidden' => true, 'reveal_at' => now()->addDay()]),
        ]);

        foreach ([$this->student, $this->teacher] as $user) {
            $expected = $events->filter(fn (Event $e) => Gate::forUser($user)->allows('view', $e))->pluck('title')->sort()->values()->all();

            $this->assertSame($expected, $this->titlesFor($user), "List and policy disagree for {$user->name}");
        }
    }

    public function test_staff_see_their_surprise_event_before_it_is_revealed(): void
    {
        $this->event('Surprise later', ['is_hidden' => true, 'reveal_at' => now()->addDay()]);

        $this->assertSame(['Surprise later'], $this->titlesFor($this->teacher));
        $this->assertSame([], $this->titlesFor($this->student));
    }

    public function test_upcoming_and_past(): void
    {
        $this->event('Next week');
        $this->event('Last week', [
            'event_starts_at' => now()->subDays(7),
            'event_ends_at' => now()->subDays(7)->addHours(2),
            'application_deadline' => now()->subDays(8),
        ]);

        $this->assertSame(['Next week'], $this->titlesFor($this->student));
        $this->assertSame(['Last week'], $this->titlesFor($this->student, '?when=past'));
    }

    public function test_search_and_category_filters(): void
    {
        $this->event('SQL night', ['description' => 'Joins and indexes']);
        $this->event('Football cup', ['category' => 'sports', 'description' => 'Five-a-side']);

        $this->assertSame(['SQL night'], $this->titlesFor($this->student, '?q=index'));
        $this->assertSame(['Football cup'], $this->titlesFor($this->student, '?category=sports'));
        // An unknown category is ignored rather than hiding everything.
        $this->assertCount(2, $this->titlesFor($this->student, '?category=nonsense'));
        // Search terms are text, not patterns.
        $this->assertSame([], $this->titlesFor($this->student, '?q=%25'));
    }

    public function test_each_event_shows_places_and_the_students_own_application(): void
    {
        $event = $this->event('SQL night', ['capacity' => 3]);
        $other = $this->makeUser(UserRole::Student);
        EventApplication::create(['event_id' => $event->id, 'student_id' => $other->id, 'status' => 'accepted']);
        EventApplication::create(['event_id' => $event->id, 'student_id' => $this->student->id, 'status' => 'waitlisted']);

        $this->actingAs($this->student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.places_left', 2)
                ->where('events.data.0.my_status', 'waitlisted')
                ->where('events.data.0.is_open', true)
                ->where('events.data.0.can_apply', false));
    }

    public function test_can_apply_follows_the_policy_and_the_deadline(): void
    {
        $this->event('Open');
        $this->event('Deadline passed', ['application_deadline' => now()->subHour()]);

        $this->actingAs($this->student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.title', 'Open')
                ->where('events.data.0.can_apply', true)
                ->where('events.data.1.title', 'Deadline passed')
                ->where('events.data.1.is_open', false)
                ->where('events.data.1.can_apply', false));

        // The teacher may see the event but holds no events.apply.
        $this->actingAs($this->teacher)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.can_apply', false));
    }

    public function test_results_are_paged(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->event(sprintf('Event %02d', $i), ['event_starts_at' => now()->addDays($i + 1), 'event_ends_at' => now()->addDays($i + 1)->addHour()]);
        }

        $this->actingAs($this->student)->get('/events')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 12)->where('events.total', 14)->where('events.last_page', 2));

        $this->actingAs($this->student)->get('/events?page=2')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 2)->where('events.data.0.title', 'Event 13'));
    }

    public function test_someone_without_any_event_role_sees_nothing(): void
    {
        $this->event('Faculty talk');
        $outsider = $this->makeUser(UserRole::Student, 'Outsider');

        $this->assertSame([], $this->titlesFor($outsider));
    }
}
