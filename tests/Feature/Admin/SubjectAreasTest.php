<?php

namespace Tests\Feature\Admin;

use App\Enums\TaxonomyCategory;
use App\Enums\UserRole;
use App\Filament\Pages\SubjectAreas;
use App\Models\Faculty;
use App\Models\SubjectArea;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for Subject Area Management — UC-3.4 (Announcing new subject areas, maintaining existing ones).
 */
class SubjectAreasTest extends TestCase
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

    public function test_admin_can_view_subject_areas_page_and_table(): void
    {
        $faculty = Faculty::create(['name' => 'Faculty of Engineering', 'code' => 'MIK']);

        $subjectArea = SubjectArea::create([
            'title' => 'Software Engineering',
            'code' => 'MIK-SWE',
            'category' => 'academic',
            'faculty_id' => $faculty->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$subjectArea])
            ->assertSee('Software Engineering')
            ->assertSee('MIK-SWE')
            ->assertSee('Academic');
    }

    public function test_admin_can_create_new_subject_area(): void
    {
        $faculty = Faculty::create(['name' => 'Faculty of Sciences', 'code' => 'TTK']);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callAction('create', [
                'title' => 'Quantum Mechanics',
                'code' => 'TTK-QM',
                'category' => TaxonomyCategory::Scientific->value,
                'faculty_id' => $faculty->id,
                'description' => 'Advanced physics research and theory.',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('subject_areas', [
            'title' => 'Quantum Mechanics',
            'code' => 'TTK-QM',
            'category' => 'scientific',
            'faculty_id' => $faculty->id,
            'description' => 'Advanced physics research and theory.',
            'is_active' => 1,
        ]);
    }

    public function test_subject_area_creation_normalizes_code_to_uppercase(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callAction('create', [
                'title' => 'University Football',
                'code' => 'pte-foot',
                'category' => TaxonomyCategory::Sports->value,
                'faculty_id' => null,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('subject_areas', [
            'title' => 'University Football',
            'code' => 'PTE-FOOT',
            'category' => 'sports',
            'is_active' => 1,
        ]);
    }

    public function test_subject_area_creation_requires_mandatory_fields(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callAction('create', [
                'title' => '',
                'code' => '',
                'category' => '',
            ])
            ->assertHasActionErrors(['title' => 'required', 'code' => 'required', 'category' => 'required']);
    }

    public function test_subject_area_creation_rejects_duplicate_code_with_spec_message(): void
    {
        SubjectArea::create([
            'title' => 'Existing Area',
            'code' => 'DUPL-01',
            'category' => 'academic',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callAction('create', [
                'title' => 'Attempt Duplicate Code',
                'code' => 'DUPL-01',
                'category' => 'sports',
            ])
            ->assertHasActionErrors(['code' => 'A subject area with this code already exists.']);
    }

    public function test_admin_can_edit_subject_area(): void
    {
        $faculty1 = Faculty::create(['name' => 'Faculty One', 'code' => 'F1']);
        $faculty2 = Faculty::create(['name' => 'Faculty Two', 'code' => 'F2']);

        $subjectArea = SubjectArea::create([
            'title' => 'Initial Title',
            'code' => 'INIT-01',
            'category' => 'academic',
            'faculty_id' => $faculty1->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callTableAction('edit', $subjectArea, [
                'title' => 'Updated Title',
                'code' => 'UPD-01',
                'category' => TaxonomyCategory::Community->value,
                'faculty_id' => $faculty2->id,
                'description' => 'Updated description text.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('subject_areas', [
            'id' => $subjectArea->id,
            'title' => 'Updated Title',
            'code' => 'UPD-01',
            'category' => 'community',
            'faculty_id' => $faculty2->id,
            'description' => 'Updated description text.',
        ]);
    }

    public function test_subject_area_edit_rejects_duplicate_code(): void
    {
        SubjectArea::create([
            'title' => 'First Area',
            'code' => 'CODE-A',
            'category' => 'academic',
            'is_active' => true,
        ]);

        $secondArea = SubjectArea::create([
            'title' => 'Second Area',
            'code' => 'CODE-B',
            'category' => 'scientific',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callTableAction('edit', $secondArea, [
                'title' => 'Second Area Edited',
                'code' => 'CODE-A',
                'category' => 'scientific',
            ])
            ->assertHasTableActionErrors(['code' => 'A subject area with this code already exists.']);
    }

    public function test_admin_can_deactivate_active_subject_area_per_uc_3_4(): void
    {
        $subjectArea = SubjectArea::create([
            'title' => 'Archived Course',
            'code' => 'ARCH-01',
            'category' => 'academic',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callTableAction('deactivate', $subjectArea)
            ->assertHasNoTableActionErrors();

        $this->assertFalse((bool) $subjectArea->fresh()->is_active);
    }

    public function test_admin_can_activate_inactive_subject_area_per_uc_3_4(): void
    {
        $subjectArea = SubjectArea::create([
            'title' => 'Reinstated Course',
            'code' => 'REIN-01',
            'category' => 'academic',
            'is_active' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->callTableAction('activate', $subjectArea)
            ->assertHasNoTableActionErrors();

        $this->assertTrue((bool) $subjectArea->fresh()->is_active);
    }

    public function test_deactivate_action_is_hidden_for_inactive_subject_area(): void
    {
        $subjectArea = SubjectArea::create([
            'title' => 'Inactive Area',
            'code' => 'INACT-01',
            'category' => 'academic',
            'is_active' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SubjectAreas::class)
            ->assertTableActionHidden('deactivate', $subjectArea)
            ->assertTableActionVisible('activate', $subjectArea);
    }

    public function test_student_cannot_access_subject_areas_management(): void
    {
        $student = User::factory()->create([
            'status' => 'active',
            'must_change_password' => false,
        ])->assignRole(UserRole::Student);

        $this->actingAs($student)
            ->get('/admin/subject-areas')
            ->assertRedirect('/');
    }
}
