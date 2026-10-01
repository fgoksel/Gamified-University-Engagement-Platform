<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\StudentManagement;
use App\Jobs\ImportStudentsJob;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for Admin Student CSV Import (Task #27, UC-3.2.1, Technical Spec Table 22 & 7.3.1).
 */
class StudentCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create([
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Admin);
    }

    public function test_admin_can_trigger_student_csv_import_via_filament_action(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csvFile = UploadedFile::fake()->createWithContent('students.csv', "Full Name,Email,Neptun Code\nJane Student,jane@student.pte.hu,STU001\n");

        Livewire::actingAs($this->admin)
            ->test(StudentManagement::class)
            ->callAction('import', [
                'file' => $csvFile,
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(ImportStudentsJob::class, function (ImportStudentsJob $job) {
            return $job->adminId === $this->admin->id;
        });
    }

    public function test_api_admin_can_upload_students_csv_via_post_route(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csvFile = UploadedFile::fake()->createWithContent('students.csv', "Full Name,Email,Neptun Code\nJane Student,jane@student.pte.hu,STU002\n");

        $response = $this->actingAs($this->admin)
            ->post('/admin/import/students', [
                'file' => $csvFile,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        Queue::assertPushed(ImportStudentsJob::class, function (ImportStudentsJob $job) {
            return $job->adminId === $this->admin->id;
        });
    }

    public function test_api_admin_receives_json_when_requesting_json_on_student_import(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csvFile = UploadedFile::fake()->createWithContent('students.csv', "Full Name,Email,Neptun Code\nJane Student,jane@student.pte.hu,STU003\n");

        $response = $this->actingAs($this->admin)
            ->postJson('/admin/import/students', [
                'file' => $csvFile,
            ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['message', 'path']);

        Queue::assertPushed(ImportStudentsJob::class);
    }

    public function test_non_admin_cannot_access_student_import_api_route(): void
    {
        Queue::fake();

        $teacher = User::factory()->create(['status' => 'active'])->assignRole(UserRole::Teacher);
        $student = User::factory()->create(['status' => 'active'])->assignRole(UserRole::Student);

        $csvFile = UploadedFile::fake()->createWithContent('students.csv', "Full Name,Email,Neptun Code\nTest,test@student.pte.hu,ABC123\n");

        $this->actingAs($teacher)
            ->post('/admin/import/students', ['file' => $csvFile])
            ->assertRedirect('/');

        $this->actingAs($student)
            ->postJson('/admin/import/students', ['file' => $csvFile])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_import_route_rejects_non_csv_files(): void
    {
        Queue::fake();

        $exeFile = UploadedFile::fake()->create('malicious.exe', 100);

        $response = $this->actingAs($this->admin)
            ->postJson('/admin/import/students', [
                'file' => $exeFile,
            ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_import_route_rejects_files_exceeding_10mb_limit(): void
    {
        Queue::fake();

        // 11 MB file exceeds 10240 KB limit (Tech Spec 7.3.1)
        $largeFile = UploadedFile::fake()->create('large.csv', 11 * 1024);

        $response = $this->actingAs($this->admin)
            ->postJson('/admin/import/students', [
                'file' => $largeFile,
            ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_download_students_sample_csv(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/sample-csv/students');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Email', $content);
        $this->assertStringContainsString('Neptun Code', $content);
        $this->assertStringContainsString('minta.peter@student.pte.hu', $content);
        $this->assertStringContainsString('ABC123', $content);
    }
}
