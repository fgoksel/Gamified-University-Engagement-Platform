<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Jobs\ImportOrganizersJob;
use App\Jobs\ImportStudentsJob;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use App\Notifications\StudentInvitationNotification;
use App\Services\AssignmentService;
use App\Services\TopicAccess;
use App\Services\TopicService;
use App\Support\LegacyTreeUpgrade;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Checks the background CSV import jobs (Task #30, Technical Specification 5.6, 7.3.1, 8.1, 8.4).
 */
class CsvImportJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        Notification::fake();

        $this->admin = User::factory()->create()->assignRole(UserRole::Admin);

    }

    // ---- Queue behaviour ---------------------------------------------------

    public function test_the_jobs_run_in_the_background_with_three_tries_and_growing_waits(): void
    {
        Queue::fake();

        ImportOrganizersJob::dispatch('imports/a.csv', $this->admin->id);
        ImportStudentsJob::dispatch('imports/b.csv', $this->admin->id);
        ImportCourseEnrollmentsJob::dispatch('imports/c.csv', $this->admin->id);

        Queue::assertPushed(ImportOrganizersJob::class);
        Queue::assertPushed(ImportStudentsJob::class);
        Queue::assertPushed(ImportCourseEnrollmentsJob::class);

        $job = new ImportOrganizersJob('imports/a.csv', $this->admin->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff());
    }

    public function test_the_queue_worker_can_restore_every_import_job(): void
    {
        // The worker unserializes each job before running it. Queue::fake()
        // skips that step, so it is checked here directly.
        foreach ([
            new ImportOrganizersJob('imports/a.csv', $this->admin->id),
            new ImportStudentsJob('imports/b.csv', $this->admin->id),
            new ImportCourseEnrollmentsJob('imports/c.csv', $this->admin->id, semesterId: 7),
        ] as $job) {
            $restored = unserialize(serialize($job));

            $this->assertSame($job->path, $restored->path);
            $this->assertSame($this->admin->id, $restored->adminId);
        }

        $this->assertSame(7, $restored->semesterId);
    }

    public function test_a_job_that_keeps_failing_tells_the_administrator_and_removes_the_file(): void
    {
        $path = $this->csv("Full Name,Email\nAnna Kiss,anna@example.com\n");

        (new ImportOrganizersJob($path, $this->admin->id))->failed(new RuntimeException('database is gone'));

        $this->assertSame('Organizer import failed', $this->adminMessage()['title']);
        Storage::disk('local')->assertMissing($path);
    }

    // ---- Organizers (UC-3.1.1) --------------------------------------------

    public function test_organizers_are_created_as_invited_teachers_and_get_an_activation_email(): void
    {
        $faculty = Faculty::create(['name' => 'Faculty of Law', 'code' => 'LAW']);

        $path = $this->csv("Full Name,Email,Faculty\nAnna Kiss,Anna@Example.com,LAW\nBela Nagy,bela@example.com,Faculty of Law\nCili Toth,cili@example.com,\n");

        (new ImportOrganizersJob($path, $this->admin->id))->handle();

        $anna = User::where('email', 'anna@example.com')->firstOrFail();
        $this->assertSame('invited', $anna->status);
        $this->assertTrue($anna->must_change_password);
        $this->assertTrue($anna->hasRole(UserRole::Teacher));
        $this->assertSame($faculty->id, $anna->faculty_id);
        $this->assertNotEmpty($anna->activation_token);
        $this->assertTrue($anna->activation_token_expires_at->between(now()->addHours(23), now()->addHours(24)->addMinute()));
        $this->assertSame($faculty->id, User::where('email', 'bela@example.com')->value('faculty_id'));
        $this->assertNull(User::where('email', 'cili@example.com')->value('faculty_id'));

        Notification::assertSentTo($anna, OrganizerInvitationNotification::class);
        $this->assertSame('Successfully imported 3 organizers.', $this->adminMessage()['body']);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_organizer_rows_that_cannot_be_imported_are_skipped_and_reported(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $path = $this->csv("Full Name,Email,Faculty\nAnna Kiss,anna@example.com,\nBela Nagy,taken@example.com,\nCili Toth,not-an-email,\n,empty@example.com,\nDora Szabo,dora@example.com,Atlantis\n");

        (new ImportOrganizersJob($path, $this->admin->id))->handle();

        $body = $this->adminMessage()['body'];

        $this->assertStringContainsString('Successfully imported 2 organizers.', $body);
        $this->assertStringContainsString('3 row(s) were skipped.', $body);
        $this->assertStringContainsString('Row 3 skipped: This email address is already registered in the system.', $body);
        $this->assertStringContainsString('Row 4 skipped: Invalid email address format.', $body);
        $this->assertStringContainsString('Row 5 skipped: The full name is missing.', $body);
        $this->assertStringContainsString('Faculty "Atlantis" was not found', $body);
        $this->assertSame(2, User::role(UserRole::Teacher)->count());
    }

    public function test_importing_the_same_organizer_file_twice_creates_nothing_new(): void
    {
        $content = "Full Name,Email\nAnna Kiss,anna@example.com\nBela Nagy,bela@example.com\n";

        (new ImportOrganizersJob($this->csv($content), $this->admin->id))->handle();
        (new ImportOrganizersJob($this->csv($content), $this->admin->id))->handle();

        $this->assertSame(2, User::role(UserRole::Teacher)->count());
        $this->assertStringContainsString('Successfully imported 0 organizers.', $this->adminMessage()['body']);
    }

    public function test_semicolon_delimited_files_with_a_byte_order_mark_are_accepted(): void
    {
        $path = $this->csv("\xEF\xBB\xBFFull Name;Email;Faculty\nAnna Kiss;anna@example.com;\n");

        (new ImportOrganizersJob($path, $this->admin->id))->handle();

        $this->assertTrue(User::where('email', 'anna@example.com')->exists());
    }

    public function test_a_file_with_the_wrong_columns_is_refused_before_any_row_is_imported(): void
    {
        $path = $this->csv("Name,Mail\nAnna Kiss,anna@example.com\n");

        (new ImportOrganizersJob($path, $this->admin->id))->handle();

        $this->assertSame(0, User::role(UserRole::Teacher)->count());
        $message = $this->adminMessage();
        $this->assertSame('Organizer import failed', $message['title']);
        $this->assertStringContainsString('Incorrect file structure', $message['body']);
        $this->assertStringContainsString('Full Name, Email', $message['body']);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_thousand_organizers_are_imported_in_one_job(): void
    {
        $rows = "Full Name,Email\n";
        for ($i = 1; $i <= 1000; $i++) {
            $rows .= "Organizer {$i},organizer{$i}@example.com\n";
        }

        (new ImportOrganizersJob($this->csv($rows), $this->admin->id))->handle();

        $this->assertSame(1000, User::role(UserRole::Teacher)->count());
        $this->assertSame('Successfully imported 1,000 organizers.', $this->adminMessage()['body']);
    }

    // ---- Students (UC-3.2.1) ----------------------------------------------

    public function test_students_are_created_as_invited_students_with_their_data(): void
    {
        $path = $this->csv("Full Name,Email,Neptun Code,Major,Year of Study\nAnna Kiss,anna@example.com,abc123,Computer Science,2\nBela Nagy,bela@example.com,DEF456,,\n");

        (new ImportStudentsJob($path, $this->admin->id))->handle();

        $anna = User::where('neptun_code', 'ABC123')->firstOrFail();
        $this->assertSame('invited', $anna->status);
        $this->assertTrue($anna->hasRole(UserRole::Student));
        $this->assertSame('Computer Science', $anna->major);
        $this->assertSame(2, $anna->year_of_study);
        $this->assertNotEmpty($anna->activation_token);

        $bela = User::where('neptun_code', 'DEF456')->firstOrFail();
        $this->assertNull($bela->major);
        $this->assertNull($bela->year_of_study);

        Notification::assertSentTo($anna, StudentInvitationNotification::class);
        $this->assertSame('Successfully imported 2 students.', $this->adminMessage()['body']);
    }

    public function test_student_rows_that_cannot_be_imported_are_skipped_with_the_specified_messages(): void
    {
        User::factory()->create(['neptun_code' => 'TAKEN1']);
        User::factory()->create(['email' => 'taken@example.com']);

        $path = $this->csv(implode("\n", [
            'Full Name,Email,Neptun Code,Major,Year of Study',
            'Anna Kiss,anna@example.com,ABC123,Law,1',
            'Bela Nagy,bela@example.com,TAKEN1,Law,1',
            'Cili Toth,taken@example.com,CILI01,Law,1',
            'Dora Szabo,not-an-email,DORA01,Law,1',
            'Edit Varga,edit@example.com,SHORT,Law,1',
            'Feri Kovacs,feri@example.com,FERI01,Law,9',
        ])."\n");

        (new ImportStudentsJob($path, $this->admin->id))->handle();

        $body = $this->adminMessage()['body'];

        $this->assertStringContainsString('Successfully imported 1 students.', $body);
        $this->assertStringContainsString('Row 3 skipped: Neptun Code TAKEN1 is already registered.', $body);
        $this->assertStringContainsString('Row 4 skipped: This email address is already registered in the system.', $body);
        $this->assertStringContainsString('Row 5 skipped: Invalid email address format.', $body);
        $this->assertStringContainsString('Row 6 skipped: The Neptun code must be exactly 6 alphanumeric characters.', $body);
        $this->assertStringContainsString('Row 7 skipped: The year of study must be a number from 1 to 6.', $body);
    }

    public function test_importing_the_same_student_file_twice_creates_nothing_new(): void
    {
        $content = "Full Name,Email,Neptun Code\nAnna Kiss,anna@example.com,ABC123\n";

        (new ImportStudentsJob($this->csv($content), $this->admin->id))->handle();
        (new ImportStudentsJob($this->csv($content), $this->admin->id))->handle();

        $this->assertSame(1, User::role(UserRole::Student)->count());
    }

    public function test_a_student_file_without_a_neptun_column_is_refused(): void
    {
        $path = $this->csv("Full Name,Email\nAnna Kiss,anna@example.com\n");

        (new ImportStudentsJob($path, $this->admin->id))->handle();

        $this->assertSame(0, User::role(UserRole::Student)->count());
        $this->assertStringContainsString('Full Name, Email, Neptun Code', $this->adminMessage()['body']);
    }

    // ---- Course enrolments (UC-3.2.3) --------------------------------------
    //
    // Compatibility path: a student is placed only on the topic that the
    // upgrade from the old faculty tree linked to the course (topics.course_id).
    // Nothing is attached to any other topic, nothing is silently dropped.

    public function test_students_are_placed_on_the_topic_linked_to_their_course_for_the_active_semester(): void
    {
        $semester = $this->activeSemester();
        $programming = $this->linkedCourse('BMEINFO101', 'Programming 1');
        $databases = $this->linkedCourse('BMEINFO102', 'Databases');
        $anna = $this->student('ABC123');
        $bela = $this->student('DEF456');

        $path = $this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\nDEF456,BMEINFO101,Programming 1\nABC123,BMEINFO102,Databases\n");

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $this->assertSame(2, RoleAssignment::query()->active()->where('topic_id', $programming->id)->count());
        $this->assertSame(1, RoleAssignment::query()->active()->where(['topic_id' => $databases->id, 'user_id' => $anna->id])->count());
        $this->assertSame(1, RoleAssignment::where('user_id', $bela->id)->count());

        $record = RoleAssignment::where(['topic_id' => $programming->id, 'user_id' => $anna->id])->sole();
        $this->assertSame($semester->id, $record->semester_id);
        $this->assertFalse($record->manual);
        $this->assertSame($this->admin->id, $record->granted_by_id);
        $this->assertSame('student', $record->role->legacy_key);
        $this->assertSame('Successfully processed: 3 student-course registrations.', $this->adminMessage()['body']);
    }

    public function test_the_student_role_is_a_view_only_role_so_the_import_grants_no_extra_power(): void
    {
        $this->activeSemester();
        $topic = $this->linkedCourse('BMEINFO101', 'Programming 1');
        $anna = $this->student('ABC123');

        (new ImportCourseEnrollmentsJob($this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\n"), $this->admin->id))->handle();

        $this->assertSame(['topic.view'], app(TopicAccess::class)->capabilities($anna->fresh(), $topic));
    }

    public function test_unknown_course_codes_are_skipped_and_listed_and_no_course_or_topic_is_created(): void
    {
        $this->activeSemester();
        $this->student('ABC123');

        $path = $this->csv("Neptun Code,Course Code,Course Name\nABC123,NEW100,New course\nABC123,NEW200,Other course\n");

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $this->assertSame(0, Course::count());
        $this->assertSame(0, Topic::count());
        $this->assertSame(0, RoleAssignment::count());
        $this->assertSame(
            'Successfully processed: 0 student-course registrations. Skipped 2 rows because the course code is unknown (NEW100, NEW200). Add the course under its faculty first.',
            $this->adminMessage()['body'],
        );
    }

    public function test_a_course_without_a_linked_topic_is_reported_and_attached_to_nothing(): void
    {
        $this->activeSemester();
        $this->student('ABC123');
        $unrelated = app(TopicService::class)->create($this->admin, null, ['title' => 'Some unrelated topic']);
        Course::factory()->create(['code' => 'MED100', 'name' => 'Anatomy']);

        $path = $this->csv("Neptun Code,Course Code,Course Name\nABC123,MED100,Anatomy\n");

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $this->assertSame(0, RoleAssignment::count());
        $this->assertSame(0, RoleDefinition::count());
        $this->assertSame(1, Topic::count());
        $this->assertStringContainsString('Skipped 1 rows because the course is not linked to a topic (MED100).', $this->adminMessage()['body']);
        $this->assertStringContainsString('never attached to another topic', $this->adminMessage()['body']);
        $this->assertNotNull($unrelated);
    }

    public function test_on_a_fresh_install_the_import_places_nobody_and_creates_no_roles_or_topics(): void
    {
        $this->activeSemester();
        $this->student('ABC123');
        Course::factory()->create(['code' => 'BMEINFO101', 'name' => 'Programming 1']);

        (new ImportCourseEnrollmentsJob($this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\n"), $this->admin->id))->handle();

        $this->assertSame([0, 0, 0], [Topic::count(), RoleDefinition::count(), RoleAssignment::count()]);
        $this->assertStringContainsString('not linked to a topic', $this->adminMessage()['body']);
    }

    public function test_unknown_neptun_codes_are_skipped_and_counted(): void
    {
        $this->activeSemester();
        $this->linkedCourse('BMEINFO101', 'Programming 1');
        $this->student('ABC123');

        $path = $this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\nZZZ999,BMEINFO101,Programming 1\nYYY888,BMEINFO101,Programming 1\n");

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $this->assertSame(1, RoleAssignment::count());
        $this->assertSame(
            'Successfully processed: 1 student-course registrations. Skipped 2 rows due to unregistered Neptun codes.',
            $this->adminMessage()['body'],
        );
    }

    public function test_only_students_are_linked_to_courses(): void
    {
        $this->activeSemester();
        $this->linkedCourse('BMEINFO101', 'Programming 1');
        User::factory()->create(['neptun_code' => 'TEACH1'])->assignRole(UserRole::Teacher);

        $path = $this->csv("Neptun Code,Course Code,Course Name\nTEACH1,BMEINFO101,Programming 1\n");

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $this->assertSame(0, RoleAssignment::count());
    }

    public function test_importing_the_same_enrolment_file_twice_creates_no_duplicates(): void
    {
        $this->activeSemester();
        $this->linkedCourse('BMEINFO101', 'Programming 1');
        $this->student('ABC123');
        $content = "Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\n";

        (new ImportCourseEnrollmentsJob($this->csv($content), $this->admin->id))->handle();
        (new ImportCourseEnrollmentsJob($this->csv($content), $this->admin->id))->handle();

        $this->assertSame(1, Course::count());
        $this->assertSame(1, RoleAssignment::count());
        $this->assertStringContainsString('Successfully processed: 0 student-course registrations.', $this->adminMessage()['body']);
    }

    public function test_a_student_who_holds_another_role_in_the_course_is_still_placed_because_roles_combine(): void
    {
        $this->activeSemester();
        $topic = $this->linkedCourse('BMEINFO101', 'Programming 1');
        $anna = $this->student('ABC123');
        $other = RoleDefinition::create(['name' => 'Helper', 'capabilities' => ['topic.view', 'topic.create'], 'delegable' => [], 'created_by_id' => $this->admin->id]);
        RoleAssignment::create(['topic_id' => $topic->id, 'user_id' => $anna->id, 'role_definition_id' => $other->id, 'started_at' => now()]);

        (new ImportCourseEnrollmentsJob($this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\n"), $this->admin->id))->handle();

        $this->assertSame(2, RoleAssignment::query()->active()->where('user_id', $anna->id)->count());
    }

    public function test_an_import_never_ends_or_deletes_existing_assignments(): void
    {
        $old = Semester::create(['name' => '2025/2026 Spring', 'starts_at' => '2026-02-01', 'ends_at' => '2026-06-30', 'status' => 'archived']);
        $this->activeSemester();
        $oldTopic = $this->linkedCourse('OLD100', 'Old course');
        $this->linkedCourse('BMEINFO101', 'Programming 1');
        $anna = $this->student('ABC123');
        app(AssignmentService::class)->grant($this->admin, $oldTopic, RoleDefinition::find(LegacyTreeUpgrade::ensureRoleDefinition('student')), $anna, $old->id);

        $path = $this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\n");

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $oldRecord = RoleAssignment::where(['topic_id' => $oldTopic->id, 'user_id' => $anna->id])->sole();
        $this->assertTrue($oldRecord->isActive());
        $this->assertSame($old->id, $oldRecord->semester_id);
        $this->assertSame(2, RoleAssignment::query()->active()->where('user_id', $anna->id)->count());
    }

    public function test_an_archived_student_role_is_reported_instead_of_silently_skipping_rows(): void
    {
        $this->activeSemester();
        $this->linkedCourse('BMEINFO101', 'Programming 1');
        $this->student('ABC123');
        RoleDefinition::find(LegacyTreeUpgrade::ensureRoleDefinition('student'))->update(['archived_at' => now()]);

        (new ImportCourseEnrollmentsJob($this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\n"), $this->admin->id))->handle();

        $this->assertSame(0, RoleAssignment::count());
        $this->assertStringContainsString('Skipped 1 rows because the student role is archived', $this->adminMessage()['body']);
    }

    public function test_without_an_active_semester_nothing_is_imported_and_the_administrator_is_told(): void
    {
        $this->linkedCourse('BMEINFO101', 'Programming 1');
        $this->student('ABC123');

        $path = $this->csv("Neptun Code,Course Code,Course Name\nABC123,BMEINFO101,Programming 1\n");

        (new ImportCourseEnrollmentsJob($path, $this->admin->id))->handle();

        $this->assertSame(0, RoleAssignment::count());
        $this->assertStringContainsString('no active semester', $this->adminMessage()['body']);
    }

    // ---- Helpers -----------------------------------------------------------

    private function csv(string $content): string
    {
        $path = 'imports/'.uniqid('', true).'.csv';
        Storage::disk('local')->put($path, $content);

        return $path;
    }

    /**
     * The newest message the administrator received in the admin panel.
     *
     * @return array<string, mixed>
     */
    private function adminMessage(): array
    {
        $sent = Notification::sent($this->admin, DatabaseNotification::class);

        $this->assertNotEmpty($sent, 'The administrator got no message.');

        return $sent->last()->toDatabase($this->admin)['data'] ?? $sent->last()->toDatabase($this->admin);
    }

    private function activeSemester(): Semester
    {
        return Semester::create(['name' => '2026/2027 Fall', 'starts_at' => '2026-09-07', 'ends_at' => '2027-01-31', 'status' => 'active']);
    }

    private function student(string $neptun): User
    {
        return User::factory()->create(['neptun_code' => $neptun])->assignRole(UserRole::Student);
    }

    /**
     * A course carried over from the old faculty tree: its topic is linked by
     * topics.course_id, which is what the enrolment import looks for.
     */
    private function linkedCourse(string $code, string $name): Topic
    {
        $course = Course::factory()->create(['code' => $code, 'name' => $name]);
        $topic = app(TopicService::class)->create($this->admin, null, ['title' => $name]);
        $topic->update(['course_id' => $course->id]);

        return $topic;
    }
}
