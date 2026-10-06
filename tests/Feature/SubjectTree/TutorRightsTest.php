<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;
use App\Enums\TutorPermission;

/**
 * Rule 7: a teacher or co-teacher sets each tutor's rights, and they apply
 * at the tutor's unit and below.
 */
class TutorRightsTest extends SubjectTreeTestCase
{
    public function test_a_tutors_rights_apply_at_their_unit_and_below(): void
    {
        $tutorRecord = $this->add($this->teacher, $this->database, TreeRole::Tutor);
        $tutor = $tutorRecord->user;

        $this->assertCannot($tutor, 'createEvent', $this->database);

        $this->tree->setTutorPermissions($this->teacher, $tutorRecord, [TutorPermission::CreateEvents]);

        $this->assertCan($tutor, 'createEvent', $this->database);
        $this->assertCan($tutor, 'createEvent', $this->sql);
        $this->assertCannot($tutor, 'createEvent', $this->networks);
    }

    public function test_a_tutor_at_a_subtopic_has_no_rights_above_it(): void
    {
        $tutorRecord = $this->tree->addMember($this->teacher, $this->sql, $this->studentAccount(), TreeRole::Tutor, ['create_events']);

        $this->assertCan($tutorRecord->user, 'createEvent', $this->sql);
        $this->assertCannot($tutorRecord->user, 'createEvent', $this->database);
    }

    public function test_only_staff_above_the_tutor_set_their_rights(): void
    {
        $tutorRecord = $this->add($this->teacher, $this->sql, TreeRole::Tutor);
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher)->user;
        $networksTeacher = $this->add($this->dean, $this->networks, TreeRole::Teacher)->user;

        $this->tree->setTutorPermissions($coTeacher, $tutorRecord, ['select_applicants', 'credit_points']);
        $this->assertSame(['select_applicants', 'credit_points'], $tutorRecord->fresh()->permissions);

        $this->assertForbidden(fn () => $this->tree->setTutorPermissions($tutorRecord->user, $tutorRecord, ['create_events']));
        $this->assertForbidden(fn () => $this->tree->setTutorPermissions($networksTeacher, $tutorRecord, ['create_events']));
    }

    public function test_unknown_rights_are_rejected(): void
    {
        $tutorRecord = $this->add($this->teacher, $this->database, TreeRole::Tutor);

        $this->assertRejected(fn () => $this->tree->setTutorPermissions($this->teacher, $tutorRecord, ['delete_everything']), 'permissions');
    }

    public function test_teachers_and_co_teachers_create_events_but_students_do_not(): void
    {
        $coTeacher = $this->add($this->teacher, $this->database, TreeRole::CoTeacher)->user;
        $student = $this->add($this->teacher, $this->database, TreeRole::Student)->user;

        $this->assertCan($this->teacher, 'createEvent', $this->sql);
        $this->assertCan($coTeacher, 'createEvent', $this->database);
        $this->assertCannot($student, 'createEvent', $this->database);
        $this->assertCannot($this->dean, 'createEvent', $this->root);
    }
}
