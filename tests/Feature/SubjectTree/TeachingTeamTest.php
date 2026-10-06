<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;

/**
 * Rule 4: several teachers can teach one subject, and one teacher can teach
 * several subjects.
 */
class TeachingTeamTest extends SubjectTreeTestCase
{
    public function test_several_teachers_can_be_on_one_subject(): void
    {
        $this->add($this->dean, $this->database, TreeRole::Teacher);

        $this->assertSame(2, $this->database->memberships()->active()->where('role', TreeRole::Teacher)->count());
    }

    public function test_one_teacher_can_be_on_several_subjects(): void
    {
        $this->add($this->dean, $this->networks, TreeRole::Teacher, $this->teacher);

        $this->assertSame(2, $this->teacher->memberships()->active()->where('role', TreeRole::Teacher)->count());
        $this->assertCan($this->teacher, 'view', $this->networks);
    }
}
