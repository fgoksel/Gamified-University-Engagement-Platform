<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Topic;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Task #120 (N5, 9 November): everyone sees the path from the root down to their area, by name
 * only. An area above a person's own access has its name on the path and nothing else: it cannot
 * be opened, its contents, siblings, people and ids stay out, and the assignment list does not
 * change.
 */
class AreaPathTest extends TopicTestCase
{
    private Topic $hungary;

    private Topic $pecs;

    private Topic $database;

    private Topic $sql;

    private Topic $budapest;

    private Topic $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hungary = $this->topic('Hungary');
        $this->pecs = $this->topic('Pécs', $this->hungary);
        $this->database = $this->topic('Database', $this->pecs);
        $this->sql = $this->topic('SQL', $this->database);
        $this->budapest = $this->topic('Budapest', $this->hungary);
        $this->secret = $this->topic('Confidential plans', $this->hungary);
    }

    public function test_a_person_assigned_below_the_root_sees_the_name_of_every_parent(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        $this->actingAs($user)->get("/topics/{$this->sql->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Above the person's own access a name stands alone: no id, so nothing to open.
                ->where('selected.path', [
                    ['id' => null, 'title' => 'Hungary'],
                    ['id' => null, 'title' => 'Pécs'],
                    ['id' => $this->database->id, 'title' => 'Database'],
                    ['id' => $this->sql->id, 'title' => 'SQL'],
                ])
                // The tree and what it expands still start where the person's access starts.
                ->has('selected.breadcrumbs', 2)
                ->where('selected.breadcrumbs.0.title', 'Database')
                ->has('entries', 1)
                ->where('entries.0.title', 'Database')
                ->etc());
    }

    public function test_a_student_gets_the_same_path(): void
    {
        $student = $this->makeUser(UserRole::Student);
        $this->assign($student, $this->viewer(), $this->database);

        $this->actingAs($student)->get("/topics/{$this->database->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.path', [
                    ['id' => null, 'title' => 'Hungary'],
                    ['id' => null, 'title' => 'Pécs'],
                    ['id' => $this->database->id, 'title' => 'Database'],
                ])
                ->etc());
    }

    public function test_the_system_admin_can_open_every_name_on_the_path(): void
    {
        $this->actingAs($this->admin)->get("/topics/{$this->sql->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.path', [
                    ['id' => $this->hungary->id, 'title' => 'Hungary'],
                    ['id' => $this->pecs->id, 'title' => 'Pécs'],
                    ['id' => $this->database->id, 'title' => 'Database'],
                    ['id' => $this->sql->id, 'title' => 'SQL'],
                ])
                ->etc());
    }

    public function test_opening_a_parent_is_refused_and_shows_none_of_its_contents(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        foreach ([$this->hungary, $this->pecs] as $parent) {
            foreach (['', '?view=branch', '?tab=access', '?tab=events', '?tab=leaderboards'] as $query) {
                // The name is on the path, but the area is "not found", whatever the page asked for.
                $this->actingAs($user)->get("/topics/{$parent->id}{$query}")
                    ->assertNotFound()
                    ->assertDontSee('Budapest')
                    ->assertDontSee('Confidential plans');
            }

            $this->actingAs($user)->getJson("/topics/{$parent->id}/children")->assertNotFound();
        }
    }

    public function test_sibling_areas_stay_hidden(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        // Budapest and Confidential plans sit beside Pécs, under the root that the path names.
        $props = json_encode($this->actingAs($user)->get("/topics/{$this->sql->id}")->assertOk()->viewData('page')['props']);
        $this->assertStringNotContainsString('Budapest', $props);
        $this->assertStringNotContainsString('Confidential plans', $props);

        $this->actingAs($user)->getJson('/topics/search?q=Budapest')->assertOk()->assertJsonCount(0, 'results');
        $this->actingAs($user)->getJson('/topics/search?q=Confidential')->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_the_assignment_list_does_not_change_when_the_path_names_a_parent(): void
    {
        $upstairs = $this->makeUser(name: 'Upstairs');
        $mid = $this->makeUser(name: 'Mid');
        $viewer = $this->makeUser(name: 'Looker');
        $manager = $this->role('Manager', [Capability::View, Capability::AccessView]);
        $this->assign($upstairs, $this->viewer(), $this->hungary);
        $this->assign($mid, $this->viewer(), $this->pecs);
        $this->assign($viewer, $manager, $this->pecs);

        $response = $this->actingAs($viewer)->get("/topics/{$this->sql->id}?tab=access");

        $response->assertInertia(fn (Assert $page) => $page
            // The path names Hungary, which is above the viewer's own access ...
            ->where('selected.path.0', ['id' => null, 'title' => 'Hungary'])
            // ... and the list is what it was: the people on Pécs and below, and nobody on Hungary.
            ->has('selected.access.inherited', 2)
            ->where('selected.access.inherited', fn ($rows) => collect($rows)->pluck('user.name')->sort()->values()->all() === ['Looker', 'Mid'])
            ->etc());
        $this->assertStringNotContainsString('Upstairs', json_encode($response->viewData('page')['props']));
    }

    public function test_the_shorter_trails_in_lists_still_start_at_the_persons_own_access(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        // Search results keep their trail from the person's own access: the path is for the page.
        $found = collect($this->actingAs($user)->getJson('/topics/search?q=SQL')->assertOk()->json('results'));

        $this->assertSame(['Database'], $found->firstWhere('title', 'SQL')['trail']);
    }
}
