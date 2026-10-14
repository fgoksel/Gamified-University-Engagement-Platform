<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
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
