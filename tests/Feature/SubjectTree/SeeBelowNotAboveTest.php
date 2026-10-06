<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;

/**
 * Rule 1: a person sees their own unit and everything below it, never above.
 */
class SeeBelowNotAboveTest extends SubjectTreeTestCase
{
    public function test_people_see_their_own_unit_and_everything_below_it(): void
    {
        $this->assertCan($this->teacher, 'view', $this->database);
        $this->assertCan($this->teacher, 'view', $this->sql);
        $this->assertCannot($this->teacher, 'view', $this->root);
        $this->assertCannot($this->teacher, 'view', $this->networks);

        $student = $this->add($this->teacher, $this->database, TreeRole::Student)->user;
        $this->assertCan($student, 'view', $this->sql);
        $this->assertCannot($student, 'view', $this->networks);
    }

    public function test_the_dean_and_the_admin_see_the_whole_tree(): void
    {
        foreach ([$this->root, $this->database, $this->sql, $this->networks] as $unit) {
            $this->assertCan($this->dean, 'view', $unit);
            $this->assertCan($this->admin, 'view', $unit);
        }
    }

    public function test_on_their_own_unit_people_see_only_lower_levels(): void
    {
        $otherTeacher = $this->add($this->dean, $this->database, TreeRole::Teacher);
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher);
        $tutor = $this->add($this->teacher, $this->database, TreeRole::Tutor);
        $student = $this->add($this->teacher, $this->database, TreeRole::Student);

        $this->assertCan($this->teacher, 'view', $coTeacher);
        $this->assertCan($this->teacher, 'view', $tutor);
        $this->assertCan($this->teacher, 'view', $student);
        $this->assertCannot($this->teacher, 'view', $otherTeacher);

        $this->assertCan($coTeacher->user, 'view', $tutor);
        $this->assertCannot($coTeacher->user, 'view', $otherTeacher);

        $this->assertCan($this->dean, 'view', $otherTeacher);
    }

    public function test_staff_see_everyone_on_units_below_theirs(): void
    {
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher);
        $sqlTutor = $this->add($this->teacher, $this->sql, TreeRole::Tutor);

        $this->assertCan($coTeacher->user, 'view', $sqlTutor);
        $this->assertCannot($sqlTutor->user, 'view', $coTeacher);
    }

    public function test_a_student_sees_only_their_own_record(): void
    {
        $anna = $this->add($this->teacher, $this->database, TreeRole::Student);
        $bela = $this->add($this->teacher, $this->database, TreeRole::Student);
        $teacherRecord = $this->database->memberships()->where('user_id', $this->teacher->id)->first();

        $this->assertCan($anna->user, 'view', $anna);
        $this->assertCannot($anna->user, 'view', $bela);
        $this->assertCannot($anna->user, 'view', $teacherRecord);
    }
}
