<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicBrowser;
use App\Services\TopicService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #73 (26 Oct, section 1): an Events tab on every topic in the explorer, listing the
 * events there and below that the viewer may see, and "Create event here".
 */
class ExplorerEventsTabTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private Topic $homework;

    private Topic $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);
        $this->homework = $this->topic('Homework 1', $this->course);
        $this->sibling = $this->topic('Law', $this->university);

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
    private function event(Topic $owning, string $title, array $attributes = []): Event
    {
        return Event::factory()->create([
            'title' => $title,
            'owning_topic_id' => $owning->id,
            'audience_topic_id' => $owning->id,
            'status' => Event::STATUS_PUBLISHED,
            'is_hidden' => false,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
            ...$attributes,
        ]);
    }

    /** A teacher with the given capabilities at one topic. */
    private function teacher(Topic $at, Capability ...$capabilities): User
    {
        $teacher = $this->makeUser(UserRole::Teacher);
        $this->assign($teacher, $this->role('Staff '.$at->id.count($capabilities).$teacher->id, [Capability::View, ...$capabilities]), $at);

        return $teacher;
    }

    private function student(Topic $at): User
    {
        $student = $this->makeUser(UserRole::Student);
        $this->assign($student, $this->role('Student '.$student->id, [Capability::View, Capability::EventsView]), $at);

        return $student;
    }

    /**
     * @return list<string>
     */
    private function titles(User $user, Topic $topic, string $when = 'upcoming', int $page = 1): array
    {
        return array_column(app(TopicBrowser::class)->events($user, $topic, $when, $page)['items'], 'title');
    }

    public function test_the_tab_lists_events_of_the_topic_and_below_and_nothing_else(): void
    {
        $this->event($this->course, 'Here');
        $this->event($this->homework, 'Below');
        $this->event($this->faculty, 'Above');
        $this->event($this->sibling, 'Sibling');
        $teacher = $this->teacher($this->university, Capability::EventsManage);

        $this->assertEqualsCanonicalizing(['Here', 'Below'], $this->titles($teacher, $this->course));
    }

    public function test_the_tab_shows_the_path_from_the_selected_topic(): void
    {
        $this->event($this->homework, 'Below', ['event_starts_at' => now()->addDays(6), 'event_ends_at' => now()->addDays(6)->addHour()]);
        $this->event($this->faculty, 'Here', ['event_starts_at' => now()->addDays(5), 'event_ends_at' => now()->addDays(5)->addHour()]);

        $this->actingAs($this->teacher($this->university, Capability::EventsManage))
            ->get("/topics/{$this->faculty->id}?tab=events")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Topics/Explorer')
                ->where('tab', 'events')
                ->where('selected.events.when', 'upcoming')
                ->where('selected.events.items.0.title', 'Here')
                ->where('selected.events.items.0.path', [])
                ->where('selected.events.items.1.path', ['Databases', 'Homework 1']));
    }

    public function test_the_events_load_only_for_their_tab(): void
    {
        $this->actingAs($this->teacher($this->university, Capability::EventsManage))
            ->get("/topics/{$this->faculty->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selected.events', null));
    }

    public function test_a_viewer_never_sees_an_event_they_could_not_open(): void
    {
        $student = $this->student($this->course);

        $this->event($this->course, 'Announced');
        $this->event($this->course, 'Cancelled', ['status' => Event::STATUS_CANCELLED]);
        $this->event($this->course, 'Draft', ['status' => Event::STATUS_DRAFT]);
        $this->event($this->course, 'On hold', ['status' => Event::STATUS_ON_HOLD]);
        $this->event($this->course, 'Surprise', ['is_hidden' => true]);
        $this->event($this->homework, 'For another group');
        $this->event($this->homework, 'To the course', ['audience_topic_id' => $this->course->id]);

        $staff = $this->teacher($this->course, Capability::QrCodesManage);
        $viewerOnly = $this->teacher($this->course);

        foreach ([$student, $staff, $viewerOnly] as $viewer) {
            $listed = $this->titles($viewer, $this->course);
            foreach (Event::all() as $event) {
                $this->assertSame(
                    Gate::forUser($viewer)->allows('view', $event),
                    in_array($event->title, $listed, true),
                    "{$event->title} for viewer {$viewer->id}"
                );
            }
        }

        $this->assertEqualsCanonicalizing(['Announced', 'Cancelled', 'To the course'], $this->titles($student, $this->course));
    }

    public function test_a_surprise_event_shows_only_to_its_managers(): void
    {
        $this->event($this->course, 'Surprise', ['is_hidden' => true]);

        $this->assertSame(['Surprise'], $this->titles($this->teacher($this->course, Capability::EventsManage), $this->course));
        $this->assertSame([], $this->titles($this->student($this->course), $this->course));
        // Managing somewhere else gives nothing here.
        $this->assertSame([], $this->titles($this->teacher($this->sibling, Capability::EventsManage), $this->course));
    }

    public function test_events_of_an_archived_topic_are_not_listed(): void
    {
        $this->event($this->homework, 'Frozen');
        $this->event($this->course, 'Still here');
        app(TopicService::class)->archive($this->admin, $this->homework);

        $this->assertSame(['Still here'], $this->titles($this->teacher($this->university, Capability::EventsManage), $this->course));
    }

    public function test_upcoming_and_past_are_split_and_ordered(): void
    {
        $at = fn (int $days) => ['event_starts_at' => now()->addDays($days), 'event_ends_at' => now()->addDays($days)->addHours(2)];

        $this->event($this->course, 'Later', $at(8));
        $this->event($this->course, 'Soon', $at(2));
        $this->event($this->course, 'Running', ['event_starts_at' => now()->subHour(), 'event_ends_at' => now()->addHour()]);
        $this->event($this->course, 'Long ago', $at(-20));
        $this->event($this->course, 'Recently', $at(-2));
        $teacher = $this->teacher($this->course, Capability::EventsManage);

        $this->assertSame(['Running', 'Soon', 'Later'], $this->titles($teacher, $this->course));
        $this->assertSame(['Recently', 'Long ago'], $this->titles($teacher, $this->course, 'past'));

        $this->actingAs($teacher)->get("/topics/{$this->course->id}?tab=events&when=past")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.events.when', 'past')
                ->where('selected.events.items.0.title', 'Recently'));
    }

    public function test_a_page_shows_at_most_twenty_events(): void
    {
        foreach (range(1, 23) as $i) {
            $this->event($this->course, "Event {$i}", ['event_starts_at' => now()->addDays($i), 'event_ends_at' => now()->addDays($i)->addHour()]);
        }
        $teacher = $this->teacher($this->course, Capability::EventsManage);

        $first = app(TopicBrowser::class)->events($teacher, $this->course, 'upcoming', 1);
        $this->assertCount(20, $first['items']);
        $this->assertSame(23, $first['total']);
        $this->assertSame(2, $first['last_page']);

        $this->actingAs($teacher)->get("/topics/{$this->course->id}?tab=events&page=2")
            ->assertInertia(fn (Assert $page) => $page->has('selected.events.items', 3));
    }

    public function test_the_queries_do_not_grow_with_the_number_of_events(): void
    {
        $teacher = $this->teacher($this->university, Capability::EventsManage);
        $queries = function () use ($teacher): int {
            $this->actingAs($teacher)->get("/topics/{$this->university->id}?tab=events")->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($teacher)->get("/topics/{$this->university->id}?tab=events")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->event($this->homework, 'One');
        $this->event($this->course, 'Two');
        $few = $queries();

        foreach (range(1, 15) as $i) {
            $this->event($i % 2 ? $this->homework : $this->sibling, "More {$i}");
        }

        $this->assertSame($few, $queries());
    }

    public function test_the_button_needs_events_create_here_or_above(): void
    {
        $here = $this->teacher($this->course, Capability::EventsCreate);
        $above = $this->teacher($this->university, Capability::EventsCreate);
        $without = $this->teacher($this->course, Capability::EventsManage);
        $elsewhere = $this->teacher($this->sibling, Capability::EventsCreate);

        $url = "/topics/{$this->course->id}";

        foreach ([$here, $above] as $teacher) {
            $this->actingAs($teacher)->get($url)
                ->assertInertia(fn (Assert $page) => $page->where('selected.can.create_event', true));
        }
        $this->actingAs($without)->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.create_event', false));
        $this->assertFalse(app(TopicBrowser::class)->abilities($elsewhere, $this->course)['create_event']);
    }

    public function test_a_student_gets_no_button(): void
    {
        $this->actingAs($this->student($this->course))->get("/topics/{$this->course->id}")
            ->assertInertia(fn (Assert $page) => $page->where('selected.can.create_event', false));
    }

    public function test_an_archived_topic_never_gets_the_button(): void
    {
        $teacher = $this->teacher($this->university, Capability::EventsCreate);
        app(TopicService::class)->archive($this->admin, $this->homework);

        $this->assertFalse(app(TopicBrowser::class)->abilities($teacher, $this->homework->fresh())['create_event']);
    }

    public function test_an_administrator_gets_no_links_that_the_event_routes_refuse(): void
    {
        $this->event($this->course, 'Listed');

        $this->actingAs($this->admin)->get("/topics/{$this->course->id}?tab=events")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.create_event', false)
                ->where('selected.events.items.0.title', 'Listed')
                ->where('selected.events.items.0.can_open', false));

        $this->actingAs($this->admin)->get('/events/create')->assertRedirect('/');
    }

    public function test_the_form_starts_at_an_allowed_topic(): void
    {
        $teacher = $this->teacher($this->faculty, Capability::EventsCreate);

        $this->actingAs($teacher)->get("/events/create?area={$this->course->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Create')
                ->where('initialTopic', $this->course->id));

        $this->actingAs($teacher)->get('/events/create')
            ->assertInertia(fn (Assert $page) => $page->where('initialTopic', null));
    }

    public function test_the_form_ignores_a_topic_the_person_may_not_use(): void
    {
        $teacher = $this->teacher($this->faculty, Capability::EventsCreate);

        $this->actingAs($teacher)->get("/events/create?area={$this->sibling->id}")
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertDontSee($this->sibling->title)
            ->assertInertia(fn (Assert $page) => $page->where('initialTopic', null));

        app(TopicService::class)->archive($this->admin, $this->course);
        $this->actingAs($teacher)->get("/events/create?area={$this->course->id}")
            ->assertInertia(fn (Assert $page) => $page->where('initialTopic', null));
    }
}
