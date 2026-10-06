<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\Courses;
use App\Models\Course;
use App\Models\Semester;
use App\Models\User;
use App\Services\TreeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin's list of Neptun courses: which tree each one is in, how many
 * students it has, and adding a course to a tree.
 */
class CoursesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create()->assignRole(UserRole::Admin);
        $this->tree = app(TreeService::class);
    }

    public function test_the_admin_sees_every_course_with_its_tree_and_students(): void
    {
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', User::factory()->create()->assignRole(UserRole::Teacher));
        $database = Course::create(['code' => 'IT-DB101', 'name' => 'Database']);
        $subject = $this->tree->addSubject($this->admin, $root, $database);
        $semester = Semester::create(['name' => 'Fall', 'starts_at' => '2026-09-01', 'ends_at' => '2027-01-31', 'status' => 'active']);
        $this->tree->importStudent($subject, User::factory()->create()->assignRole(UserRole::Student), $semester);
        $this->tree->importStudent($subject, User::factory()->create()->assignRole(UserRole::Student), $semester);
        $loose = Course::create(['code' => 'IT-NEW1', 'name' => 'Imported only']);

        Livewire::actingAs($this->admin)
            ->test(Courses::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$database, $loose])
            ->assertTableColumnStateSet('tree', 'Faculty of Informatics', $database)
            ->assertTableColumnStateSet('students', 2, $database)
            ->assertTableColumnStateSet('students', 0, $loose)
            ->assertSee('Not in a tree');
    }

    public function test_courses_can_be_filtered_by_whether_they_are_in_a_tree(): void
    {
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', User::factory()->create()->assignRole(UserRole::Teacher));
        $placed = Course::create(['code' => 'IT-DB101', 'name' => 'Database']);
        $this->tree->addSubject($this->admin, $root, $placed);
        $loose = Course::create(['code' => 'IT-NEW1', 'name' => 'Imported only']);

        Livewire::actingAs($this->admin)
            ->test(Courses::class)
            ->filterTable('in_tree', false)
            ->assertCanSeeTableRecords([$loose])
            ->assertCanNotSeeTableRecords([$placed]);
    }

    public function test_the_admin_adds_a_course_that_is_in_no_tree_to_a_tree(): void
    {
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', User::factory()->create()->assignRole(UserRole::Teacher));
        $course = Course::create(['code' => 'IT-NEW1', 'name' => 'Imported only']);

        Livewire::actingAs($this->admin)
            ->test(Courses::class)
            ->assertTableActionHidden('viewTree', $course)
            ->callTableAction('addToTree', $course, ['tree_id' => $root->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame($root->id, $course->subjectUnit()->sole()->parent_id);

        Livewire::actingAs($this->admin)
            ->test(Courses::class)
            ->assertTableActionHidden('addToTree', $course)
            ->assertTableActionVisible('viewTree', $course);
    }

    public function test_only_admins_can_open_the_courses_page(): void
    {
        $teacher = User::factory()->create()->assignRole(UserRole::Teacher);

        $this->actingAs($teacher)->get('/admin/courses')->assertRedirect('/');
        $this->actingAs($this->admin)->get('/admin/courses')->assertOk();
    }
}
