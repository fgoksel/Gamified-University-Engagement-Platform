<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\FacultyCourses;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task #29: Admin: Link courses to topics and run the Neptun enrolment import.
 * Verifies explicit multi-node selection per 13 Oct 2026 agreement with professor.
 */
class CourseEnrollmentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        Notification::fake();

        $this->admin = User::factory()->create()->assignRole(UserRole::Admin);
        $this->semester = Semester::create([
            'name' => '2026/2027 Fall',
            'starts_at' => '2026-09-07',
            'ends_at' => '2027-01-31',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_link_and_unlink_course_to_topic(): void
    {
        $faculty = Faculty::factory()->create(['name' => 'Engineering']);
        $course = Course::factory()->create([
            'faculty_id' => $faculty->id,
            'code' => 'CS101',
            'name' => 'Computer Science 1',
        ]);

        $topic = app(TopicService::class)->create($this->admin, null, [
            'title' => 'Computer Science Topic',
            'code' => 'CS-TOPIC',
        ]);

        $this->assertNull($course->fresh()->topic);

        // Link course to topic
        $topic->update(['course_id' => $course->id]);
        $this->assertSame($topic->id, $course->fresh()->topic->id);
        $this->assertSame($topic->id, $course->fresh()->legacyTopic->id);

        // Unlink course
        $topic->update(['course_id' => null]);
        $this->assertNull($course->fresh()->topic);
    }

    public function test_enrolment_import_places_students_at_course_topic(): void
    {
        $topic = $this->createLinkedCourse('DB101', 'Database Systems');
        $student = $this->createStudent('ABC123');

        $csv = "Neptun Code,Course Code,Course Name\nABC123,DB101,Database Systems\n";
        $path = $this->storeCsv($csv);

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $this->assertSame(1, RoleAssignment::count());
        $assignment = RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $topic->id])->first();
        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->isActive());
        $this->assertSame($this->semester->id, $assignment->semester_id);
    }

    public function test_enrolment_import_places_students_at_ticked_same_branch_ancestors_and_descendants(): void
    {
        // Topic Hierarchy:
        // Root (University) -> Faculty -> Course (Database) -> Homework 1
        //                                                   -> Lab Group A
        $topicService = app(TopicService::class);
        $university = $topicService->create($this->admin, null, ['title' => 'University of Pécs']);
        $faculty = $topicService->create($this->admin, $university, ['title' => 'Faculty of Engineering']);
        $courseTopic = $topicService->create($this->admin, $faculty, ['title' => 'Database Systems']);
        $homework = $topicService->create($this->admin, $courseTopic, ['title' => 'Homework 1']);
        $labGroupA = $topicService->create($this->admin, $courseTopic, ['title' => 'Lab Group A']);

        // Link course
        $course = Course::factory()->create(['code' => 'DB101', 'name' => 'Database Systems']);
        $courseTopic->update(['course_id' => $course->id]);

        $student = $this->createStudent('ABC123');

        // Importer ticks: University (ancestor), Faculty (ancestor), Homework 1 (descendant).
        // Importer leaves Lab Group A UNTICKED!
        $tickedNodeIds = [$university->id, $faculty->id, $homework->id];

        $csv = "Neptun Code,Course Code,Course Name\nABC123,DB101,Database Systems\n";
        $path = $this->storeCsv($csv);

        (new ImportCourseEnrollmentsJob(
            $path,
            $this->admin->id,
            semesterId: $this->semester->id,
            additionalTopicIds: $tickedNodeIds
        ))->handle();

        // Student should belong to:
        // 1. Database Systems (primary import node)
        // 2. University of Pécs (ticked ancestor)
        // 3. Faculty of Engineering (ticked ancestor)
        // 4. Homework 1 (ticked descendant)
        $this->assertTrue(RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $courseTopic->id])->exists());
        $this->assertTrue(RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $university->id])->exists());
        $this->assertTrue(RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $faculty->id])->exists());
        $this->assertTrue(RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $homework->id])->exists());

        // And strictly NOT at Lab Group A (clean lab group isolation)!
        $this->assertFalse(RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $labGroupA->id])->exists());

        $this->assertSame(4, RoleAssignment::where('user_id', $student->id)->count());
    }

    public function test_enrolment_import_strictly_ignores_side_branch_nodes(): void
    {
        $topicService = app(TopicService::class);
        $root = $topicService->create($this->admin, null, ['title' => 'University']);

        // Branch 1: Faculty 1 -> Course 1
        $faculty1 = $topicService->create($this->admin, $root, ['title' => 'Engineering']);
        $course1Topic = $topicService->create($this->admin, $faculty1, ['title' => 'Database Systems']);
        $course1 = Course::factory()->create(['code' => 'DB101', 'name' => 'Database Systems']);
        $course1Topic->update(['course_id' => $course1->id]);

        // Branch 2 (Side Branch): Faculty 2 -> Course 2
        $faculty2 = $topicService->create($this->admin, $root, ['title' => 'Humanities']);
        $course2Topic = $topicService->create($this->admin, $faculty2, ['title' => 'Philosophy 101']);

        $student = $this->createStudent('ABC123');

        // Importer attempts to pass side branch topic $course2Topic->id in additionalTopicIds
        $csv = "Neptun Code,Course Code,Course Name\nABC123,DB101,Database Systems\n";
        $path = $this->storeCsv($csv);

        (new ImportCourseEnrollmentsJob(
            $path,
            $this->admin->id,
            semesterId: $this->semester->id,
            additionalTopicIds: [$course2Topic->id] // Side branch node
        ))->handle();

        // Student placed at Course 1 Topic
        $this->assertTrue(RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $course1Topic->id])->exists());

        // Student is strictly NOT placed at Side Branch Topic
        $this->assertFalse(RoleAssignment::where(['user_id' => $student->id, 'topic_id' => $course2Topic->id])->exists());
        $this->assertSame(1, RoleAssignment::where('user_id', $student->id)->count());
    }

    public function test_branch_nodes_api_returns_ancestors_and_descendants(): void
    {
        $topicService = app(TopicService::class);
        $root = $topicService->create($this->admin, null, ['title' => 'University']);
        $faculty = $topicService->create($this->admin, $root, ['title' => 'Faculty of Sciences']);
        $courseTopic = $topicService->create($this->admin, $faculty, ['title' => 'Chemistry 101']);
        $labGroup = $topicService->create($this->admin, $courseTopic, ['title' => 'Lab Group B']);

        // Sibling / side branch
        $otherFaculty = $topicService->create($this->admin, $root, ['title' => 'Faculty of Arts']);

        $response = $this->actingAs($this->admin)->getJson(route('admin.topics.branch-nodes', $courseTopic));

        $response->assertOk();
        $data = $response->json();

        $this->assertSame($courseTopic->id, $data['topic']['id']);

        // Nodes above should include University and Faculty of Sciences
        $aboveTitles = collect($data['nodes_above'])->pluck('title')->all();
        $this->assertContains('University', $aboveTitles);
        $this->assertContains('Faculty of Sciences', $aboveTitles);
        $this->assertNotContains('Faculty of Arts', $aboveTitles);

        // Nodes below should include Lab Group B
        $belowTitles = collect($data['nodes_below'])->pluck('title')->all();
        $this->assertContains('Lab Group B', $belowTitles);
    }

    public function test_admin_can_dispatch_enrolments_via_http_post(): void
    {
        Queue::fake();

        $topic = $this->createLinkedCourse('DB101', 'Database Systems');

        $file = UploadedFile::fake()->createWithContent(
            'enrolments.csv',
            "Neptun Code,Course Code,Course Name\nABC123,DB101,Database Systems\n"
        );

        $response = $this->actingAs($this->admin)->postJson(route('admin.import.enrolments'), [
            'file' => $file,
            'semester_id' => $this->semester->id,
            'target_topic_id' => $topic->id,
            'additional_topic_ids' => [],
        ]);

        $response->assertStatus(202);
        Queue::assertPushed(ImportCourseEnrollmentsJob::class);
    }

    public function test_per_course_import_skips_rows_for_other_courses(): void
    {
        $dbTopic = $this->createLinkedCourse('DB101', 'Database Systems');
        $progTopic = $this->createLinkedCourse('PROG101', 'Programming');
        $student1 = $this->createStudent('ABC123');
        $student2 = $this->createStudent('XYZ789');

        // CSV contains rows for both courses
        $csv = "Neptun Code,Course Code,Course Name\n"
            ."ABC123,DB101,Database Systems\n"
            ."XYZ789,PROG101,Programming\n";
        $path = $this->storeCsv($csv);

        // Targeted import for Database Systems only
        (new ImportCourseEnrollmentsJob(
            $path,
            $this->admin->id,
            targetTopicId: $dbTopic->id
        ))->handle();

        // Student 1 (DB101) placed in Database topic
        $this->assertTrue(RoleAssignment::where(['user_id' => $student1->id, 'topic_id' => $dbTopic->id])->exists());

        // Student 2 (PROG101) is strictly NOT placed in Database topic or Programming topic
        $this->assertFalse(RoleAssignment::where(['user_id' => $student2->id, 'topic_id' => $dbTopic->id])->exists());
        $this->assertFalse(RoleAssignment::where(['user_id' => $student2->id, 'topic_id' => $progTopic->id])->exists());
    }

    public function test_lab_subset_import_preserves_course_link_and_does_not_add_parent_or_sibling_memberships(): void
    {
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $course = $courseTopic->course;
        $lab = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab B']);
        $sibling = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab A']);
        $this->createLinkedCourse('OTHER1', 'Other course');
        $student = $this->createStudent('LAB001');
        $outsider = $this->createStudent('OTHER1');
        $csv = "Neptun Code,Course Code,Course Name\nLAB001,CHEM101,Chemistry\nOTHER1,OTHER1,Other course\n";

        $job = new ImportCourseEnrollmentsJob($this->storeCsv($csv), $this->admin->id,
            targetTopicId: $lab->id, courseId: $course->id);
        // Exercise the queue's serialization boundary as well.
        unserialize(serialize($job))->handle();

        $this->assertDatabaseHas('role_assignments', ['user_id' => $student->id, 'topic_id' => $lab->id]);
        $this->assertSame(1, RoleAssignment::count());
        $this->assertDatabaseMissing('role_assignments', ['user_id' => $student->id, 'topic_id' => $courseTopic->id]);
        $this->assertDatabaseMissing('role_assignments', ['topic_id' => $sibling->id]);
        $this->assertDatabaseMissing('role_assignments', ['user_id' => $outsider->id]);
        $this->assertSame($courseTopic->id, $course->fresh()->legacyTopic->id);
        $this->assertNull($lab->fresh()->course_id);

        // Reimport is additive and does not create another membership.
        (new ImportCourseEnrollmentsJob($this->storeCsv($csv), $this->admin->id,
            targetTopicId: $lab->id, courseId: $course->id))->handle();
        $this->assertSame(1, RoleAssignment::count());
    }

    public function test_lab_import_ticking_is_relative_to_lab_not_parent_course(): void
    {
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $lab = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab B']);
        $child = app(TopicService::class)->create($this->admin, $lab, ['title' => 'Lab B quiz']);
        $sibling = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab A']);
        $student = $this->createStudent('LAB001');

        (new ImportCourseEnrollmentsJob(
            $this->storeCsv("Neptun Code,Course Code,Course Name\nLAB001,CHEM101,Chemistry\n"),
            $this->admin->id,
            additionalTopicIds: [$courseTopic->id, $child->id, $sibling->id],
            targetTopicId: $lab->id, courseId: $courseTopic->course_id,
        ))->handle();

        $this->assertEqualsCanonicalizing([$courseTopic->id, $lab->id, $child->id],
            RoleAssignment::where('user_id', $student->id)->pluck('topic_id')->all());
    }

    public function test_api_dispatches_lab_destination_and_source_course_separately(): void
    {
        Queue::fake();
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $lab = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab B']);

        $this->actingAs($this->admin)->postJson(route('admin.import.enrolments'), [
            'file' => UploadedFile::fake()->createWithContent('lab.csv', "Neptun Code,Course Code,Course Name\nLAB001,CHEM101,Chemistry\n"),
            'course_id' => $courseTopic->course_id,
            'target_topic_id' => $lab->id,
        ])->assertAccepted();

        Queue::assertPushed(ImportCourseEnrollmentsJob::class, fn ($job) => $job->courseId === $courseTopic->course_id && $job->targetTopicId === $lab->id);
    }

    public function test_api_rejects_unrelated_and_archived_import_destinations(): void
    {
        Queue::fake();
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $other = $this->createLinkedCourse('OTHER1', 'Other course');
        $archived = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Old lab']);
        $archived->update(['archived_at' => now()]);

        foreach ([$other, $archived] as $target) {
            $this->actingAs($this->admin)->postJson(route('admin.import.enrolments'), [
                'file' => UploadedFile::fake()->createWithContent('lab.csv', "Neptun Code,Course Code,Course Name\nLAB001,CHEM101,Chemistry\n"),
                'course_id' => $courseTopic->course_id,
                'target_topic_id' => $target->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('target_topic_id');
        }
        Queue::assertNothingPushed();
    }

    public function test_job_revalidates_destination_when_archived_after_dispatch(): void
    {
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $lab = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab B']);
        $this->createStudent('LAB001');
        $job = new ImportCourseEnrollmentsJob(
            $this->storeCsv("Neptun Code,Course Code,Course Name\nLAB001,CHEM101,Chemistry\n"),
            $this->admin->id, targetTopicId: $lab->id, courseId: $courseTopic->course_id,
        );
        $lab->update(['archived_at' => now()]);
        $job->handle();

        $this->assertSame(0, RoleAssignment::count());
        Storage::disk('local')->assertMissing($job->path);
    }

    public function test_filament_course_import_can_choose_lab_destination(): void
    {
        Queue::fake();
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $course = $courseTopic->course;
        $lab = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab B']);

        Livewire::actingAs($this->admin)->test(FacultyCourses::class, ['faculty' => $course->faculty_id])
            ->callTableAction('importEnrolments', $course, [
                'file' => UploadedFile::fake()->createWithContent('lab.csv', "Neptun Code,Course Code,Course Name\nLAB001,CHEM101,Chemistry\n"),
                'semester_id' => $this->semester->id,
                'target_topic_id' => $lab->id,
                'nodes_above' => [],
                'nodes_below' => [],
            ])->assertHasNoTableActionErrors();

        Queue::assertPushed(ImportCourseEnrollmentsJob::class, fn ($job) => $job->courseId === $course->id && $job->targetTopicId === $lab->id && $job->additionalTopicIds === []);
    }

    public function test_job_refuses_lab_moved_outside_source_course_before_execution(): void
    {
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $other = $this->createLinkedCourse('OTHER1', 'Other course');
        $lab = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab B']);
        $this->createStudent('LAB001');
        $job = new ImportCourseEnrollmentsJob(
            $this->storeCsv("Neptun Code,Course Code,Course Name\nLAB001,CHEM101,Chemistry\n"),
            $this->admin->id, targetTopicId: $lab->id, courseId: $courseTopic->course_id,
        );
        app(TopicService::class)->move($this->admin, $lab, $other);
        $job->handle();

        $this->assertSame(0, RoleAssignment::count());
        Storage::disk('local')->assertMissing($job->path);
    }

    public function test_changing_form_destination_clears_previous_membership_selections(): void
    {
        $courseTopic = $this->createLinkedCourse('CHEM101', 'Chemistry');
        $course = $courseTopic->course;
        $lab = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab B']);
        $sibling = app(TopicService::class)->create($this->admin, $courseTopic, ['title' => 'Lab A']);

        Livewire::actingAs($this->admin)->test(FacultyCourses::class, ['faculty' => $course->faculty_id])
            ->mountTableAction('importEnrolments', $course)
            ->set('mountedActions.0.data.nodes_below', [$sibling->id])
            ->set('mountedActions.0.data.target_topic_id', $lab->id)
            ->assertSet('mountedActions.0.data.nodes_above', [])
            ->assertSet('mountedActions.0.data.nodes_below', []);
    }

    // ---- Helpers -----------------------------------------------------------

    private function storeCsv(string $content): string
    {
        $path = 'imports/'.uniqid('enrol_', true).'.csv';
        Storage::disk('local')->put($path, $content);

        return $path;
    }

    private function createStudent(string $neptun): User
    {
        return User::factory()->create(['neptun_code' => $neptun])->assignRole(UserRole::Student);
    }

    private function createLinkedCourse(string $code, string $name): Topic
    {
        $course = Course::factory()->create(['code' => $code, 'name' => $name]);
        $topic = app(TopicService::class)->create($this->admin, null, ['title' => $name]);
        $topic->update(['course_id' => $course->id]);

        return $topic;
    }
}
