<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Services\AssignmentService;
use App\Services\RoleService;
use App\Services\TopicAccess;
use App\Services\TopicService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Escalation attempts: giving roles and defining roles stay inside what the
 * actor may pass on, in scope, and never through names, edits, moves or
 * direct requests.
 */
class TopicDelegationTest extends TopicTestCase
{
    private Topic $root;

    private Topic $branch;

    private Topic $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->topic('Root');
        $this->branch = $this->topic('Branch', $this->root);
        $this->other = $this->topic('Other branch', $this->root);
    }

    private function assignments(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    public function test_the_system_admin_can_give_any_role_anywhere(): void
    {
        $person = $this->makeUser();

        $assignment = $this->assignments()->grant($this->admin, $this->branch, $this->branchLead(), $person);

        $this->assertSame(['via' => 'system_admin'], $assignment->authority_snapshot);
        $this->assertSame($this->admin->id, $assignment->granted_by_id);
    }

    public function test_a_branch_lead_gives_roles_inside_the_branch_within_their_ceiling_and_provenance_is_recorded(): void
    {
        $lead = $this->makeUser(name: 'Lead');
        $leadAssignment = $this->assign($lead, $this->role('Lead', [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign], [Capability::View, Capability::Create]), $this->branch);
        $author = $this->role('Author', [Capability::View, Capability::Create]);
        $person = $this->makeUser();

        $assignment = $this->assignments()->grant($lead, $this->branch, $author, $person);

        $this->assertSame($leadAssignment->id, $assignment->granted_by_assignment_id);
        $this->assertSame('assignment', $assignment->authority_snapshot['via']);
        $this->assertSame('Lead', $assignment->authority_snapshot['role_name']);
        $this->assertEqualsCanonicalizing([Capability::View->value, Capability::Create->value], $assignment->authority_snapshot['delegable']);
    }

    public function test_cannot_give_a_role_with_powers_beyond_what_you_may_pass_on(): void
    {
        $lead = $this->makeUser();
        $this->assign($lead, $this->role('Lead', [Capability::View, Capability::Create, Capability::Edit, Capability::AccessView, Capability::AccessAssign], [Capability::View, Capability::Create]), $this->branch);

        // Holding Edit is not the same as being allowed to pass Edit on.
        $this->expectException(AuthorizationException::class);
        $this->assignments()->grant($lead, $this->branch, $this->role('Editor', [Capability::View, Capability::Edit]), $this->makeUser());
    }

    public function test_cannot_give_roles_without_the_give_roles_capability_even_with_a_big_ceiling(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->role('Writer', [Capability::View, Capability::Create, Capability::Edit]), $this->branch);

        $this->expectException(AuthorizationException::class);
        $this->assignments()->grant($user, $this->branch, $this->viewer(), $this->makeUser());
    }

    public function test_cannot_give_roles_outside_the_branch(): void
    {
        $lead = $this->makeUser();
        $this->assign($lead, $this->branchLead(), $this->branch);

        $this->expectException(AuthorizationException::class);
        $this->assignments()->grant($lead, $this->other, $this->viewer(), $this->makeUser());
    }

    public function test_cannot_give_yourself_a_role(): void
    {
        $lead = $this->makeUser();
        $this->assign($lead, $this->branchLead(), $this->branch);

        $this->expectException(AuthorizationException::class);
        $this->assignments()->grant($lead, $this->branch, $this->viewer(), $lead);
    }

    public function test_cannot_use_a_branch_role_in_a_different_branch(): void
    {
        $scoped = $this->role('Branch only', [Capability::View], null, $this->other);
        $lead = $this->makeUser();
        $this->assign($lead, $this->branchLead(), $this->root);

        $this->expectException(AuthorizationException::class);
        $this->assignments()->grant($lead, $this->branch, $scoped, $this->makeUser());
    }

    public function test_a_branch_role_can_be_used_inside_its_branch(): void
    {
        $child = $this->topic('Child', $this->other);
        $scoped = $this->role('Branch only', [Capability::View], null, $this->other);

        $assignment = $this->assignments()->grant($this->admin, $child, $scoped, $this->makeUser());

        $this->assertSame($child->id, $assignment->topic_id);
    }

    public function test_only_active_accounts_can_receive_roles_and_duplicates_are_refused(): void
    {
        $inactive = $this->makeUser(status: 'inactive');
        $invited = $this->makeUser(status: 'invited');
        $role = $this->viewer();

        foreach ([$inactive, $invited] as $person) {
            try {
                $this->assignments()->grant($this->admin, $this->branch, $role, $person);
                $this->fail('Should refuse a non-active account.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('user_id', $exception->errors());
            }
        }

        $person = $this->makeUser();
        $this->assignments()->grant($this->admin, $this->branch, $role, $person);

        $this->expectException(ValidationException::class);
        $this->assignments()->grant($this->admin, $this->branch, $role, $person);
    }

    public function test_an_archived_role_cannot_be_given_to_anyone_new_but_existing_holders_keep_access(): void
    {
        $role = $this->viewer();
        $holder = $this->makeUser();
        $this->assign($holder, $role, $this->branch);

        app(RoleService::class)->setArchived($this->admin, $role, true);

        $this->assertTrue(app(TopicAccess::class)->canView($holder, $this->branch));

        $this->expectException(ValidationException::class);
        $this->assignments()->grant($this->admin, $this->branch, $role->fresh(), $this->makeUser());
    }

    // Role definitions ---------------------------------------------------

    public function test_only_a_system_admin_defines_global_roles(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->branchLead(), $this->root);

        $this->expectException(AuthorizationException::class);
        app(RoleService::class)->create($user, null, ['name' => 'Global', 'capabilities' => [Capability::View->value]]);
    }

    public function test_a_delegated_definer_creates_roles_only_within_their_ceiling(): void
    {
        $definer = $this->makeUser();
        $this->assign($definer, $this->role('Definer', [Capability::View, Capability::Create, Capability::DefineRoles], [Capability::View, Capability::Create]), $this->branch);

        $role = app(RoleService::class)->create($definer, $this->branch, [
            'name' => 'Local author',
            'capabilities' => [Capability::View->value, Capability::Create->value],
        ]);
        $this->assertSame($this->branch->id, $role->topic_id);

        $this->expectException(AuthorizationException::class);
        app(RoleService::class)->create($definer, $this->branch, [
            'name' => 'Local editor',
            'capabilities' => [Capability::View->value, Capability::Edit->value],
        ]);
    }

    public function test_a_definer_cannot_pass_on_more_than_they_define_even_through_the_delegable_list(): void
    {
        $definer = $this->makeUser();
        $this->assign($definer, $this->role('Definer', [Capability::View, Capability::DefineRoles, Capability::Create], [Capability::View]), $this->branch);

        $this->expectException(AuthorizationException::class);
        app(RoleService::class)->create($definer, $this->branch, [
            'name' => 'Sneaky',
            'capabilities' => [Capability::View->value, Capability::Create->value],
            'delegable' => [Capability::View->value, Capability::Create->value],
        ]);
    }

    public function test_cannot_define_roles_in_a_branch_where_you_lack_the_capability(): void
    {
        $definer = $this->makeUser();
        $this->assign($definer, $this->role('Definer', [Capability::View, Capability::DefineRoles]), $this->branch);

        $this->expectException(AuthorizationException::class);
        app(RoleService::class)->create($definer, $this->other, ['name' => 'Elsewhere', 'capabilities' => [Capability::View->value]]);
    }

    public function test_a_local_definer_cannot_edit_a_global_definition(): void
    {
        $global = $this->viewer();
        $definer = $this->makeUser();
        $this->assign($definer, $this->branchLead(), $this->root);

        $this->expectException(AuthorizationException::class);
        app(RoleService::class)->update($definer, $global, ['name' => 'Hacked', 'capabilities' => [Capability::View->value]]);
    }

    public function test_a_definer_cannot_expand_an_existing_role_beyond_their_ceiling(): void
    {
        $definer = $this->makeUser();
        $this->assign($definer, $this->role('Definer', [Capability::View, Capability::DefineRoles, Capability::Create], [Capability::View, Capability::Create]), $this->branch);
        $local = $this->role('Local', [Capability::View], null, $this->branch);

        $this->expectException(AuthorizationException::class);
        app(RoleService::class)->update($definer, $local, ['name' => 'Local', 'capabilities' => [Capability::View->value, Capability::AccessView->value, Capability::AccessAssign->value]]);
    }

    public function test_a_definer_cannot_edit_a_role_that_is_stronger_than_they_may_pass_on(): void
    {
        $definer = $this->makeUser();
        $this->assign($definer, $this->role('Definer', [Capability::View, Capability::DefineRoles], [Capability::View]), $this->branch);
        $strong = $this->role('Strong', [Capability::View, Capability::Edit], null, $this->branch);

        $this->expectException(AuthorizationException::class);
        app(RoleService::class)->update($definer, $strong, ['name' => 'Weakened', 'capabilities' => [Capability::View->value]]);
    }

    public function test_nobody_changes_the_powers_of_a_role_they_hold_themselves(): void
    {
        $definer = $this->makeUser();
        $mine = $this->role('Mine', [Capability::View, Capability::DefineRoles, Capability::Create], null, $this->branch);
        $this->assign($definer, $mine, $this->branch);

        try {
            app(RoleService::class)->update($definer, $mine, ['name' => 'Mine', 'capabilities' => [Capability::View->value, Capability::DefineRoles->value]]);
            $this->fail('Self-edit of powers should be refused.');
        } catch (AuthorizationException) {
        }

        try {
            app(RoleService::class)->setArchived($definer, $mine, true);
            $this->fail('Archiving your own role should be refused.');
        } catch (AuthorizationException) {
        }

        // A rename does not change powers and is allowed.
        $renamed = app(RoleService::class)->update($definer, $mine, ['name' => 'Renamed', 'capabilities' => $mine->capabilityList(), 'delegable' => $mine->delegableList()]);
        $this->assertSame('Renamed', $renamed->name);
    }

    public function test_changing_a_used_role_needs_acknowledgement_of_affected_assignments(): void
    {
        $role = $this->role('Author', [Capability::View, Capability::Create]);
        $this->assign($this->makeUser(), $role, $this->branch);
        $this->assign($this->makeUser(), $role, $this->other);

        try {
            app(RoleService::class)->update($this->admin, $role, ['name' => 'Author', 'capabilities' => [Capability::View->value]]);
            $this->fail('Should need acknowledgement.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('2 active assignment', $exception->errors()['capabilities'][0]);
        }

        $updated = app(RoleService::class)->update($this->admin, $role, ['name' => 'Author', 'capabilities' => [Capability::View->value], 'acknowledge_affected' => true]);
        $this->assertSame([Capability::View->value], $updated->capabilityList());
        $this->assertSame(2, RoleAssignment::query()->active()->where('role_definition_id', $role->id)->count());
    }

    public function test_role_validation_uses_only_registry_capabilities_and_dependencies(): void
    {
        foreach ([
            ['capabilities' => ['topic.view', 'made.up']],
            ['capabilities' => ['topic.create']],
            ['capabilities' => []],
            ['capabilities' => ['topic.view'], 'delegable' => ['topic.create']],
            ['capabilities' => ['topic.view', 'access.assign']],
        ] as $bad) {
            try {
                app(RoleService::class)->create($this->admin, null, ['name' => 'Bad', ...$bad]);
                $this->fail('Should be refused: '.json_encode($bad));
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, RoleDefinition::count());
    }

    public function test_a_move_cannot_leave_a_branch_role_outside_its_branch(): void
    {
        $inside = $this->topic('Inside', $this->other);
        $scoped = $this->role('Other only', [Capability::View, Capability::Organise], null, $this->other);
        $this->assign($this->makeUser(), $scoped, $inside);
        $mover = $this->makeUser();
        $this->assign($mover, $this->role('Organiser', [Capability::View, Capability::Organise]), $this->root);

        try {
            app(TopicService::class)->move($mover, $inside, $this->branch);
            $this->fail('Move should be blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('End or replace', $exception->errors()['destination'][0]);
        }

        $this->assertSame($this->other->id, $inside->fresh()->parent_id);
    }

    public function test_moving_needs_the_organise_power_at_both_ends(): void
    {
        $leaf = $this->topic('Leaf', $this->branch);
        $mover = $this->makeUser();
        $this->assign($mover, $this->role('Organiser', [Capability::View, Capability::Organise]), $this->branch);

        $this->expectException(AuthorizationException::class);
        app(TopicService::class)->move($mover, $leaf, $this->other);
    }
}
