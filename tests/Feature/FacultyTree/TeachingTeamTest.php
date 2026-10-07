<?php

namespace Tests\Feature\FacultyTree;

use App\Enums\TreeRole;

/**
 * Rule 4: several teachers can teach one course, and one teacher can teach
 * several courses.
 */
class TeachingTeamTest extends FacultyTreeTestCase
{
    public function test_several_teachers_can_be_on_one_course(): void
    {
        $this->add($this->dean, $this->database, TreeRole::Teacher);

        $this->assertSame(2, $this->database->memberships()->active()->where('role', TreeRole::Teacher)->count());
    }

    public function test_one_teacher_can_be_on_several_courses(): void
    {
        $this->add($this->dean, $this->networks, TreeRole::Teacher, $this->teacher);

        $this->assertSame(2, $this->teacher->memberships()->active()->where('role', TreeRole::Teacher)->count());
        $this->assertCan($this->teacher, 'view', $this->networks);
    }
}
