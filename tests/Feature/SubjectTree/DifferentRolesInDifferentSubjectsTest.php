<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;

/**
 * Rule 6: the same person can hold different roles in different subjects,
 * and a dean can also teach a subject in their own tree.
 */
class DifferentRolesInDifferentSubjectsTest extends SubjectTreeTestCase
{
    public function test_a_student_can_tutor_one_subject_and_study_another(): void
    {
        $networksTeacher = $this->add($this->dean, $this->networks, TreeRole::Teacher)->user;
        $anna = $this->add($this->teacher, $this->database, TreeRole::Tutor)->user;

        $this->add($networksTeacher, $this->networks, TreeRole::Student, $anna);

        $this->assertSame(
            ['student', 'tutor'],
            $anna->memberships()->active()->pluck('role')->map->value->sort()->values()->all(),
        );
    }

    public function test_a_dean_can_also_teach_a_subject_in_their_own_tree(): void
    {
        $this->add($this->dean, $this->networks, TreeRole::Teacher, $this->dean);

        $this->assertSame(2, $this->dean->memberships()->active()->count());
        $this->assertTrue($this->networks->memberships()->active()->where('user_id', $this->dean->id)->exists());
    }
}
