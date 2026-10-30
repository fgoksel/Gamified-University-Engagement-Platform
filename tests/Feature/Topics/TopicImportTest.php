<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Jobs\ImportTopicStudentsJob;
use App\Models\Event;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;

/**
 * Task #72: "Import students" on a topic. One column of Neptun codes; each student gets
 * the chosen role at the topic and at the ticked topics above, so a topic above holds
 * the union of the imported sets (26 October, Q1). Ticking stops at the top of the
 * importer's own branch, and importing is a right passed on like the others (S3, 27 October).
 */
class TopicImportTest extends TopicTestCase
{
    private Topic $university;

    private Topic $faculty;

    private Topic $course;

    private RoleDefinition $student;

    private RoleDefinition $organiser;

    private User $importer;

    private RoleAssignment $importerAssignment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->university = $this->topic('University');
        $this->faculty = $this->topic('Faculty of Informatics', $this->university);
        $this->course = $this->topic('Databases', $this->faculty);

        $this->student = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
        $this->organiser = $this->role('Organiser', [Capability::View, Capability::EventsView, Capability::EventsCreate]);

        // A faculty administrator: the faculty is the top of their own branch.
        $this->importer = $this->makeUser(UserRole::Teacher, 'Faculty Admin');
        $this->importerAssignment = $this->assign($this->importer, $this->role('Faculty importer', [
            Capability::View, Capability::AccessView, Capability::AccessImport, Capability::EventsView, Capability::EventsApply,
        ]), $this->faculty);
    }

    private function studentAccount(string $neptun, string $status = 'active'): User
    {
        $user = $this->makeUser(UserRole::Student, "Student {$neptun}", $status);
        $user->forceFill(['neptun_code' => $neptun])->save();

        return $user;
    }

    private function csv(string ...$codes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('students.csv', "Neptun Code\n".implode("\n", $codes)."\n");
    }

    private function import(Topic $topic, UploadedFile $file, ?RoleDefinition $role = null, array $parents = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->importer)->post("/topics/{$topic->id}/imports", [
            'file' => $file,
            'role_definition_id' => ($role ?? $this->student)->id,
            'parent_topic_ids' => array_map(fn (Topic $t) => $t->id, $parents),
        ]);
    }

    /**
     * @return list<string> titles of the topics where the person has the role
     */
    private function placesOf(User $user, ?RoleDefinition $role = null): array
    {
        return RoleAssignment::query()->active()
            ->where(['user_id' => $user->id, 'role_definition_id' => ($role ?? $this->student)->id])
            ->join('topics', 'topics.id', '=', 'role_assignments.topic_id')
            ->orderBy('topics.title')
            ->pluck('topics.title')
            ->all();
    }

    private function lastResult(User $user): string
    {
        return (string) ($user->notifications()->latest()->first()?->data['body'] ?? '');
    }

    public function test_the_access_tab_offers_the_import_with_allowed_roles_and_topics_only(): void
    {
        $this->actingAs($this->importer)->get("/topics/{$this->course->id}?tab=access")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.import', true)
                ->where('selected.access.import.roles', fn ($roles) => collect($roles)->pluck('name')->all() === ['Faculty importer', 'Student'])
                // The faculty is offered; the university above the importer's own branch is not (S3).
                ->where('selected.access.import.parents', [['id' => $this->faculty->id, 'title' => 'Faculty of Informatics']]));

        $viewer = $this->makeUser(UserRole::Teacher, 'Viewer');
        $this->assign($viewer, $this->role('Access viewer', [Capability::View, Capability::AccessView]), $this->faculty);

        $this->actingAs($viewer)->get("/topics/{$this->course->id}?tab=access")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.import', false)
                ->where('selected.access.import', null));
    }

    public function test_students_imported_at_a_course_and_ticked_for_the_faculty_are_in_both_sets(): void
    {
        $anna = $this->studentAccount('ANNA01');
        $bence = $this->studentAccount('BENC02');
        $csilla = $this->studentAccount('CSIL03');

        $this->import($this->course, $this->csv('ANNA01', 'benc02'), parents: [$this->faculty])
            ->assertRedirect("/topics/{$this->course->id}?tab=access")
            ->assertSessionHas('success', 'The import has started. You will find the result under Notifications.');

        $this->assertSame(['Databases', 'Faculty of Informatics'], $this->placesOf($anna));
        $this->assertSame(['Databases', 'Faculty of Informatics'], $this->placesOf($bence));

        // Without ticking, only the course gets the student; the faculty is unchanged.
        $this->import($this->course, $this->csv('CSIL03'));
        $this->assertSame(['Databases'], $this->placesOf($csilla));

        // Importing the same file again adds nobody twice. (A minute later, so its result is the newest.)
        $this->travel(1)->minutes();
        $before = RoleAssignment::count();
        $this->import($this->course, $this->csv('ANNA01', 'BENC02'), parents: [$this->faculty]);
        $this->assertSame($before, RoleAssignment::count());
        $this->assertStringContainsString('0 student(s) now have the role "Student" in Databases. 2 already had it there.', $this->lastResult($this->importer));
    }

    public function test_topics_above_the_importers_own_branch_are_refused(): void
    {
        $anna = $this->studentAccount('ANNA01');

        $this->import($this->course, $this->csv('ANNA01'), parents: [$this->university])
            ->assertSessionHasErrors('parent_topic_ids');

        $this->assertSame([], $this->placesOf($anna));
    }

    public function test_only_roles_within_the_importers_powers_can_be_given(): void
    {
        $anna = $this->studentAccount('ANNA01');

        // "Organiser" holds "Create draft events", which the importer may not pass on.
        $this->import($this->course, $this->csv('ANNA01'), $this->organiser)
            ->assertSessionHasErrors(['role_definition_id' => 'You cannot pass on these powers here: Create draft events.']);

        $this->assertSame([], $this->placesOf($anna, $this->organiser));
    }

    public function test_people_without_the_right_cannot_import(): void
    {
        $anna = $this->studentAccount('ANNA01');

        $viewer = $this->makeUser(UserRole::Teacher, 'Viewer');
        $this->assign($viewer, $this->role('Access viewer', [Capability::View, Capability::AccessView]), $this->faculty);
        // A refusal (403) on a page sends the person home with a message, like everywhere in the app.
        $this->import($this->course, $this->csv('ANNA01'), as: $viewer)
            ->assertRedirect('/')
            ->assertSessionHas('error', 'You do not have permission to view this page.');

        // Someone who cannot even see the topic learns nothing about it.
        $outsider = $this->makeUser(UserRole::Teacher, 'Outsider');
        $this->import($this->course, $this->csv('ANNA01'), as: $outsider)->assertNotFound();

        $this->assertSame([], $this->placesOf($anna));
    }

    public function test_the_file_must_be_a_csv_with_a_neptun_code_column(): void
    {
        $this->import($this->course, UploadedFile::fake()->create('students.pdf', 10))
            ->assertSessionHasErrors('file');

        $this->import($this->course, UploadedFile::fake()->createWithContent('students.csv', "Name\nAnna\n"))
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('The CSV file needs the columns: Neptun Code.', $this->lastResult($this->importer));
    }

    public function test_an_upload_the_server_cannot_save_is_refused_with_a_clear_message(): void
    {
        Queue::fake();
        Exceptions::fake();

        // For example a storage folder the web server may not write to.
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);

        $this->import($this->course, $this->csv('ANNA01'))
            ->assertSessionHasErrors(['file' => 'The file could not be saved on the server, so nothing was imported. Please try again, or tell an administrator.']);

        Queue::assertNothingPushed();
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_missing_file_fails_at_once_with_a_clear_message(): void
    {
        (new ImportTopicStudentsJob('imports/gone.csv', $this->importer->id, $this->course->id, $this->student->id))->handle();

        $result = $this->importer->notifications()->latest()->first()->data;
        $this->assertSame('Student import failed', $result['title']);
        $this->assertSame('The uploaded file could not be found, so nothing was imported. Please upload it again.', $result['body']);
    }

    public function test_a_single_column_and_a_semicolon_export_are_both_read(): void
    {
        $anna = $this->studentAccount('ANNA01');
        $bence = $this->studentAccount('BENC02');

        // One column and one row: there is no delimiter, and the space in the heading is not one.
        $this->import($this->course, UploadedFile::fake()->createWithContent('one.csv', "Neptun Code\nANNA01\n"));
        // A spreadsheet export with more columns, separated by semicolons.
        $this->import($this->course, UploadedFile::fake()->createWithContent('export.csv', "Name;Neptun Code;Email\nBence Tóth;BENC02;b@example.test\n"));

        $this->assertSame(['Databases'], $this->placesOf($anna));
        $this->assertSame(['Databases'], $this->placesOf($bence));
    }

    public function test_the_result_lists_who_was_added_and_why_rows_were_skipped(): void
    {
        $this->studentAccount('ANNA01');
        $this->studentAccount('IVY009', 'invited');
        $teacher = $this->makeUser(UserRole::Teacher, 'A Teacher');
        $teacher->forceFill(['neptun_code' => 'TEACH1'])->save();

        $this->import($this->course, $this->csv('ANNA01', 'ZZZ999', 'IVY009', 'TEACH1', ''));

        $result = $this->lastResult($this->importer);
        $this->assertSame('Student import finished', $this->importer->notifications()->latest()->first()->data['title']);
        $this->assertStringContainsString('1 student(s) now have the role "Student" in Databases.', $result);
        $this->assertStringContainsString('no student account has the Neptun code ZZZ999.', $result);
        $this->assertStringContainsString('IVY009: Only active accounts can be given a role.', $result);
        $this->assertStringContainsString('no student account has the Neptun code TEACH1.', $result);

        // The importer reads it on the Notifications page.
        $this->actingAs($this->importer)->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.data.0.title', 'Student import finished')
                ->where('notifications.data.0.body', fn ($body) => str_contains($body, 'no student account has the Neptun code ZZZ999.')));
    }

    public function test_the_right_can_be_passed_on_and_the_new_holder_stops_at_their_own_branch(): void
    {
        $anna = $this->studentAccount('ANNA01');

        // A faculty lead who may give roles and pass on importing.
        $lead = $this->makeUser(UserRole::Teacher, 'Faculty Lead');
        $this->assign($lead, $this->role('Faculty lead', [
            Capability::View, Capability::AccessView, Capability::AccessAssign, Capability::AccessImport, Capability::EventsView, Capability::EventsApply,
        ]), $this->faculty);

        $courseImporter = $this->role('Course importer', [Capability::View, Capability::AccessView, Capability::AccessImport, Capability::EventsView, Capability::EventsApply]);
        $tutor = $this->makeUser(UserRole::Teacher, 'Tutor');

        $this->actingAs($lead)->post("/topics/{$this->course->id}/assignments", ['user_id' => $tutor->id, 'role_definition_id' => $courseImporter->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($tutor)->get("/topics/{$this->course->id}?tab=access")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.can.import', true)
                ->where('selected.access.import.parents', []));

        $this->import($this->course, $this->csv('ANNA01'), as: $tutor)->assertSessionHasNoErrors();
        $this->assertSame(['Databases'], $this->placesOf($anna));
    }

    public function test_imported_students_see_the_events_of_the_ticked_faculty(): void
    {
        $anna = $this->studentAccount('ANNA01');
        Event::factory()->create([
            'title' => 'Faculty career day',
            'owning_topic_id' => $this->faculty->id,
            'audience_topic_id' => $this->faculty->id,
        ]);

        $titles = function () use ($anna): array {
            $titles = [];
            $this->actingAs($anna)->get('/events')->assertOk()->assertInertia(function (Assert $page) use (&$titles) {
                $titles = collect($page->toArray()['props']['events']['data'])->pluck('title')->all();
            });

            return $titles;
        };

        $this->assertNotContains('Faculty career day', $titles());

        $this->import($this->course, $this->csv('ANNA01'), parents: [$this->faculty]);

        $this->assertContains('Faculty career day', $titles());
    }

    public function test_the_job_checks_the_rights_again_and_records_the_import(): void
    {
        $anna = $this->studentAccount('ANNA01');
        Storage::disk('local')->put('imports/students.csv', "Neptun Code\nANNA01\n");
        $job = new ImportTopicStudentsJob('imports/students.csv', $this->importer->id, $this->course->id, $this->student->id, [$this->faculty->id]);

        // The right was taken away after the file was uploaded.
        $this->importerAssignment->forceFill(['ended_at' => now()])->save();
        $job->handle();

        $this->assertSame([], $this->placesOf($anna));
        $this->assertStringContainsString('ANNA01: You cannot import students here.', $this->lastResult($this->importer));

        // With the right, each new role records the import and the assignment that allowed it.
        $this->importerAssignment->forceFill(['ended_at' => null])->save();
        Storage::disk('local')->put('imports/students.csv', "Neptun Code\nANNA01\n");
        $this->app->forgetScopedInstances(); // a new job starts with fresh rights
        $job->handle();

        $made = RoleAssignment::query()->active()->where('user_id', $anna->id)->get();
        $this->assertCount(2, $made);
        $this->assertSame([$this->importerAssignment->id], $made->pluck('granted_by_assignment_id')->unique()->values()->all());
        $this->assertSame(2, DB::table('topic_audit_events')->where('action', 'assignment.imported')->count());
    }
}
