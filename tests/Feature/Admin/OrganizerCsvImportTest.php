<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\OrganizerManagement;
use App\Jobs\ImportOrganizersJob;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for Admin Organizer CSV Import (Task #24, UC-3.1.1, Technical Spec Table 22 & 7.3.1).
 */
class OrganizerCsvImportTest extends TestCase
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

    public function test_admin_can_trigger_organizer_csv_import_via_filament_action(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csvFile = UploadedFile::fake()->createWithContent('organizers.csv', "Full Name,Email\nJohn Doe,john@pte.hu\n");

        Livewire::actingAs($this->admin)
            ->test(OrganizerManagement::class)
            ->callAction('import', [
                'file' => $csvFile,
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(ImportOrganizersJob::class, function (ImportOrganizersJob $job) {
            return $job->adminId === $this->admin->id;
        });
    }

    public function test_api_admin_can_upload_organizers_csv_via_post_route(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csvFile = UploadedFile::fake()->createWithContent('organizers.csv', "Full Name,Email\nJane Doe,jane@pte.hu\n");

        $response = $this->actingAs($this->admin)
            ->post('/admin/import/organizers', [
                'file' => $csvFile,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        Queue::assertPushed(ImportOrganizersJob::class, function (ImportOrganizersJob $job) {
            return $job->adminId === $this->admin->id;
        });
    }

    public function test_api_admin_receives_json_when_requesting_json_on_organizer_import(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csvFile = UploadedFile::fake()->createWithContent('organizers.csv', "Full Name,Email\nJane Doe,jane@pte.hu\n");

        $response = $this->actingAs($this->admin)
            ->postJson('/admin/import/organizers', [
                'file' => $csvFile,
            ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['message', 'path']);

        Queue::assertPushed(ImportOrganizersJob::class);
    }

    public function test_non_admin_cannot_access_organizer_import_api_route(): void
    {
        Queue::fake();

        $teacher = User::factory()->create(['status' => 'active'])->assignRole(UserRole::Teacher);
        $student = User::factory()->create(['status' => 'active'])->assignRole(UserRole::Student);

        $csvFile = UploadedFile::fake()->createWithContent('organizers.csv', "Full Name,Email\nTest,test@pte.hu\n");

        $this->actingAs($teacher)
            ->post('/admin/import/organizers', ['file' => $csvFile])
            ->assertRedirect('/');

        $this->actingAs($student)
            ->postJson('/admin/import/organizers', ['file' => $csvFile])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_import_route_rejects_non_csv_files(): void
    {
        Queue::fake();

        $exeFile = UploadedFile::fake()->create('malicious.exe', 100);

        $response = $this->actingAs($this->admin)
            ->postJson('/admin/import/organizers', [
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
            ->postJson('/admin/import/organizers', [
                'file' => $largeFile,
            ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_download_organizers_sample_csv(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/sample-csv/organizers');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Email', $content);
        $this->assertStringContainsString('Faculty', $content);
        $this->assertStringContainsString('kovacs.istvan@pte.hu', $content);
    }
}
