<?php

namespace Tests\Feature\DataProtection;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Jobs\ImportTopicStudentsJob;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Topics\TopicTestCase;

/**
 * Task #62 (GDPR): a file of students (Neptun codes, and names and addresses where the file has
 * them) is kept only while its import needs it. CsvImportJobsTest shows it for the other imports;
 * this is the same for "Import students" on an area, which had no such test.
 */
class UploadedImportFilesTest extends TopicTestCase
{
    private function upload(string $content): string
    {
        $path = 'imports/'.Str::random(10).'.csv';
        Storage::disk('local')->put($path, $content);

        return $path;
    }

    public function test_the_file_of_a_student_import_is_deleted_when_the_import_finishes_is_refused_or_fails(): void
    {
        Storage::fake('local');

        $faculty = $this->topic('Faculty');
        $role = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);
        $this->makeUser(UserRole::Student, 'Quixote Zephyrine')->forceFill(['neptun_code' => 'QZ0001'])->save();

        $job = fn (string $path) => new ImportTopicStudentsJob($path, $this->admin->id, $faculty->id, $role->id);

        // It finishes.
        $path = $this->upload("Neptun Code\nQZ0001\n");
        $this->assertTrue(Storage::disk('local')->exists($path));
        $job($path)->handle();
        Storage::disk('local')->assertMissing($path);

        // It is refused for its columns: the same file would be refused again, so nothing keeps it.
        $path = $this->upload("Name,Address\nQuixote Zephyrine,quixote.zephyrine@example.test\n");
        $job($path)->handle();
        Storage::disk('local')->assertMissing($path);

        // It fails for good, after the queue has tried again.
        $path = $this->upload("Neptun Code\nQZ0001\n");
        $job($path)->failed(new RuntimeException('The database is gone.'));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_report_of_a_student_import_goes_only_to_the_admin_who_started_it(): void
    {
        Storage::fake('local');

        $faculty = $this->topic('Faculty');
        $role = $this->role('Student', [Capability::View, Capability::EventsView, Capability::EventsApply]);

        // A code nobody has: the report says which line and which code, from the file the admin sent.
        $path = $this->upload("Neptun Code\nNOBODY\n");
        (new ImportTopicStudentsJob($path, $this->admin->id, $faculty->id, $role->id))->handle();

        $report = (string) User::find($this->admin->id)->notifications()->latest()->first()?->data['body'];

        $this->assertStringContainsString('NOBODY', $report);
        // And the report is for the admin who started the import, nobody else.
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(0, User::query()->whereKeyNot($this->admin->id)->get()->sum(fn (User $user) => $user->notifications()->count()));
    }
}
