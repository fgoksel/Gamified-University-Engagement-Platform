<?php

namespace Tests\Feature\FacultyTree;

use App\Enums\TreeRole;
use App\Models\Semester;

/**
 * Rule 9: students added by hand are marked manual so the admin can review them.
 */
class ManualStudentsTest extends FacultyTreeTestCase
{
    public function test_a_student_added_by_hand_is_marked_manual(): void
    {
        $semester = Semester::create(['name' => '2026/2027 Autumn', 'starts_at' => '2026-09-01', 'ends_at' => '2027-01-31', 'status' => 'active']);

        $record = $this->add($this->teacher, $this->database, TreeRole::Student);

        $this->assertTrue($record->manual);
        $this->assertSame($this->teacher->id, $record->added_by_id);
        $this->assertSame($semester->id, $record->semester_id);
    }

    public function test_staff_and_tutors_are_not_marked_manual(): void
    {
        $tutor = $this->add($this->teacher, $this->database, TreeRole::Tutor);
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher);

        $this->assertFalse($tutor->manual);
        $this->assertFalse($coTeacher->manual);
        $this->assertNull($tutor->semester_id);
    }
}
