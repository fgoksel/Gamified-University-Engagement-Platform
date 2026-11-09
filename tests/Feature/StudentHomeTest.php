<?php

namespace Tests\Feature;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Leaderboard;
use App\Models\PointTransaction;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\TopicTag;
use App\Models\User;
use App\Services\PointLedger;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #92 (26 Oct, section 4): the student's home page replaces the empty leaderboard. Their
 * leaderboards as boxes, starred first (leaderboards redesign, 9 November, which removed the
 * points per category), the next event with a countdown, events open for applications and
 * recent points. Teachers keep the Leaderboard page for now.
 */
class StudentHomeTest extends TopicTestCase
{
    private Topic $course;

    private Semester $semester;

    private User $student;

    private User $organiser;

    protected function setUp(): void
    {
        parent::setUp();

        $university = $this->topic('University');
        $faculty = $this->topic('Faculty of Informatics', $university);
        $this->course = $this->topic('Databases', $faculty);

        $this->semester = Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->student = $this->makeUser(UserRole::Student, 'Anna Nagy');
        $this->student->forceFill(['neptun_code' => 'ANNA01'])->save();
        $this->assign($this->student, $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]), $this->course);

        $this->organiser = $this->makeUser(UserRole::Teacher, 'Ivan Organiser');
    }

    /**
     * An upcoming event the student can see and apply to, unless changed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): Event
    {
        $tag = TopicTag::firstOrCreate(['name' => 'SQL'], ['category' => 'academic', 'is_active' => true]);

        return Event::factory()->create([
            'owning_topic_id' => $this->course->id,
            'audience_topic_id' => $this->course->id,
            'topic_tag_id' => $tag->id,
            'category' => 'academic',
            'semester_id' => $this->semester->id,
            'application_type' => 'fcfs',
            'capacity' => 10,
            'points_value' => 10,
            'published_at' => now()->subDay(),
            'event_starts_at' => now()->addDays(3)->startOfMinute(),
            'event_ends_at' => now()->addDays(3)->startOfMinute()->addHours(2),
            'application_deadline' => now()->addDays(2),
            'completion_deadline' => now()->addDays(10),
            ...$attributes,
        ]);
    }

    private function applyAs(User $student, Event $event, string $status = EventApplication::ACCEPTED): void
    {
        EventApplication::create(['event_id' => $event->id, 'student_id' => $student->id, 'status' => $status, 'applied_at' => now()]);
    }

    /** A point row written straight to the ledger table, for the sums. */
    private function points(int $points, string $category, ?int $semesterId = null, ?Event $event = null): PointTransaction
    {
        $event ??= $this->event(['category' => $category, 'event_starts_at' => now()->subDays(2), 'event_ends_at' => now()->subDays(2)->addHour(), 'application_deadline' => now()->subDays(3)]);

        return PointTransaction::create([
            'student_id' => $this->student->id,
            'event_id' => $event->id,
            'semester_id' => $semesterId ?? $this->semester->id,
            'credited_by_id' => $this->organiser->id,
            'points' => $points,
            'type' => $points < 0 ? PointTransaction::REVERSAL : PointTransaction::CREDIT,
            'source' => PointLedger::PASSPORT,
            'category' => $category,
        ]);
    }

    private function home()
    {
        return $this->actingAs($this->student)->get('/');
    }

    /** A leaderboard of a topic, with the default columns (the Neptun code alone). */
    private function board(Topic $topic, string $name): Leaderboard
    {
        return Leaderboard::create(['topic_id' => $topic->id, 'name' => $name, 'created_by_id' => $this->organiser->id])->fresh();
    }

    public function test_a_student_gets_the_home_page_and_a_teacher_keeps_the_leaderboard(): void
    {
        $this->home()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('name', 'Anna Nagy')
            ->where('isStudent', true)
            ->has('leaderboards')
            ->missing('points')
            ->missing('semester')
            ->has('open')
            ->has('recent'));

        $this->actingAs($this->organiser)->get('/')
            ->assertInertia(fn (Assert $page) => $page->component('Leaderboard/Index'));
    }

    public function test_the_boxes_show_the_starred_boards_first_then_the_rest(): void
    {
        $this->points(10, 'academic');
        $faculty = $this->course->parent;
        $this->board($this->course, 'Databases total');
        $this->board($faculty, 'Informatics total');
        $university = $this->board($faculty->parent, 'University total');

        // In name order while nothing is starred; the areas above Anna's course are hers too.
        $this->home()->assertInertia(fn (Assert $page) => $page
            ->has('leaderboards', 3)
            ->where('leaderboards.0.name', 'Databases total')
            ->where('leaderboards.0.you', ['rank' => 1, 'points' => 10, 'to_next' => null, 'next_rank' => null])
            ->where('leaderboards.1.name', 'Informatics total')
            ->where('leaderboards.2.name', 'University total'));

        $this->actingAs($this->student)->post("/leaderboards/{$university->id}/highlight")->assertRedirect();

        $this->home()->assertInertia(fn (Assert $page) => $page
            ->where('leaderboards.0.name', 'University total')
            ->where('leaderboards.0.highlighted', true)
            ->where('leaderboards.1.name', 'Databases total')
            ->where('leaderboards.2.name', 'Informatics total'));
    }

    public function test_the_next_event_is_the_soonest_one_with_a_place(): void
    {
        // On a whole minute, so the seconds left are exact.
        $this->travelTo(now()->startOfMinute());
        $later = $this->event(['title' => 'Later', 'event_starts_at' => now()->addDays(5), 'event_ends_at' => now()->addDays(5)->addHour()]);
        $soon = $this->event(['title' => 'Soon', 'location' => 'Room 2.14', 'event_starts_at' => now()->addHours(26)->startOfMinute(), 'event_ends_at' => now()->addHours(28)->startOfMinute()]);
        $waiting = $this->event(['title' => 'Only waitlisted', 'event_starts_at' => now()->addHour(), 'event_ends_at' => now()->addHours(2)]);
        $over = $this->event(['title' => 'Over', 'event_starts_at' => now()->subHours(3), 'event_ends_at' => now()->subHour(), 'application_deadline' => now()->subDay()]);

        $this->applyAs($this->student, $later);
        $this->applyAs($this->student, $soon);
        $this->applyAs($this->student, $waiting, EventApplication::WAITLISTED);
        $this->applyAs($this->student, $over);

        $this->home()->assertInertia(fn (Assert $page) => $page
            ->where('next.title', 'Soon')
            ->where('next.location', 'Room 2.14')
            ->where('next.running', false)
            ->where('next.starts_in', 26 * 3600)
            ->where('next.ends_in', 28 * 3600));
    }

    public function test_an_event_that_is_running_now_is_the_next_event(): void
    {
        $running = $this->event(['title' => 'Running', 'event_starts_at' => now()->subMinutes(30), 'event_ends_at' => now()->addMinutes(90), 'application_deadline' => now()->subHour()]);
        $this->applyAs($this->student, $running);

        $this->home()->assertInertia(fn (Assert $page) => $page
            ->where('next.title', 'Running')
            ->where('next.running', true)
            ->where('next.starts_in', 0));
    }

    public function test_a_cancelled_or_held_event_is_not_the_next_event(): void
    {
        foreach ([Event::STATUS_CANCELLED, Event::STATUS_ON_HOLD] as $status) {
            $this->applyAs($this->student, $this->event(['status' => $status]));
        }

        $this->home()->assertInertia(fn (Assert $page) => $page->where('next', null));
    }

    public function test_open_events_are_the_ones_the_student_can_apply_to_now(): void
    {
        $open = $this->event(['title' => 'Open', 'capacity' => 3]);
        $this->applyAs($this->makeUser(UserRole::Student), $open);
        $applied = $this->event(['title' => 'Already applied']);
        $this->applyAs($this->student, $applied, EventApplication::PENDING);
        $this->event(['title' => 'Closed', 'application_deadline' => now()->subHour()]);
        $this->event(['title' => 'Not open yet', 'applications_open_at' => now()->addDay()]);
        $this->event(['title' => 'Surprise', 'is_hidden' => true]);
        $this->event(['title' => 'Draft', 'status' => Event::STATUS_DRAFT, 'published_at' => null]);
        // Announced to a course the student is not assigned to (Task #71)
        $this->event(['title' => 'Other course', 'owning_topic_id' => $this->topic('Programming')->id, 'audience_topic_id' => null]);

        $this->home()->assertInertia(fn (Assert $page) => $page
            ->has('open', 1)
            ->where('open.0.title', 'Open')
            ->where('open.0.places_left', 2)
            ->where('open.0.application_type', 'fcfs'));
    }

    public function test_a_student_who_left_an_event_sees_it_open_again(): void
    {
        $event = $this->event(['title' => 'Left it']);
        $this->applyAs($this->student, $event, EventApplication::WITHDRAWN);

        $this->home()->assertInertia(fn (Assert $page) => $page->where('open.0.title', 'Left it'));
    }

    public function test_at_most_three_open_events_are_shown_soonest_first(): void
    {
        foreach (range(1, 5) as $day) {
            $this->event(['title' => "Day {$day}", 'event_starts_at' => now()->addDays($day + 1), 'event_ends_at' => now()->addDays($day + 1)->addHour()]);
        }

        $this->home()->assertInertia(fn (Assert $page) => $page
            ->has('open', 3)
            ->where('open.0.title', 'Day 1')
            ->where('open.2.title', 'Day 3'));
    }

    public function test_recent_points_are_the_last_five_newest_first(): void
    {
        foreach (range(1, 6) as $i) {
            $this->travel(1)->minutes();
            $row = $this->points(10 * $i, 'academic');
            $row->event->update(['title' => "Event {$i}"]);
        }

        $this->home()->assertInertia(fn (Assert $page) => $page
            ->has('recent', 5)
            ->where('recent.0.event', 'Event 6')
            ->where('recent.0.points', 60)
            ->where('recent.0.correction', false)
            ->where('recent.4.event', 'Event 2'));
    }

    public function test_a_correction_shows_up_as_a_correction(): void
    {
        $credit = $this->points(20, 'sports');
        $this->travel(1)->minutes();
        $this->points(-20, 'sports', event: $credit->event);

        $this->home()->assertInertia(fn (Assert $page) => $page
            ->where('recent.0.points', -20)
            ->where('recent.0.correction', true));
    }

    public function test_a_new_student_sees_empty_parts(): void
    {
        $this->home()->assertInertia(fn (Assert $page) => $page
            ->has('leaderboards', 0)
            ->where('next', null)
            ->has('open', 0)
            ->has('recent', 0));
    }

    public function test_students_never_see_another_students_points_or_places(): void
    {
        $other = $this->makeUser(UserRole::Student);
        $event = $this->event();
        $this->applyAs($other, $event);
        $other->forceFill(['neptun_code' => 'OTHR99'])->save();
        PointTransaction::create(['student_id' => $other->id, 'event_id' => $event->id, 'semester_id' => $this->semester->id, 'credited_by_id' => $this->organiser->id, 'points' => 40, 'type' => PointTransaction::CREDIT, 'source' => PointLedger::PASSPORT, 'category' => 'academic']);
        $this->board($this->course, 'Databases total');

        // The box says how many are listed, never who leads: the leader is for staff.
        $this->home()->assertInertia(fn (Assert $page) => $page
            ->where('leaderboards.0.students', 1)
            ->where('leaderboards.0.you', null)
            ->where('leaderboards.0.leader', null)
            ->where('next', null)
            ->has('recent', 0));

        $this->assertStringNotContainsString('OTHR99', json_encode($this->home()->viewData('page')['props']));
    }

    public function test_more_open_events_do_not_add_queries(): void
    {
        $queries = function (): int {
            $this->home()->assertOk(); // warm-up, so caches don't skew the count
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->home()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $day) {
            $this->event(['event_starts_at' => now()->addDays($day + 1), 'event_ends_at' => now()->addDays($day + 1)->addHour()]);
        }
        $three = $queries();

        foreach (range(4, 15) as $day) {
            $this->event(['event_starts_at' => now()->addDays($day + 1), 'event_ends_at' => now()->addDays($day + 1)->addHour()]);
        }

        $this->assertSame($three, $queries());
    }
}
