<?php

namespace Tests\Feature\FacultyTree;

use App\Enums\TreeRole;

/**
 * Rule 2: people add only lower roles, only inside their own branch, and
 * nobody changes their own role.
 */
class AddBelowOnlyTest extends FacultyTreeTestCase
{
    public function test_nobody_adds_a_role_at_or_above_their_own_level(): void
    {
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher)->user;

        $this->assertForbidden(fn () => $this->add($this->teacher, $this->database, TreeRole::Teacher));
        $this->assertForbidden(fn () => $this->add($coTeacher, $this->database, TreeRole::CoTeacher));
        $this->assertForbidden(fn () => $this->add($coTeacher, $this->database, TreeRole::Teacher));
        $this->assertForbidden(fn () => $this->add($this->dean, $this->root, TreeRole::Dean));
    }

    public function test_nobody_acts_outside_their_own_branch(): void
    {
        $this->assertForbidden(fn () => $this->add($this->teacher, $this->networks, TreeRole::Student));
        $this->assertForbidden(fn () => $this->tree->addSubtopic($this->teacher, $this->networks, 'Routing'));
    }

    public function test_nobody_ends_their_own_role(): void
    {
        $teacherRecord = $this->database->memberships()->where('user_id', $this->teacher->id)->first();
        $deanRecord = $this->root->memberships()->where('user_id', $this->dean->id)->first();

        $this->assertForbidden(fn () => $this->tree->endMembership($this->teacher, $teacherRecord, 'Leaving'));
        $this->assertForbidden(fn () => $this->tree->endMembership($this->dean, $deanRecord, 'Leaving'));
    }

    public function test_nobody_ends_a_role_above_their_own(): void
    {
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher)->user;
        $teacherRecord = $this->database->memberships()->where('user_id', $this->teacher->id)->first();

        $this->assertForbidden(fn () => $this->tree->endMembership($coTeacher, $teacherRecord, 'Not needed'));
    }
}
