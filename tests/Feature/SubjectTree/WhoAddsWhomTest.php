<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Models\Course;

/**
 * Rule 3: admin -> dean and subjects, dean -> teachers, teacher -> co-teachers,
 * tutors, students and subtopics, co-teacher -> tutors, students and
 * subtopics. Tutors and students add nothing.
 */
class WhoAddsWhomTest extends SubjectTreeTestCase
{
    public function test_the_admin_appoints_the_dean_and_adds_subjects(): void
    {
        $this->assertSame(TreeUnitKind::Root, $this->root->kind);
        $this->assertTrue($this->root->memberships()->where('user_id', $this->dean->id)->where('role', TreeRole::Dean)->exists());

        $this->assertForbidden(fn () => $this->tree->addSubject($this->dean, $this->root, Course::factory()->create()));
        $this->assertForbidden(fn () => $this->tree->createTree($this->dean, 'Another tree', $this->teacherAccount()));
        $this->assertForbidden(fn () => $this->add($this->admin, $this->database, TreeRole::Teacher));
    }

    public function test_the_dean_adds_teachers_only(): void
    {
        $this->add($this->dean, $this->networks, TreeRole::Teacher);

        $this->assertForbidden(fn () => $this->add($this->dean, $this->networks, TreeRole::CoTeacher));
        $this->assertForbidden(fn () => $this->add($this->dean, $this->networks, TreeRole::Student));
    }

    public function test_a_teacher_adds_co_teachers_tutors_students_and_subtopics(): void
    {
        $this->add($this->teacher, $this->database, TreeRole::CoTeacher);
        $this->add($this->teacher, $this->database, TreeRole::Tutor);
        $this->add($this->teacher, $this->database, TreeRole::Student);
        $subtopic = $this->tree->addSubtopic($this->teacher, $this->sql, 'Joins');

        $this->assertSame(4, $this->database->memberships()->count());
        $this->assertSame(TreeUnitKind::Subtopic, $subtopic->kind);
    }

    public function test_a_co_teacher_adds_tutors_students_and_subtopics(): void
    {
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher)->user;

        $this->add($coTeacher, $this->sql, TreeRole::Tutor);
        $this->add($coTeacher, $this->database, TreeRole::Student);
        $this->tree->addSubtopic($coTeacher, $this->database, 'NoSQL');

        $this->assertForbidden(fn () => $this->add($coTeacher, $this->database, TreeRole::CoTeacher));
    }

    public function test_tutors_and_students_add_nothing(): void
    {
        $tutor = $this->add($this->teacher, $this->database, TreeRole::Tutor)->user;
        $student = $this->add($this->teacher, $this->database, TreeRole::Student)->user;

        foreach ([$tutor, $student] as $user) {
            $this->assertForbidden(fn () => $this->add($user, $this->database, TreeRole::Student));
            $this->assertForbidden(fn () => $this->add($user, $this->sql, TreeRole::Tutor));
            $this->assertForbidden(fn () => $this->tree->addSubtopic($user, $this->database, 'Mine'));
        }
    }

    public function test_each_role_needs_the_right_account_type(): void
    {
        $this->assertRejected(fn () => $this->add($this->dean, $this->networks, TreeRole::Teacher, $this->studentAccount()), 'user_id');
        $this->assertRejected(fn () => $this->add($this->teacher, $this->database, TreeRole::CoTeacher, $this->studentAccount()), 'user_id');
        $this->assertRejected(fn () => $this->add($this->teacher, $this->database, TreeRole::Tutor, $this->teacherAccount()), 'user_id');
        $this->assertRejected(fn () => $this->add($this->teacher, $this->database, TreeRole::Student, $this->teacherAccount()), 'user_id');
        $this->assertRejected(fn () => $this->tree->createTree($this->admin, 'Student tree', $this->studentAccount()), 'user_id');
    }

    public function test_each_role_sits_only_on_its_kind_of_unit(): void
    {
        $this->assertRejected(fn () => $this->add($this->dean, $this->sql, TreeRole::Teacher), 'role');
        $this->assertRejected(fn () => $this->add($this->teacher, $this->sql, TreeRole::CoTeacher), 'role');
        $this->assertRejected(fn () => $this->add($this->teacher, $this->sql, TreeRole::Student), 'role');

        $this->add($this->teacher, $this->sql, TreeRole::Tutor);
        $this->assertTrue($this->sql->memberships()->where('role', TreeRole::Tutor)->exists());
    }

    public function test_a_tree_has_one_dean(): void
    {
        $this->assertRejected(fn () => $this->add($this->admin, $this->root, TreeRole::Dean), 'user_id');
    }
}
