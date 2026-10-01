<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Faculty;
use App\Models\SubjectArea;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Data for the teacher's Subject Area panel in the sidebar (UC-1.2).
 */
class SubjectAreaPanelTest extends TestCase
{
    use RefreshDatabase;

    private Faculty $engineering;

    private Faculty $sciences;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->engineering = Faculty::create(['code' => 'MIK', 'name' => 'Faculty of Engineering and Information Technology']);
        $this->sciences = Faculty::create(['code' => 'TTK', 'name' => 'Faculty of Sciences']);

        $this->area('MIK-SWE', 'Software Engineering', 'academic', $this->engineering);
        $this->area('MIK-AI', 'Artificial Intelligence', 'scientific', $this->engineering);
        $this->area('TTK-PHY', 'Physics Research', 'scientific', $this->sciences);
        $this->area('PTE-FOOTBALL', 'Football', 'sports', null);
        $this->area('MIK-OLD', 'Old Area', 'academic', $this->engineering, active: false);
    }

    private function area(string $code, string $title, string $category, ?Faculty $faculty, bool $active = true): void
    {
        SubjectArea::create([
            'code' => $code,
            'title' => $title,
            'category' => $category,
            'faculty_id' => $faculty?->id,
            'is_active' => $active,
        ]);
    }

    public function test_teachers_get_active_areas_grouped_by_faculty_with_university_wide_last(): void
    {
        $teacher = User::factory()->create(['faculty_id' => $this->engineering->id])
            ->assignRole(UserRole::Teacher);

        $this->actingAs($teacher)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('subjectAreaTree.myFacultyId', $this->engineering->id)
            ->has('subjectAreaTree.groups', 3)
            // Engineering: active areas only, A–Z
            ->where('subjectAreaTree.groups.0.code', 'MIK')
            ->has('subjectAreaTree.groups.0.areas', 2)
            ->where('subjectAreaTree.groups.0.areas.0.title', 'Artificial Intelligence')
            ->where('subjectAreaTree.groups.0.areas.0.categoryLabel', 'Scientific')
            ->where('subjectAreaTree.groups.0.areas.1.title', 'Software Engineering')
            ->where('subjectAreaTree.groups.1.code', 'TTK')
            // University-wide areas have no faculty and come last
            ->where('subjectAreaTree.groups.2.id', null)
            ->where('subjectAreaTree.groups.2.name', 'University-wide')
            ->where('subjectAreaTree.groups.2.areas.0.code', 'PTE-FOOTBALL'));
    }

    public function test_the_panel_data_follows_the_teacher_to_every_page(): void
    {
        $teacher = User::factory()->create()->assignRole(UserRole::Teacher);

        $this->actingAs($teacher)->get('/events')->assertInertia(fn (Assert $page) => $page
            ->where('subjectAreaTree.myFacultyId', null)
            ->has('subjectAreaTree.groups', 3));
    }

    public function test_students_do_not_get_the_panel_data(): void
    {
        $student = User::factory()->create()->assignRole(UserRole::Student);

        $this->actingAs($student)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('subjectAreaTree', null));
    }

    public function test_guests_do_not_get_the_panel_data(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('subjectAreaTree', null));
    }
}
