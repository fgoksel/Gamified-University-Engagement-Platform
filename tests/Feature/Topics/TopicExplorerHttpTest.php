<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The explorer over HTTP: entry points, deep links, visibility, counts,
 * search and the whole-branch view all apply the same rules.
 */
class TopicExplorerHttpTest extends TopicTestCase
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

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/topics')->assertRedirect('/login');
        $this->getJson("/topics/{$this->hungary->id}/children")->assertUnauthorized();
    }

    public function test_the_admin_sees_every_root_and_a_single_entry_opens_directly(): void
    {
        $this->topic('Programming');

        $this->actingAs($this->admin)->get('/topics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Topics/Explorer')
                ->where('isAdmin', true)
                ->has('entries', 2)
                ->where('selected', null));

        // With a single root the explorer opens it straight away.
        Topic::where('title', 'Programming')->first()->delete();
        DB::table('topic_closure')->where('ancestor_id', '>', 0)->whereNotIn('ancestor_id', Topic::pluck('id'))->delete();
        $this->actingAs($this->admin)->get('/topics')->assertRedirect("/topics/{$this->hungary->id}");
    }

    public function test_a_fresh_install_shows_an_empty_state_to_the_admin(): void
    {
        DB::table('topic_closure')->delete();
        Topic::query()->update(['parent_id' => null]);
        Topic::query()->delete();

        $this->actingAs($this->admin)->get('/topics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries', 0)->where('selected', null));
    }

    public function test_a_branch_only_user_enters_at_their_branch_and_sees_nothing_above_or_beside(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        $this->actingAs($user)->get('/topics?start=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.title', 'Database'));

        $this->actingAs($user)->get("/topics/{$this->sql->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.title', 'SQL')
                ->where('selected.breadcrumbs.0.title', 'Database')
                ->has('selected.breadcrumbs', 2)
                ->missing('selected.access.here')
                ->etc());
    }

    public function test_inaccessible_topics_look_exactly_like_missing_ones(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        foreach ([$this->hungary, $this->pecs, $this->budapest, $this->secret] as $hidden) {
            $this->actingAs($user)->get("/topics/{$hidden->id}")->assertNotFound();
            $this->actingAs($user)->getJson("/topics/{$hidden->id}/children")->assertNotFound();
        }
        $this->actingAs($user)->get('/topics/999999')->assertNotFound();
    }

    public function test_children_of_a_visible_topic_do_not_leak_siblings_or_parents(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        $this->actingAs($user)->getJson("/topics/{$this->database->id}/children")
            ->assertOk()
            ->assertJsonCount(1, 'children')
            ->assertJsonPath('children.0.title', 'SQL');
    }

    public function test_search_finds_only_what_the_person_may_see_and_hides_ancestors(): void
    {
        $this->topic('Database design', $this->budapest);
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        $response = $this->actingAs($user)->getJson('/topics/search?q=Data')->assertOk();
        $titles = collect($response->json('results'))->pluck('title');

        $this->assertSame(['Database'], $titles->all());
        $this->assertSame([], $response->json('results.0.trail'));

        $this->actingAs($user)->getJson('/topics/search?q=Confidential')->assertOk()->assertJsonCount(0, 'results');
        $this->actingAs($user)->getJson('/topics/search?q=Hung')->assertOk()->assertJsonCount(0, 'results');
        $this->actingAs($user)->getJson('/topics/search?q=D')->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_search_trails_show_only_accessible_ancestors(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->pecs);

        $result = $this->actingAs($user)->getJson('/topics/search?q=SQL')->json('results.0');

        $this->assertSame(['Pécs', 'Database'], $result['trail']);
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->topic('100% sure', $this->budapest);
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->hungary);

        $this->actingAs($user)->getJson('/topics/search?q=%25%25')->assertOk()->assertJsonCount(0, 'results');
        $this->actingAs($user)->getJson('/topics/search?q=100%25')->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_direct_contents_and_whole_branch_are_different_lists(): void
    {
        $this->actingAs($this->admin)->get("/topics/{$this->hungary->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('selected.children', 3)
                ->where('selected.branch', null));

        $this->actingAs($this->admin)->get("/topics/{$this->hungary->id}?view=branch")
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'branch')
                ->where('selected.branch.total', 5)
                ->has('selected.branch.items', 5)
                ->where('selected.branch.items.0.title', fn ($title) => in_array($title, ['Pécs', 'Budapest', 'Confidential plans'], true))
                ->where('selected.counts.topics_below', 5));
    }

    public function test_whole_branch_lists_only_visible_topics_with_context_and_filters_and_paginates(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->topic("Lesson {$i}", $this->sql);
        }
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        $this->actingAs($user)->get("/topics/{$this->database->id}?view=branch")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.branch.total', 31)
                ->has('selected.branch.items', 25)
                ->where('selected.branch.last_page', 2)
                ->where('selected.branch.items.0.title', 'SQL')
                ->where('selected.branch.items.0.trail', [])
                ->where('selected.branch.items.1.trail', ['SQL']));

        $this->actingAs($user)->get("/topics/{$this->database->id}?view=branch&page=2")
            ->assertInertia(fn (Assert $page) => $page->has('selected.branch.items', 6));

        $this->actingAs($user)->get("/topics/{$this->database->id}?view=branch&q=Lesson 3")
            ->assertInertia(fn (Assert $page) => $page->where('selected.branch.total', 2));
    }

    public function test_counts_distinguish_people_from_assignments_and_need_the_access_view_power(): void
    {
        $lead = $this->makeUser(name: 'Lead');
        $reader = $this->makeUser(name: 'Reader');
        $author = $this->role('Author', [Capability::View, Capability::Create]);
        $this->assign($lead, $this->role('Lead', [Capability::View, Capability::AccessView]), $this->pecs);
        $this->assign($reader, $this->viewer(), $this->database);
        $this->assign($reader, $author, $this->database);
        $this->assign($reader, $author, $this->sql);

        $this->actingAs($lead)->get("/topics/{$this->database->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.counts.people_here', 1)
                ->where('selected.counts.assignments_here', 2)
                ->where('selected.counts.people_branch', 1)
                ->where('selected.counts.assignments_branch', 3)
                ->where('selected.counts.people_effective', 2)
                ->where('selected.counts.assignments_effective', 3));

        // The reader has no "see who has access" power: only the topic count appears.
        $this->actingAs($reader)->get("/topics/{$this->database->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('selected.counts.topics_below')
                ->missing('selected.counts.people_here'));
    }

    public function test_the_access_tab_separates_assigned_here_from_inherited_and_hides_inaccessible_ancestors(): void
    {
        $upstairs = $this->makeUser(name: 'Upstairs');
        $mid = $this->makeUser(name: 'Mid');
        $viewer = $this->makeUser(name: 'Looker');
        $local = $this->makeUser(name: 'Local');
        $role = $this->role('Manager', [Capability::View, Capability::AccessView]);
        $this->assign($upstairs, $this->viewer(), $this->hungary);
        $this->assign($mid, $this->viewer(), $this->pecs);
        $this->assign($local, $this->viewer(), $this->sql);
        $this->assign($viewer, $role, $this->pecs);

        $this->actingAs($viewer)->get("/topics/{$this->sql->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'access')
                ->where('selected.access.here.0.user.name', 'Local')
                ->has('selected.access.inherited', 2)
                ->where('selected.access.inherited.0.topic.title', 'Pécs')
                ->where('selected.access.inherited', fn ($rows) => collect($rows)->pluck('user.name')->sort()->values()->all() === ['Looker', 'Mid']));
    }

    public function test_the_access_tab_is_not_available_without_an_access_power(): void
    {
        $reader = $this->makeUser();
        $this->assign($reader, $this->viewer(), $this->database);

        $this->actingAs($reader)->get("/topics/{$this->database->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'topic')->where('selected.access', null));
    }

    public function test_abilities_reflect_the_powers_at_the_topic(): void
    {
        $author = $this->makeUser();
        $this->assign($author, $this->role('Author', [Capability::View, Capability::Create]), $this->database);

        $this->actingAs($author)->get("/topics/{$this->database->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.create', true)
                ->where('selected.can.edit', false)
                ->where('selected.can.organise', false)
                ->where('selected.can.assign', false)
                ->where('selected.can.define_roles', false));
    }

    public function test_the_last_opened_topic_is_restored_while_still_permitted(): void
    {
        $user = $this->makeUser();
        $assignment = $this->assign($user, $this->viewer(), $this->database);
        $other = $this->assign($user, $this->viewer(), $this->budapest);

        $this->actingAs($user)->get("/topics/{$this->sql->id}")->assertOk();
        $this->get('/topics')->assertRedirect("/topics/{$this->sql->id}");

        $assignment->update(['ended_at' => now(), 'ended_reason' => 'test']);

        // No longer permitted: falls back to the entry (the single remaining one).
        $this->get('/topics')->assertRedirect("/topics/{$this->budapest->id}");
        $this->assertNotNull($other);
    }

    public function test_the_start_page_always_lists_the_entries(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);
        $this->actingAs($user)->get("/topics/{$this->database->id}")->assertOk();

        $this->get('/topics?start=1')->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('selected', null));
    }

    public function test_a_deep_link_carries_the_ancestors_children_so_the_tree_opens_expanded(): void
    {
        $this->actingAs($this->admin)->get("/topics/{$this->sql->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('expanded', 4)
                ->where("expanded.{$this->hungary->id}", fn ($children) => count($children) === 3));
    }

    public function test_shared_menu_flags_follow_access(): void
    {
        $nobody = $this->makeUser();
        $reader = $this->makeUser();
        $this->assign($reader, $this->viewer(), $this->database);
        $lead = $this->makeUser();
        $this->assign($lead, $this->branchLead(), $this->database);

        $this->actingAs($nobody)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('topics.available', false)->where('topics.roles', false));
        $this->actingAs($reader)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('topics.available', true)->where('topics.roles', false));
        $this->actingAs($lead)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('topics.roles', true));
        $this->actingAs($this->admin)->get('/topics?start=1')->assertInertia(fn (Assert $page) => $page->where('topics.available', true)->where('topics.roles', true));
    }

    public function test_old_my_courses_addresses_lead_to_topics(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/my-courses')->assertRedirect('/topics');
        $this->actingAs($user)->get('/my-courses/12')->assertRedirect('/topics');
    }

    public function test_an_inactive_account_cannot_use_topics(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);
        $user->update(['status' => 'inactive']);

        $this->actingAs($user)->get("/topics/{$this->database->id}")->assertRedirect('/login');
    }

    public function test_a_user_who_must_change_their_password_is_sent_there_first(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(UserRole::Teacher);

        $this->actingAs($user)->get('/topics')->assertRedirect(route('password.change'));
        $this->assertSame(0, RoleAssignment::count());
    }
}
