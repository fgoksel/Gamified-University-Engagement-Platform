<?php

namespace Tests\Feature\Events;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #75 (26 Oct, Q2): every event page shows its path in the tree, and the people who run the
 * event see a "Who has access" panel: everyone who can manage it and the node their right comes
 * from. Nobody has access to one event: a right is held on a node and covers the events there and
 * below, so a role that ended on that node takes the person off the panel.
 */
class EventAccessPanelTest extends EventTestCase
{
    private RoleDefinition $organiser;

    private RoleDefinition $helper;

    private RoleDefinition $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organiser = $this->role('Organiser', [Capability::View, Capability::EventsView, Capability::EventsManage, Capability::AccessView]);
        // Runs part of the event (credits points) but cannot manage it.
        $this->helper = $this->role('Helper', [Capability::View, Capability::EventsView, Capability::PointsCredit]);
        // May see who has access, but cannot manage events.
        $this->auditor = $this->role('Auditor', [Capability::View, Capability::EventsView, Capability::AccessView]);
    }

    /**
     * Sees the whole path and the panel without managing anything: they credit points and see who
     * has access, from the top of the tree. (A system administrator works in the admin panel and
     * never opens the event pages.)
     */
    private function overseer(): User
    {
        $role = $this->role('Overseer', [Capability::View, Capability::EventsView, Capability::AccessView, Capability::PointsCredit]);

        return $this->person('Alma Overseer', $role, $this->university);
    }

    private function person(string $name, RoleDefinition $role, Topic $at, string $status = 'active'): User
    {
        $user = $this->makeUser(UserRole::Teacher, $name, $status);
        $this->assign($user, $role, $at);

        return $user;
    }

    private function page(User $viewer, ?int $eventId = null)
    {
        return $this->actingAs($viewer)->get('/events/'.($eventId ?? $this->event->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $person, string $role, Topic $node, bool $you = false): array
    {
        return ['id' => $person->id, 'name' => $person->name, 'is_you' => $you, 'sources' => [['role' => $role, 'node' => ['id' => $node->id, 'title' => $node->title]]]];
    }

    public function test_the_event_page_shows_its_path_from_the_top_of_the_tree_down(): void
    {
        $path = [
            ['id' => $this->university->id, 'title' => 'University'],
            ['id' => $this->faculty->id, 'title' => 'Faculty'],
            ['id' => $this->course->id, 'title' => 'Databases'],
        ];
        $student = $this->student();
        $this->assign($student, $this->studentRole(), $this->course);

        // Everyone who can open the event sees where it sits, a student too.
        foreach ([$this->overseer(), $this->person('Ivan Kovacs', $this->organiser, $this->course), $student] as $viewer) {
            $this->page($viewer)->assertInertia(fn (Assert $page) => $page->component('Events/Show')->where('path', $path));
        }
    }

    public function test_the_panel_lists_every_manager_with_the_node_their_right_comes_from(): void
    {
        $anna = $this->person('Anna Course', $this->organiser, $this->course);
        $bence = $this->person('Bence Faculty', $this->organiser, $this->faculty);
        $csilla = $this->person('Csilla University', $this->organiser, $this->university);

        // None of these can manage this event.
        $this->person('Dora Web', $this->organiser, $this->topic('Web', $this->faculty));   // another branch
        $this->person('Erik Helper', $this->helper, $this->course);                         // runs part of it, cannot manage
        $this->person('Fanni Inactive', $this->organiser, $this->course, 'inactive');       // deactivated account
        $student = $this->student('Gabor Student');
        $this->assign($student, $this->studentRole(), $this->course);

        $this->page($this->overseer())->assertInertia(fn (Assert $page) => $page->where('managers', [
            $this->row($anna, 'Organiser', $this->course),
            $this->row($bence, 'Organiser', $this->faculty),
            $this->row($csilla, 'Organiser', $this->university),
        ]));
    }

    public function test_system_administrators_hold_no_node_and_are_not_listed(): void
    {
        $this->person('Anna Course', $this->organiser, $this->course);

        $listed = collect($this->page($this->overseer())->viewData('page')['props']['managers'])->pluck('name');

        $this->assertSame(['Anna Course'], $listed->all());
        $this->assertNotContains($this->admin->name, $listed->all());
    }

    public function test_a_person_with_rights_from_two_nodes_is_listed_once_with_both_nearest_first(): void
    {
        $gina = $this->person('Gina Both', $this->organiser, $this->faculty);
        $lead = $this->role('Course lead', [Capability::View, Capability::EventsView, Capability::EventsManage]);
        $this->assign($gina, $lead, $this->course);

        $this->page($this->overseer())->assertInertia(fn (Assert $page) => $page->where('managers', [[
            'id' => $gina->id,
            'name' => 'Gina Both',
            'is_you' => false,
            'sources' => [
                ['role' => 'Course lead', 'node' => ['id' => $this->course->id, 'title' => 'Databases']],
                ['role' => 'Organiser', 'node' => ['id' => $this->faculty->id, 'title' => 'Faculty']],
            ],
        ]]));
    }

    public function test_a_role_that_ended_on_that_node_removes_the_person_from_the_panel(): void
    {
        $hanna = $this->person('Hanna Faculty', $this->organiser, $this->faculty);
        $viewer = $this->person('Ivan Course', $this->organiser, $this->course);
        $assignment = RoleAssignment::query()->where('user_id', $hanna->id)->firstOrFail();

        $this->page($this->overseer())->assertInertia(fn (Assert $page) => $page->where('managers.0.name', 'Hanna Faculty')->where('managers.1.name', 'Ivan Course'));

        $assignment->update(['ended_at' => now(), 'ended_reason' => 'Left the faculty']);

        $this->page($this->overseer())->assertInertia(fn (Assert $page) => $page->where('managers', [$this->row($viewer, 'Organiser', $this->course)]));
    }

    public function test_a_person_stays_on_the_panel_with_the_node_that_is_left_when_one_of_two_roles_ends(): void
    {
        $gina = $this->person('Gina Both', $this->organiser, $this->faculty);
        $this->assign($gina, $this->organiser, $this->course);

        RoleAssignment::query()->where('user_id', $gina->id)->where('topic_id', $this->course->id)->firstOrFail()->update(['ended_at' => now()]);

        $this->page($this->overseer())->assertInertia(fn (Assert $page) => $page->where('managers', [$this->row($gina, 'Organiser', $this->faculty)]));
    }

    public function test_someone_who_only_applies_sees_the_path_but_not_who_has_access(): void
    {
        $this->person('Anna Course', $this->organiser, $this->course);
        $student = $this->student();
        $this->assign($student, $this->studentRole(), $this->course);

        $response = $this->page($student);
        $response->assertInertia(fn (Assert $page) => $page->has('path', 3)->where('managers', null));

        // Nothing about the managers travels with the page.
        $props = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString('Anna Course', $props);
        $this->assertStringNotContainsString('Organiser', $props);
    }

    public function test_someone_who_may_see_who_has_access_sees_the_panel_without_running_the_event(): void
    {
        $this->person('Anna Course', $this->organiser, $this->course);
        $auditor = $this->person('Auditor', $this->auditor, $this->course);

        $this->page($auditor)->assertInertia(fn (Assert $page) => $page->has('managers', 1)->where('managers.0.name', 'Anna Course'));
    }

    public function test_a_node_above_the_viewers_own_access_is_not_named(): void
    {
        $viewer = $this->person('Ivan Course', $this->organiser, $this->course);
        $bence = $this->person('Bence Faculty', $this->organiser, $this->faculty);

        $this->page($viewer)->assertInertia(fn (Assert $page) => $page->where('managers', [
            ['id' => $bence->id, 'name' => 'Bence Faculty', 'is_you' => false, 'sources' => [['role' => 'Organiser', 'node' => ['id' => null, 'title' => 'a branch above your access']]]],
            $this->row($viewer, 'Organiser', $this->course, true),
        ]));
    }

    public function test_the_viewer_sees_which_row_is_theirs(): void
    {
        $anna = $this->person('Anna Course', $this->organiser, $this->course);
        $bence = $this->person('Bence Course', $this->organiser, $this->course);

        $this->page($anna)->assertInertia(fn (Assert $page) => $page->where('managers', [
            $this->row($anna, 'Organiser', $this->course, true),
            $this->row($bence, 'Organiser', $this->course),
        ]));
    }

    public function test_people_are_listed_by_name_not_in_the_order_they_got_their_role(): void
    {
        $zoltan = $this->person('Zoltan Late', $this->organiser, $this->course);
        $aron = $this->person('Aron Early', $this->organiser, $this->faculty);

        $this->page($this->overseer())->assertInertia(fn (Assert $page) => $page->where('managers', [
            $this->row($aron, 'Organiser', $this->faculty),
            $this->row($zoltan, 'Organiser', $this->course),
        ]));
    }

    public function test_an_event_owned_higher_up_lists_only_the_people_whose_right_covers_that_node(): void
    {
        $this->person('Anna Course', $this->organiser, $this->course);
        $bence = $this->person('Bence Faculty', $this->organiser, $this->faculty);
        $csilla = $this->person('Csilla University', $this->organiser, $this->university);
        $event = $this->makeEvent(['owning_topic_id' => $this->faculty->id, 'audience_topic_id' => $this->faculty->id]);

        $this->page($this->overseer(), $event->id)->assertInertia(fn (Assert $page) => $page
            ->where('path', [['id' => $this->university->id, 'title' => 'University'], ['id' => $this->faculty->id, 'title' => 'Faculty']])
            ->where('managers', [$this->row($bence, 'Organiser', $this->faculty), $this->row($csilla, 'Organiser', $this->university)]));
    }

    public function test_an_event_nobody_manages_gets_an_empty_panel(): void
    {
        $this->page($this->overseer())->assertInertia(fn (Assert $page) => $page->where('managers', []));
    }
}
