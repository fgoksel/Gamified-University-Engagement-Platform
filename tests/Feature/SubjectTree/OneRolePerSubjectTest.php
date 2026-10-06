<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;

/**
 * Rule 5: one active role per person inside one subject's whole subtree.
 */
class OneRolePerSubjectTest extends SubjectTreeTestCase
{
    public function test_a_tutor_at_a_subtopic_cannot_also_be_a_student_of_the_subject(): void
    {
        $tutor = $this->add($this->teacher, $this->sql, TreeRole::Tutor)->user;

        $this->assertRejected(fn () => $this->add($this->teacher, $this->database, TreeRole::Student, $tutor), 'user_id');
        $this->assertRejected(fn () => $this->add($this->teacher, $this->database, TreeRole::Tutor, $tutor), 'user_id');
    }

    public function test_a_teacher_cannot_hold_a_second_role_in_the_same_subject(): void
    {
        $this->assertRejected(fn () => $this->add($this->dean, $this->database, TreeRole::Teacher, $this->teacher), 'user_id');
    }

    public function test_making_a_student_a_tutor_ends_their_student_role(): void
    {
        $studentRecord = $this->add($this->teacher, $this->database, TreeRole::Student);

        $tutorRecord = $this->add($this->teacher, $this->sql, TreeRole::Tutor, $studentRecord->user);

        $studentRecord->refresh();
        $this->assertNotNull($studentRecord->ended_at);
        $this->assertSame('Became a tutor in this subject.', $studentRecord->ended_reason);
        $this->assertTrue($tutorRecord->isActive());
    }

    public function test_an_ended_role_does_not_block_a_new_one(): void
    {
        $studentRecord = $this->add($this->teacher, $this->database, TreeRole::Student);
        $this->tree->endMembership($this->teacher, $studentRecord, 'Added by mistake');

        $again = $this->add($this->teacher, $this->database, TreeRole::Student, $studentRecord->user);

        $this->assertTrue($again->isActive());
        $this->assertSame(2, $studentRecord->user->memberships()->count());
    }
}
