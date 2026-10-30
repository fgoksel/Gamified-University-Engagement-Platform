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
use App\Services\TopicAccess;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #71 (meeting of 26 Oct, Q1): a student sees and applies to an event only if they are in
 * the set of students assigned to the event's node. Nothing flows up or down the tree: not from
 * a faculty to its courses, and not from a course to its faculty. The three demo cases of
 * 25 October are the first three tests.
 */
class EventAudienceTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $databases;

    private Topic $webDevelopment;

    private RoleDefinition $studentRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->university = $this->topic('Demo University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->databases = $this->topic('Databases', $this->faculty);
        $this->webDevelopment = $this->topic('Web Development', $this->faculty);

        Semester::create([
            'name' => '2026/27 autumn',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $this->studentRole = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
    }

    /** A student assigned to the given nodes (more than one is what an import with parent areas ticked does). */
    private function student(string $neptun, Topic ...$nodes): User
    {
        $student = $this->makeUser(UserRole::Student);
        $student->forceFill(['neptun_code' => $neptun])->save();

        foreach ($nodes as $node) {
            $this->assign($student, $this->studentRole, $node);
        }

        return $student;
    }

    private function event(string $title, Topic $announcedTo, array $attributes = []): Event
    {
        return Event::factory()->create([
            'title' => $title,
            'owning_topic_id' => $this->databases->id,
            'audience_topic_id' => $announcedTo->id,
            'topic_tag_id' => TopicTag::firstOrCreate(['name' => 'SQL'], ['category' => 'scientific', 'is_active' => true])->id,
            'category' => 'scientific',
            'capacity' => 20,
            'event_starts_at' => now()->addDays(5),
            'event_ends_at' => now()->addDays(5)->addHours(2),
            'application_deadline' => now()->addDays(4),
            'completion_deadline' => now()->addDays(12),
            ...$attributes,
        ]);
    }

    /** @return list<string> */
    private function listed(User $user): array
    {
        $titles = [];

        $this->actingAs($user)->get('/events')->assertOk()
            ->assertInertia(function (Assert $page) use (&$titles) {
                $titles = collect($page->toArray()['props']['events']['data'])->pluck('title')->sort()->values()->all();
            });

        return $titles;
    }

    private function mayOpen(User $user, Event $event): bool
    {
        return Gate::forUser($user)->allows('view', $event);
    }

    public function test_a_faculty_student_sees_an_event_announced_to_the_faculty(): void
    {
        $event = $this->event('Faculty open day', $this->faculty);
        $student = $this->student('FAC001', $this->faculty);

        $this->assertSame(['Faculty open day'], $this->listed($student));
        $this->assertTrue($this->mayOpen($student, $event));
        $this->actingAs($student)->get("/events/{$event->id}")->assertOk();
    }

    public function test_a_course_student_does_not_see_an_event_announced_to_the_faculty(): void
    {
        $event = $this->event('Faculty open day', $this->faculty);
        $student = $this->student('DB0001', $this->databases);

        $this->assertSame([], $this->listed($student));
        $this->assertFalse($this->mayOpen($student, $event));
        // The address typed in directly looks like an event that does not exist.
        $this->actingAs($student)->get("/events/{$event->id}")->assertNotFound();
    }

    public function test_a_faculty_student_does_not_see_the_event_of_a_course_they_do_not_take(): void
    {
        $event = $this->event('Web Development lab', $this->webDevelopment);
        $student = $this->student('FAC002', $this->faculty);

        $this->assertSame([], $this->listed($student));
        $this->assertFalse($this->mayOpen($student, $event));
        $this->actingAs($student)->get("/events/{$event->id}")->assertNotFound();
    }

    public function test_applying_follows_the_same_rule_as_seeing(): void
    {
        $event = $this->event('Web Development lab', $this->webDevelopment);
        $outsider = $this->student('FAC003', $this->faculty);
        $member = $this->student('WEB001', $this->webDevelopment);

        $this->actingAs($outsider)->post("/events/{$event->id}/apply")
            ->assertSessionHasErrors(['event' => 'You do not belong to the audience for this event.']);
        $this->assertSame(0, EventApplication::count());

        $this->actingAs($member)->post("/events/{$event->id}/apply")->assertSessionHasNoErrors();
        $this->assertSame(1, EventApplication::count());
    }

    public function test_a_student_assigned_at_both_levels_sees_both_events(): void
    {
        // Importing at a course with the faculty ticked assigns the student to both (Task #72).
        $this->event('Faculty open day', $this->faculty);
        $this->event('Databases lab', $this->databases);
        $this->event('Web Development lab', $this->webDevelopment);
        $student = $this->student('BOTH01', $this->databases, $this->faculty);

        $this->assertSame(['Databases lab', 'Faculty open day'], $this->listed($student));
    }

    public function test_the_list_and_the_policy_agree(): void
    {
        $events = [
            $this->event('Faculty open day', $this->faculty),
            $this->event('Databases lab', $this->databases),
            $this->event('Web Development lab', $this->webDevelopment),
            $this->event('Whole university', $this->university),
        ];

        foreach ([$this->student('A', $this->faculty), $this->student('B', $this->databases), $this->student('C', $this->university, $this->webDevelopment)] as $student) {
            $expected = collect($events)->filter(fn (Event $e) => $this->mayOpen($student, $e))->pluck('title')->sort()->values()->all();

            $this->assertSame($expected, $this->listed($student));
        }
    }

    public function test_the_people_who_run_the_event_still_see_it_wherever_it_is_announced(): void
    {
        $event = $this->event('Faculty open day', $this->faculty);
        $organiser = $this->makeUser(UserRole::Teacher);
        $this->assign($organiser, $this->role('Organiser', [Capability::View, Capability::EventsView, Capability::EventsCreate, Capability::EventsManage]), $this->databases);

        $this->assertTrue($this->mayOpen($organiser, $event));
        $this->assertSame(['Faculty open day'], $this->listed($organiser));
    }

    public function test_an_archived_audience_node_hides_its_events_even_from_its_students(): void
    {
        $event = $this->event('Databases lab', $this->databases, ['owning_topic_id' => $this->faculty->id]);
        $student = $this->student('DB0002', $this->databases);

        $this->assertTrue($this->mayOpen($student, $event));

        $this->databases->forceFill(['archived_at' => now()])->save();
        app(TopicAccess::class)->flush();

        $this->assertFalse($this->mayOpen($student, $event));
        $this->assertSame([], $this->listed($student));
    }

    public function test_a_node_inside_an_archived_branch_hides_its_events_from_the_list_and_the_policy(): void
    {
        // Run from the university, announced to Databases; the faculty in between is archived.
        $event = $this->event('Databases lab', $this->databases, ['owning_topic_id' => $this->university->id]);
        $student = $this->student('DB0003', $this->databases);

        $this->assertSame(['Databases lab'], $this->listed($student));

        $this->faculty->forceFill(['archived_at' => now()])->save();
        app(TopicAccess::class)->flush();

        $this->assertFalse($this->mayOpen($student, $event));
        $this->assertSame([], $this->listed($student));
    }
}
