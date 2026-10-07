<?php

namespace Tests\Feature\FacultyTree;

use App\Enums\TreeRole;
use App\Models\Faculty;
use App\Models\UnitMembership;

/**
 * The admin replaces a dean in one step, so a tree is never left without one.
 */
class ReplaceDeanTest extends FacultyTreeTestCase
{
    public function test_the_old_dean_is_ended_with_the_reason_and_the_new_dean_takes_over(): void
    {
        $newDean = $this->teacherAccount();

        $this->tree->replaceDean($this->admin, $this->root, $newDean, 'End of mandate');

        $old = $this->root->memberships()->where('user_id', $this->dean->id)->sole();
        $this->assertFalse($old->isActive());
        $this->assertSame('End of mandate', $old->ended_reason);
        $this->assertSame($newDean->id, $this->activeDean()->user_id);
    }

    public function test_the_new_dean_gets_the_old_deans_rights_and_the_old_dean_loses_them(): void
    {
        $newDean = $this->teacherAccount();

        $this->tree->replaceDean($this->admin, $this->root, $newDean, 'End of mandate');

        $this->assertCan($newDean, 'add', [UnitMembership::class, $this->networks, TreeRole::Teacher]);
        $this->assertCannot($this->dean, 'add', [UnitMembership::class, $this->networks, TreeRole::Teacher]);
        $this->assertCannot($this->dean, 'view', $this->networks);
    }

    public function test_nothing_changes_when_the_new_dean_cannot_be_appointed(): void
    {
        $deanElsewhere = $this->tree->createTree($this->admin, Faculty::factory()->create(), $this->teacherAccount())
            ->memberships()->sole()->user;

        $this->assertRejected(fn () => $this->tree->replaceDean($this->admin, $this->root, $deanElsewhere, 'Swap'), 'user_id');
        $this->assertRejected(fn () => $this->tree->replaceDean($this->admin, $this->root, $this->studentAccount(), 'Swap'), 'user_id');
        $this->assertRejected(fn () => $this->tree->replaceDean($this->admin, $this->root, $this->dean, 'Same'), 'user_id');
        $this->assertRejected(fn () => $this->tree->replaceDean($this->admin, $this->root, $this->teacherAccount(), '  '), 'ended_reason');

        $this->assertSame($this->dean->id, $this->activeDean()->user_id);
        $this->assertSame(1, $this->root->memberships()->where('role', TreeRole::Dean)->count());
    }

    public function test_only_the_admin_replaces_a_dean(): void
    {
        $this->assertForbidden(fn () => $this->tree->replaceDean($this->dean, $this->root, $this->teacherAccount(), 'Leaving'));
        $this->assertForbidden(fn () => $this->tree->replaceDean($this->teacher, $this->root, $this->teacherAccount(), 'Coup'));
    }

    public function test_a_teacher_of_the_tree_can_become_its_dean_and_keeps_teaching(): void
    {
        $this->tree->replaceDean($this->admin, $this->root, $this->teacher, 'Promoted');

        $this->assertSame($this->teacher->id, $this->activeDean()->user_id);
        $this->assertTrue($this->database->memberships()->active()->where('user_id', $this->teacher->id)->exists());
    }

    private function activeDean(): UnitMembership
    {
        return $this->root->memberships()->active()->where('role', TreeRole::Dean)->sole();
    }
}
