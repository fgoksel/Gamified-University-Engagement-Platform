<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\Topic;
use App\Services\TopicAccess;
use Filament\Facades\Filament;

class TopicAccessTest extends TopicTestCase
{
    private Topic $hungary;

    private Topic $pecs;

    private Topic $database;

    private Topic $sql;

    private Topic $budapest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hungary = $this->topic('Hungary');
        $this->pecs = $this->topic('Pécs', $this->hungary);
        $this->database = $this->topic('Database', $this->pecs);
        $this->sql = $this->topic('SQL', $this->database);
        $this->budapest = $this->topic('Budapest', $this->hungary);
    }

    private function access(): TopicAccess
    {
        return app(TopicAccess::class);
    }

    public function test_access_flows_down_but_never_up_or_sideways(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        $this->assertTrue($this->access()->canView($user, $this->database));
        $this->assertTrue($this->access()->canView($user, $this->sql));
        $this->assertFalse($this->access()->canView($user, $this->pecs));
        $this->assertFalse($this->access()->canView($user, $this->hungary));
        $this->assertFalse($this->access()->canView($user, $this->budapest));
    }

    public function test_direct_and_inherited_assignments_combine_and_never_subtract(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->role('Reader and author', [Capability::View, Capability::Create]), $this->pecs);
        $this->assign($user, $this->viewer(), $this->sql);

        $this->assertEqualsCanonicalizing(
            [Capability::View->value, Capability::Create->value],
            $this->access()->capabilities($user, $this->sql),
        );
    }

    public function test_one_person_can_have_different_responsibilities_in_different_branches(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->role('Editor', [Capability::View, Capability::Edit]), $this->pecs);
        $this->assign($user, $this->viewer(), $this->budapest);

        $this->assertTrue($this->access()->can($user, Capability::Edit, $this->database));
        $this->assertTrue($this->access()->canView($user, $this->budapest));
        $this->assertFalse($this->access()->can($user, Capability::Edit, $this->budapest));
    }

    public function test_labels_grant_nothing_two_roles_with_the_same_capabilities_behave_the_same(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->assign($a, $this->role('Teacher', [Capability::View, Capability::Create]), $this->database);
        $this->assign($b, $this->role('Assistant Admin', [Capability::View, Capability::Create]), $this->database);

        $this->assertSame($this->access()->capabilities($a, $this->sql), $this->access()->capabilities($b, $this->sql));
    }

    public function test_a_topic_role_called_admin_with_only_view_is_view_only_and_not_a_system_admin(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->role('Admin', [Capability::View]), $this->pecs);

        $this->assertFalse($this->access()->isSystemAdmin($user));
        $this->assertFalse($user->hasRole(UserRole::Admin));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame([Capability::View->value], $this->access()->capabilities($user, $this->database));
        $this->actingAs($user)->get('/topics/roles')->assertRedirect('/');
    }

    public function test_an_inactive_account_has_no_access_but_keeps_its_assignments(): void
    {
        $user = $this->makeUser();
        $assignment = $this->assign($user, $this->viewer(), $this->pecs);

        $user->update(['status' => 'inactive']);

        $this->assertFalse($this->access()->canView($user->fresh(), $this->pecs));
        $this->assertNull($assignment->fresh()->ended_at);
    }

    public function test_an_ended_assignment_gives_nothing_and_independent_access_is_kept(): void
    {
        $user = $this->makeUser();
        $direct = $this->assign($user, $this->viewer(), $this->sql);
        $inherited = $this->assign($user, $this->role('Author', [Capability::View, Capability::Create]), $this->pecs);

        $inherited->update(['ended_at' => now(), 'ended_reason' => 'test']);

        $this->assertSame([Capability::View->value], $this->access()->capabilities($user, $this->sql));
        $this->assertTrue($this->access()->canView($user, $this->sql));
        $this->assertFalse($this->access()->canView($user, $this->database));
        $this->assertNull($direct->fresh()->ended_at);
    }

    public function test_the_system_admin_sees_every_root_and_has_every_capability(): void
    {
        $entries = $this->access()->entryTopics($this->admin);
        $this->assertSame([$this->hungary->id], $entries->pluck('id')->all());
        $this->assertEqualsCanonicalizing(Capability::values(), $this->access()->capabilities($this->admin, $this->sql));
    }

    public function test_a_nested_only_user_enters_at_the_nested_topic_without_ancestry(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->database);

        $this->assertSame([$this->database->id], $this->access()->entryTopics($user)->pluck('id')->all());
        $this->assertSame(
            ['Database', 'SQL'],
            $this->access()->breadcrumbs($user, $this->sql)->pluck('title')->all(),
        );
    }

    public function test_a_descendant_covered_by_an_accessible_ancestor_is_not_a_second_entry(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->pecs);
        $this->assign($user, $this->role('Author', [Capability::View, Capability::Create]), $this->sql);

        $this->assertSame([$this->pecs->id], $this->access()->entryTopics($user)->pluck('id')->all());
    }

    public function test_visible_topics_cover_the_branch_only(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->viewer(), $this->pecs);

        $this->assertEqualsCanonicalizing(
            [$this->pecs->id, $this->database->id, $this->sql->id],
            $this->access()->visibleTopics($user)->pluck('id')->all(),
        );
    }

    public function test_a_user_with_no_assignment_sees_nothing(): void
    {
        $user = $this->makeUser();

        $this->assertSame(0, $this->access()->visibleTopics($user)->count());
        $this->assertTrue($this->access()->entryTopics($user)->isEmpty());
    }

    public function test_delegation_ceiling_is_the_passable_part_of_held_powers(): void
    {
        $user = $this->makeUser();
        $this->assign($user, $this->role('Lead', [Capability::View, Capability::Create, Capability::Edit], [Capability::View, Capability::Create]), $this->pecs);

        $this->assertEqualsCanonicalizing([Capability::View->value, Capability::Create->value], $this->access()->delegable($user, $this->sql));
    }
}
