<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;

/**
 * Writes over HTTP, including direct requests that skip the interface.
 */
class TopicActionsHttpTest extends TopicTestCase
{
    private Topic $root;

    private Topic $branch;

    private Topic $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->topic('Root');
        $this->branch = $this->topic('Branch', $this->root);
        $this->other = $this->topic('Other', $this->root);
    }

    // Topics -------------------------------------------------------------

    public function test_admin_creates_a_root_and_is_taken_to_it(): void
    {
        $this->actingAs($this->admin)
            ->post('/topics', ['title' => 'Programming', 'code' => 'PRG', 'description' => 'All about code'])
            ->assertRedirect();

        $topic = Topic::where('title', 'Programming')->first();
        $this->assertNull($topic->parent_id);
        $this->assertSame('PRG', $topic->code);
    }

    public function test_a_non_admin_cannot_create_a_root_through_a_direct_request(): void
    {
        $this->actingAs($this->makeUser())->post('/topics', ['title' => 'Mine'])->assertRedirect('/');

        $this->assertDatabaseMissing('topics', ['title' => 'Mine']);
    }

    public function test_validation_errors_come_back_without_creating_anything(): void
    {
        $this->actingAs($this->admin)
            ->from('/topics')
            ->post('/topics', ['title' => '', 'code' => str_repeat('x', 60)])
            ->assertSessionHasErrors(['title', 'code']);
    }

    public function test_a_contributor_adds_a_child_and_edits_only_their_own_contributions(): void
    {
        $author = $this->makeUser();
        $this->assign($author, $this->role('Author', [Capability::View, Capability::Create]), $this->branch);
        $someoneElses = $this->topic('By admin', $this->branch);

        $this->actingAs($author)->post("/topics/{$this->branch->id}/children", ['title' => 'Mine'])->assertRedirect();
        $mine = Topic::where('title', 'Mine')->first();
        $this->assertSame($author->id, $mine->created_by_id);

        $this->put("/topics/{$mine->id}", ['title' => 'Mine, renamed'])->assertRedirect();
        $this->assertSame('Mine, renamed', $mine->fresh()->title);

        $this->put("/topics/{$someoneElses->id}", ['title' => 'Hijacked'])->assertRedirect('/');
        $this->assertSame('By admin', $someoneElses->fresh()->title);
    }

    public function test_a_contributor_gains_no_access_administration_powers(): void
    {
        $author = $this->makeUser();
        $this->assign($author, $this->role('Author', [Capability::View, Capability::Create]), $this->branch);
        $child = $this->actingAs($author)->post("/topics/{$this->branch->id}/children", ['title' => 'Mine']);
        $mine = Topic::where('title', 'Mine')->first();

        $this->postJson("/topics/{$mine->id}/assignments", ['user_id' => $this->makeUser()->id, 'role_definition_id' => $this->viewer()->id])->assertForbidden();
        $this->post('/topics/roles', ['name' => 'Mine', 'capabilities' => ['topic.view'], 'scope_topic_id' => $this->branch->id])->assertRedirect('/');
        $this->assertSame(0, RoleAssignment::where('user_id', '!=', $author->id)->count());
        $this->assertNotNull($child);
    }

    public function test_ownership_does_not_continue_after_losing_access_to_the_branch(): void
    {
        $author = $this->makeUser();
        $assignment = $this->assign($author, $this->role('Author', [Capability::View, Capability::Create]), $this->branch);
        $this->actingAs($author)->post("/topics/{$this->branch->id}/children", ['title' => 'Mine']);
        $mine = Topic::where('title', 'Mine')->first();

        $assignment->update(['ended_at' => now(), 'ended_reason' => 'test']);

        $this->put("/topics/{$mine->id}", ['title' => 'Still mine?'])->assertNotFound();
        $this->assertSame('Mine', $mine->fresh()->title);
    }

    public function test_a_hidden_parent_cannot_be_targeted_by_a_direct_request(): void
    {
        $author = $this->makeUser();
        $this->assign($author, $this->role('Author', [Capability::View, Capability::Create]), $this->branch);

        $this->actingAs($author)->post("/topics/{$this->other->id}/children", ['title' => 'Sneaky'])->assertNotFound();
        $this->assertDatabaseMissing('topics', ['title' => 'Sneaky']);
    }

    public function test_an_editor_changes_other_peoples_topics_but_cannot_move_them(): void
    {
        $editor = $this->makeUser();
        $this->assign($editor, $this->role('Editor', [Capability::View, Capability::Edit]), $this->branch);
        $leaf = $this->topic('Leaf', $this->branch);

        $this->actingAs($editor)->put("/topics/{$leaf->id}", ['title' => 'Leaf edited'])->assertRedirect();
        $this->assertSame('Leaf edited', $leaf->fresh()->title);

        $this->postJson("/topics/{$leaf->id}/move/preview", ['destination_id' => $this->branch->id])->assertForbidden();
    }

    public function test_moving_over_http_previews_and_then_moves(): void
    {
        $leaf = $this->topic('Leaf', $this->branch);
        $mover = $this->makeUser();
        $this->assign($mover, $this->role('Organiser', [Capability::View, Capability::Organise]), $this->root);
        $watcher = $this->makeUser();
        $this->assign($watcher, $this->viewer(), $this->other);

        $this->actingAs($mover)
            ->postJson("/topics/{$leaf->id}/move/preview", ['destination_id' => $this->other->id])
            ->assertOk()
            ->assertJsonPath('blocked', [])
            ->assertJsonPath('gain_people', 1)
            ->assertJsonPath('topics_moved', 1);

        $this->post("/topics/{$leaf->id}/move", ['destination_id' => $this->other->id])->assertRedirect();
        $this->assertSame($this->other->id, $leaf->fresh()->parent_id);

        $this->postJson("/topics/{$leaf->id}/move/preview", ['destination_id' => $leaf->id])
            ->assertOk()->assertJsonPath('blocked.0', 'A topic cannot be moved into itself.');
        $this->postJson("/topics/{$leaf->id}/move", ['destination_id' => $leaf->id])->assertStatus(422);
    }

    public function test_the_destination_picker_lists_only_allowed_places(): void
    {
        $leaf = $this->topic('Leaf', $this->branch);
        $deeper = $this->topic('Deeper', $leaf);
        $mover = $this->makeUser();
        $this->assign($mover, $this->role('Organiser', [Capability::View, Capability::Organise]), $this->branch);

        $titles = collect($this->actingAs($mover)->getJson("/topics/{$leaf->id}/destinations")->assertOk()->json('results'))->pluck('title');

        $this->assertSame(['Branch'], $titles->all());
        $this->assertNotNull($deeper);
    }

    // Assignments --------------------------------------------------------

    public function test_giving_a_role_over_http_works_within_the_ceiling_and_is_refused_beyond_it(): void
    {
        $lead = $this->makeUser();
        $this->assign($lead, $this->role('Lead', [Capability::View, Capability::Create, Capability::Edit, Capability::AccessView, Capability::AccessAssign], [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign]), $this->branch);
        $person = $this->makeUser();
        $ok = $this->role('Author', [Capability::View, Capability::Create]);
        $tooMuch = $this->role('Editor', [Capability::View, Capability::Edit]);

        $this->actingAs($lead)->post("/topics/{$this->branch->id}/assignments", ['user_id' => $person->id, 'role_definition_id' => $tooMuch->id])->assertRedirect('/');
        $this->assertSame(0, RoleAssignment::where('user_id', $person->id)->count());

        $this->post("/topics/{$this->branch->id}/assignments", ['user_id' => $person->id, 'role_definition_id' => $ok->id])->assertRedirect();
        $this->assertSame(1, RoleAssignment::where('user_id', $person->id)->active()->count());
    }

    public function test_a_direct_request_cannot_give_a_system_admin_style_role_by_name(): void
    {
        $lead = $this->makeUser();
        $this->assign($lead, $this->role('Lead', [Capability::View, Capability::AccessView, Capability::AccessAssign], [Capability::View]), $this->branch);
        $adminNamed = $this->role('System Admin', Capability::cases());

        $this->actingAs($lead)->postJson("/topics/{$this->branch->id}/assignments", ['user_id' => $this->makeUser()->id, 'role_definition_id' => $adminNamed->id])->assertForbidden();
    }

    public function test_candidate_search_needs_the_give_roles_power_and_returns_only_active_accounts(): void
    {
        $lead = $this->makeUser();
        $this->assign($lead, $this->branchLead(), $this->branch);
        $this->makeUser(name: 'Zed Active');
        $this->makeUser(name: 'Zed Inactive', status: 'inactive');
        $reader = $this->makeUser();
        $this->assign($reader, $this->viewer(), $this->branch);

        $names = collect($this->actingAs($lead)->getJson("/topics/{$this->branch->id}/candidates?q=Zed")->assertOk()->json('results'))->pluck('name');
        $this->assertSame(['Zed Active'], $names->all());

        $this->actingAs($reader)->getJson("/topics/{$this->branch->id}/candidates?q=Zed")->assertOk()->assertJsonCount(0, 'results');
        $this->actingAs($lead)->getJson("/topics/{$this->other->id}/candidates?q=Zed")->assertNotFound();
    }

    public function test_two_step_removal_over_http_prepare_then_execute(): void
    {
        $holder = $this->makeUser(name: 'Holder');
        $assignment = $this->assign($holder, $this->viewer(), $this->branch);

        $prepared = $this->actingAs($this->admin)
            ->postJson("/topics/assignments/{$assignment->id}/prepare", ['reason' => 'Left'])
            ->assertOk()
            ->assertJsonPath('summary.person', 'Holder')
            ->assertJsonPath('summary.topic', 'Branch')
            ->json();

        // Nothing happens until the second call.
        $this->assertNull($assignment->fresh()->ended_at);

        $this->post('/topics/assignments/execute', ['token' => $prepared['token']])->assertRedirect();
        $this->assertNotNull($assignment->fresh()->ended_at);

        // Replay: refused, nothing more changes.
        $this->postJson('/topics/assignments/execute', ['token' => $prepared['token']])->assertStatus(422);
    }

    public function test_prepare_reports_authorization_and_validation_problems_as_json(): void
    {
        $holder = $this->makeUser();
        $assignment = $this->assign($holder, $this->viewer(), $this->branch);

        $this->actingAs($this->admin)->postJson("/topics/assignments/{$assignment->id}/prepare", ['reason' => ''])->assertStatus(422);
        $this->actingAs($holder)->postJson("/topics/assignments/{$assignment->id}/prepare", ['reason' => 'x'])->assertForbidden();

        $mine = $this->assign($this->admin, $this->viewer(), $this->branch);
        $this->actingAs($this->admin)->postJson("/topics/assignments/{$mine->id}/prepare", ['reason' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot end or replace your own role. Another authorised person must do it.');
    }

    public function test_prepare_for_a_hidden_assignment_looks_like_it_does_not_exist(): void
    {
        $holder = $this->makeUser();
        $assignment = $this->assign($holder, $this->viewer(), $this->other);
        $outsider = $this->makeUser();
        $this->assign($outsider, $this->branchLead(), $this->branch);

        $this->actingAs($outsider)->postJson("/topics/assignments/{$assignment->id}/prepare", ['reason' => 'x'])->assertNotFound();
    }

    public function test_handover_over_http_keeps_history_and_branch(): void
    {
        $outgoing = $this->makeUser(name: 'Outgoing');
        $incoming = $this->makeUser(name: 'Incoming');
        $role = $this->role('Manager', [Capability::View, Capability::AccessView, Capability::AccessEnd, Capability::AccessAssign]);
        $assignment = $this->assign($outgoing, $role, $this->branch);
        $child = $this->assign($this->makeUser(), $this->viewer(), $this->branch, $outgoing, $assignment);

        $token = $this->actingAs($this->admin)
            ->postJson("/topics/assignments/{$assignment->id}/prepare", ['reason' => 'Retired', 'replacement_id' => $incoming->id])
            ->assertOk()->assertJsonPath('summary.replacement', 'Incoming')->json('token');

        $this->post('/topics/assignments/execute', ['token' => $token])->assertRedirect();

        $this->assertNotNull($assignment->fresh()->ended_at);
        $this->assertNull($child->fresh()->ended_at);
        $this->assertSame(1, RoleAssignment::where('user_id', $incoming->id)->active()->where('role_definition_id', $role->id)->count());
        $this->assertSame(1, RoleDefinition::where('id', $role->id)->count());
    }

    // Roles page ---------------------------------------------------------

    public function test_the_roles_page_is_for_people_who_define_or_give_roles(): void
    {
        $reader = $this->makeUser();
        $this->assign($reader, $this->viewer(), $this->branch);
        $definer = $this->makeUser();
        $this->assign($definer, $this->role('Definer', [Capability::View, Capability::DefineRoles]), $this->branch);

        $this->actingAs($reader)->get('/topics/roles')->assertRedirect('/');
        $this->actingAs($definer)->get('/topics/roles')->assertOk()->assertInertia(fn ($page) => $page->component('Topics/Roles'));
        $this->actingAs($this->admin)->get('/topics/roles')->assertOk();
    }

    public function test_the_admin_creates_a_global_role_and_it_assigns_nothing(): void
    {
        $this->actingAs($this->admin)
            ->post('/topics/roles', ['name' => 'Assistant Admin', 'capabilities' => ['topic.view', 'topic.create'], 'delegable' => ['topic.view']])
            ->assertRedirect('/topics/roles');

        $role = RoleDefinition::where('name', 'Assistant Admin')->first();
        $this->assertNull($role->topic_id);
        $this->assertSame(0, RoleAssignment::count());
    }

    public function test_a_definer_creates_a_branch_role_but_not_a_global_one_or_one_beyond_their_ceiling(): void
    {
        $definer = $this->makeUser();
        $this->assign($definer, $this->role('Definer', [Capability::View, Capability::Create, Capability::DefineRoles], [Capability::View, Capability::Create]), $this->branch);

        $this->actingAs($definer)->post('/topics/roles', ['name' => 'Branch author', 'capabilities' => ['topic.view', 'topic.create'], 'scope_topic_id' => $this->branch->id])->assertRedirect('/topics/roles');
        $this->assertSame($this->branch->id, RoleDefinition::where('name', 'Branch author')->value('topic_id'));

        $this->post('/topics/roles', ['name' => 'Global', 'capabilities' => ['topic.view']])->assertRedirect('/');
        $this->post('/topics/roles', ['name' => 'Too much', 'capabilities' => ['topic.view', 'topic.edit'], 'scope_topic_id' => $this->branch->id])->assertRedirect('/');
        $this->post('/topics/roles', ['name' => 'Elsewhere', 'capabilities' => ['topic.view'], 'scope_topic_id' => $this->other->id])->assertNotFound();

        $this->assertSame(['Branch author'], RoleDefinition::where('name', '!=', 'Definer')->pluck('name')->all());
    }

    public function test_the_roles_list_hides_branch_roles_from_other_branches_and_the_names_of_hidden_scopes(): void
    {
        $this->role('Other only', [Capability::View], null, $this->other);
        $hiddenScope = $this->role('From above', [Capability::View], null, $this->root);
        $user = $this->makeUser();
        $this->assign($user, $this->role('Definer', [Capability::View, Capability::DefineRoles]), $this->branch);
        $this->role('Global one', [Capability::View]);

        $roles = collect($this->actingAs($user)->get('/topics/roles')->assertOk()->viewData('page')['props']['roles']);

        $this->assertFalse($roles->contains('name', 'Other only'));
        $this->assertSame('a branch above your access', $roles->firstWhere('id', $hiddenScope->id)['scope']['title']);
        $this->assertNull($roles->firstWhere('id', $hiddenScope->id)['scope']['id']);
        $this->assertTrue($roles->contains('name', 'Global one'));
    }

    public function test_editing_and_archiving_over_http(): void
    {
        $role = $this->role('Author', [Capability::View, Capability::Create]);
        $this->assign($this->makeUser(), $role, $this->branch);

        $this->actingAs($this->admin)->put("/topics/roles/{$role->id}", ['name' => 'Author', 'capabilities' => ['topic.view']])->assertSessionHasErrors('capabilities');
        $this->put("/topics/roles/{$role->id}", ['name' => 'Author', 'capabilities' => ['topic.view'], 'acknowledge_affected' => true])->assertRedirect('/topics/roles');
        $this->assertSame(['topic.view'], $role->fresh()->capabilityList());

        $this->getJson("/topics/roles/{$role->id}/usage")->assertOk()->assertJsonPath('assignments', 1);
        $this->post("/topics/roles/{$role->id}/archive", ['archived' => true])->assertRedirect('/topics/roles');
        $this->assertTrue($role->fresh()->isArchived());
        $this->assertSame(1, RoleAssignment::query()->active()->where('role_definition_id', $role->id)->count());
        $this->post("/topics/roles/{$role->id}/archive", ['archived' => false])->assertRedirect();
        $this->assertFalse($role->fresh()->isArchived());
    }

    public function test_a_local_definer_cannot_edit_or_archive_global_roles_over_http(): void
    {
        $global = $this->viewer();
        $definer = $this->makeUser();
        $this->assign($definer, $this->branchLead(), $this->root);

        $this->actingAs($definer)->put("/topics/roles/{$global->id}", ['name' => 'X', 'capabilities' => ['topic.view']])->assertRedirect('/');
        $this->post("/topics/roles/{$global->id}/archive", ['archived' => true])->assertRedirect('/');
        $this->getJson("/topics/roles/{$global->id}/usage")->assertForbidden();
        $this->assertSame('Viewer', $global->fresh()->name);
    }

    public function test_a_topic_role_named_admin_cannot_open_the_admin_panel(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->role('Admin', Capability::cases()), $this->root);

        $this->actingAs($user)->get('/admin')->assertRedirect();
        $this->actingAs($user)->get('/admin/system-admins')->assertRedirect();
        $this->assertNotNull(User::find($user->id));
    }
}
