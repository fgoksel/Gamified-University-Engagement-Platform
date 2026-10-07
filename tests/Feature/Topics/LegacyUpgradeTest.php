<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Models\Course;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\TopicAccess;
use App\Support\LegacyTreeUpgrade;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Upgrade fixture: a small faculty tree in the OLD tables is carried over to
 * topics and role assignments. IDs, hierarchy, course links, ended history,
 * semester and manual metadata survive; access outcomes match the documented
 * mapping (docs/topic-tree.md).
 */
class LegacyUpgradeTest extends TopicTestCase
{
    private User $dean;

    private User $teacher;

    private User $coTeacher;

    private User $tutor;

    private User $student;

    private User $formerStudent;

    private Course $course;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dean = $this->makeUser(name: 'Dean');
        $this->teacher = $this->makeUser(name: 'Teacher');
        $this->coTeacher = $this->makeUser(name: 'Co-teacher');
        $this->tutor = $this->makeUser(UserRole::Student, 'Tutor');
        $this->student = $this->makeUser(UserRole::Student, 'Student');
        $this->formerStudent = $this->makeUser(UserRole::Student, 'Former student');

        $this->semester = Semester::create(['name' => '2026/27/1', 'starts_at' => '2026-09-01', 'ends_at' => '2027-01-31', 'status' => 'active']);
        $this->course = Course::factory()->create(['code' => 'BMEINFO1', 'name' => 'Database']);

        $created = now()->subMonths(3)->startOfSecond();

        // Tree: Faculty (1) > Database course (2) > SQL (3) > Homework topic (4)
        $units = [
            [1, null, 'root', 'Faculty of Engineering', null, '/1/'],
            [2, 1, 'course', 'Database', $this->course->id, '/1/2/'],
            [3, 2, 'subtopic', 'SQL', null, '/1/2/3/'],
            [4, 3, 'subtopic', 'Joins', null, '/1/2/3/4/'],
        ];
        foreach ($units as [$id, $parent, $kind, $title, $courseId, $path]) {
            DB::table('tree_units')->insert([
                'id' => $id, 'parent_id' => $parent, 'kind' => $kind, 'title' => $title, 'course_id' => $courseId,
                'path' => $path, 'created_by_id' => $this->admin->id, 'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        $memberships = [
            [1, 1, $this->dean, 'dean', null, null, false, null, null],
            [2, 2, $this->teacher, 'teacher', null, null, false, null, null],
            [3, 2, $this->coTeacher, 'co_teacher', null, null, false, null, null],
            [4, 3, $this->tutor, 'tutor', null, null, false, json_encode(['create_events', 'credit_points']), null],
            [5, 2, $this->student, 'student', $this->semester->id, null, true, null, null],
            [6, 2, $this->formerStudent, 'student', $this->semester->id, $created->copy()->addMonth(), false, null, 'Semester closed'],
        ];
        foreach ($memberships as [$id, $unit, $user, $role, $semester, $endedAt, $manual, $permissions, $reason]) {
            DB::table('unit_memberships')->insert([
                'id' => $id, 'unit_id' => $unit, 'user_id' => $user->id, 'role' => $role, 'added_by_id' => $this->admin->id,
                'permissions' => $permissions, 'semester_id' => $semester, 'manual' => $manual,
                'started_at' => $created, 'ended_at' => $endedAt, 'ended_reason' => $reason, 'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        (new LegacyTreeUpgrade)->run();
    }

    private function emptyTopicTables(): void
    {
        DB::table('role_assignments')->delete();
        DB::table('role_definitions')->delete();
        DB::table('topic_closure')->delete();
        DB::table('topics')->update(['parent_id' => null]);
        DB::table('topics')->delete();
    }

    private function access(): TopicAccess
    {
        return app(TopicAccess::class);
    }

    public function test_every_unit_becomes_a_topic_with_the_same_id_hierarchy_and_course_link(): void
    {
        $this->assertSame(4, Topic::count());
        $this->assertSame([null, 1, 2, 3], Topic::orderBy('id')->pluck('parent_id')->all());
        $this->assertSame(['Faculty of Engineering', 'Database', 'SQL', 'Joins'], Topic::orderBy('id')->pluck('title')->all());
        $this->assertSame([0, 1, 2, 3], Topic::orderBy('id')->pluck('depth')->all());
        $this->assertSame($this->course->id, Topic::find(2)->course_id);
        $this->assertSame([4, 3, 2, 1], Topic::find(4)->lineageIds());
        $this->assertSame(10, DB::table('topic_closure')->count());
        $this->assertSame(1, Topic::find(1)->legacy_tree_unit_id);
    }

    public function test_original_timestamps_and_creator_are_kept(): void
    {
        $unit = DB::table('tree_units')->find(3);
        $topic = DB::table('topics')->find(3);

        $this->assertSame($unit->created_at, $topic->created_at);
        $this->assertSame($unit->created_by_id, $topic->created_by_id);
    }

    public function test_every_membership_becomes_an_assignment_including_ended_history_and_metadata(): void
    {
        $this->assertSame(6, RoleAssignment::count());
        $this->assertSame(5, RoleAssignment::query()->active()->count());

        $ended = RoleAssignment::find(6);
        $this->assertSame('Semester closed', $ended->ended_reason);
        $this->assertNotNull($ended->ended_at);

        $manual = RoleAssignment::find(5);
        $this->assertTrue($manual->manual);
        $this->assertSame($this->semester->id, $manual->semester_id);
        $this->assertSame($this->admin->id, $manual->granted_by_id);

        $this->assertSame(['create_events', 'credit_points'], RoleAssignment::find(4)->legacy_permissions);
    }

    public function test_legacy_labels_become_ordinary_definitions_only_for_roles_that_exist(): void
    {
        $this->assertEqualsCanonicalizing(
            ['dean', 'teacher', 'co_teacher', 'tutor', 'student'],
            RoleDefinition::whereNotNull('legacy_key')->pluck('legacy_key')->all(),
        );
        $this->assertSame(0, RoleDefinition::whereNotNull('topic_id')->count());
    }

    public function test_the_legacy_tables_are_untouched_and_a_second_run_changes_nothing(): void
    {
        $summary = (new LegacyTreeUpgrade)->run();

        $this->assertSame(['topics' => 0, 'closure_rows' => 0, 'assignments' => 0, 'role_definitions' => 0], $summary);
        $this->assertSame(4, DB::table('tree_units')->count());
        $this->assertSame(6, DB::table('unit_memberships')->count());
        $this->assertSame(4, Topic::count());
        $this->assertSame(6, RoleAssignment::count());
    }

    public function test_migrated_people_keep_the_reach_they_had(): void
    {
        // Students and tutors saw their node and everything below; nothing above.
        $this->assertTrue($this->access()->canView($this->student, Topic::find(3)));
        $this->assertFalse($this->access()->canView($this->student, Topic::find(1)));
        $this->assertTrue($this->access()->canView($this->tutor, Topic::find(4)));
        $this->assertFalse($this->access()->canView($this->tutor, Topic::find(2)));
        // The dean saw the whole tree.
        $this->assertTrue($this->access()->canView($this->dean, Topic::find(4)));
        // Ended roles stay history and give nothing.
        $this->assertFalse($this->access()->canView($this->formerStudent, Topic::find(2)));
    }

    public function test_mapped_powers_follow_the_documented_mapping_and_add_no_event_or_role_powers(): void
    {
        $author = [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign, Capability::AccessEnd];

        foreach ([$this->dean, $this->teacher, $this->coTeacher] as $person) {
            $this->assertEqualsCanonicalizing(
                array_map(fn (Capability $c) => $c->value, $author),
                $this->access()->capabilities($person, Topic::find(3)),
            );
        }
        $this->assertSame([Capability::View->value], $this->access()->capabilities($this->student, Topic::find(3)));
        $this->assertSame([Capability::View->value], $this->access()->capabilities($this->tutor, Topic::find(4)));
        // Nobody gained edit, organise or role-definition powers by migration.
        foreach ([$this->dean, $this->teacher, $this->coTeacher, $this->tutor, $this->student] as $person) {
            $this->assertNotContains(Capability::DefineRoles->value, $this->access()->capabilities($person, Topic::find(4)));
            $this->assertNotContains(Capability::Organise->value, $this->access()->capabilities($person, Topic::find(4)));
            $this->assertNotContains(Capability::Edit->value, $this->access()->capabilities($person, Topic::find(4)));
        }
    }

    public function test_a_co_teacher_can_still_give_student_and_tutor_roles_but_not_their_own(): void
    {
        $service = app(AssignmentService::class);
        $newcomer = $this->makeUser(UserRole::Student);
        $student = RoleDefinition::where('legacy_key', 'student')->first();
        $coTeacher = RoleDefinition::where('legacy_key', 'co_teacher')->first();

        $service->grant($this->coTeacher, Topic::find(2), $student, $newcomer);
        $this->assertDatabaseHas('role_assignments', ['user_id' => $newcomer->id, 'role_definition_id' => $student->id]);

        $this->expectException(AuthorizationException::class);
        $service->grant($this->coTeacher, Topic::find(2), $coTeacher, $this->makeUser());
    }

    public function test_a_teacher_can_give_co_teacher_roles_as_before(): void
    {
        $coTeacher = RoleDefinition::where('legacy_key', 'co_teacher')->first();
        $person = $this->makeUser();

        $assignment = app(AssignmentService::class)->grant($this->teacher, Topic::find(2), $coTeacher, $person);

        $this->assertSame(2, $assignment->topic_id);
    }

    public function test_a_dean_named_role_created_after_the_upgrade_has_no_special_meaning(): void
    {
        $fresh = $this->role('Dean', [Capability::View]);
        $person = $this->makeUser();
        $this->assign($person, $fresh, Topic::find(1));

        $this->assertSame([Capability::View->value], $this->access()->capabilities($person, Topic::find(1)));
        $this->assertNull($fresh->legacy_key);
    }

    public function test_the_enrolment_import_places_students_only_on_the_linked_topic(): void
    {
        $unlinked = Course::factory()->create(['code' => 'OTHER1', 'name' => 'Other']);
        $newStudent = $this->makeUser(UserRole::Student, 'Newbie');
        $newStudent->update(['neptun_code' => 'NEWB01']);

        $csv = "Neptun Code,Course Code,Course Name\nNEWB01,BMEINFO1,Database\nNEWB01,OTHER1,Other\nNEWB01,UNKNOWN9,Nothing\nNEWB01,BMEINFO1,Database\n";
        Storage::fake('local');
        Storage::disk('local')->put('imports/enrol.csv', $csv);

        $job = new ImportCourseEnrollmentsJob('imports/enrol.csv', $this->admin->id, $this->semester->id);
        $job->handle();

        $placed = RoleAssignment::query()->active()->where('user_id', $newStudent->id)->get();
        $this->assertCount(1, $placed);
        $this->assertSame(2, $placed->first()->topic_id);
        $this->assertSame($this->semester->id, $placed->first()->semester_id);
        $this->assertSame(0, RoleAssignment::query()->where('topic_id', '!=', 2)->where('user_id', $newStudent->id)->count());
        $this->assertNotNull($unlinked);
    }

    public function test_a_fresh_database_gets_no_definitions_or_topics_from_the_upgrade(): void
    {
        $this->emptyTopicTables();
        DB::table('unit_memberships')->delete();
        DB::table('tree_units')->update(['parent_id' => null]);
        DB::table('tree_units')->delete();

        $summary = (new LegacyTreeUpgrade)->run();

        $this->assertSame(['topics' => 0, 'closure_rows' => 0, 'assignments' => 0, 'role_definitions' => 0], $summary);
        $this->assertSame(0, RoleDefinition::count());
    }

    public function test_a_cycle_in_the_legacy_data_stops_the_upgrade_without_partial_copy(): void
    {
        $this->emptyTopicTables();
        DB::table('tree_units')->where('id', 1)->update(['parent_id' => 4]);

        try {
            (new LegacyTreeUpgrade)->run();
            $this->fail('A legacy cycle must stop the upgrade.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cycle', $exception->getMessage());
        }

        $this->assertSame(0, Topic::count());
    }
}
