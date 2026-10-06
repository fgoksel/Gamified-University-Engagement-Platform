<?php

namespace Tests\Feature\Admin;

use App\Enums\TreeRole;
use App\Enums\UserRole;
use App\Filament\Pages\Faculties;
use App\Filament\Pages\FacultyCourses;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\User;
use App\Services\TreeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin's Faculties page and the courses of each faculty. Courses are
 * created only here, and each one appears in its faculty's tree by itself.
 */
class FacultiesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create()->assignRole(UserRole::Admin);
    }

    public function test_the_admin_sees_the_faculties_with_their_courses_and_tree(): void
    {
        $informatics = Faculty::factory()->create(['name' => 'Faculty of Informatics']);
        Course::factory()->for($informatics)->count(3)->create();
        app(TreeService::class)->createTree($this->admin, $informatics, User::factory()->create(['name' => 'Dr. Kovács'])->assignRole(UserRole::Teacher));
        $medicine = Faculty::factory()->create(['name' => 'Faculty of Medicine']);

        Livewire::actingAs($this->admin)
            ->test(Faculties::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$informatics, $medicine])
            ->assertTableColumnStateSet('courses_count', 3, $informatics)
            ->assertSee('Dean: Dr. Kovács')
            ->assertSee('No tree yet');
    }

    public function test_the_admin_creates_a_faculty(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Faculties::class)
            ->callAction('create', ['name' => 'Faculty of Informatics', 'code' => 'fi'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('faculties', ['name' => 'Faculty of Informatics', 'code' => 'FI']);
    }

    public function test_a_faculty_name_that_exists_in_any_letter_case_is_refused(): void
    {
        Faculty::factory()->create(['name' => 'Faculty of Informatics']);

        Livewire::actingAs($this->admin)
            ->test(Faculties::class)
            ->callAction('create', ['name' => 'FACULTY OF informatics', 'code' => 'XX'])
            ->assertHasActionErrors(['name']);

        $this->assertSame(1, Faculty::count());
    }

    public function test_a_faculty_can_keep_its_own_name_when_edited(): void
    {
        $faculty = Faculty::factory()->create(['name' => 'Faculty of Informatics', 'code' => 'FI']);

        Livewire::actingAs($this->admin)
            ->test(Faculties::class)
            ->callTableAction('edit', $faculty, ['name' => 'Faculty of informatics', 'code' => 'FI'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Faculty of informatics', $faculty->fresh()->name);
    }

    public function test_a_course_added_to_a_faculty_appears_in_its_tree(): void
    {
        $faculty = Faculty::factory()->create();
        $root = app(TreeService::class)->createTree($this->admin, $faculty, User::factory()->create()->assignRole(UserRole::Teacher));

        Livewire::withQueryParams(['faculty' => $faculty->id])
            ->actingAs($this->admin)
            ->test(FacultyCourses::class)
            ->callAction('create', ['code' => 'it-db101', 'name' => 'Database'])
            ->assertHasNoActionErrors();

        $course = Course::where('code', 'IT-DB101')->sole();
        $this->assertSame($faculty->id, $course->faculty_id);
        $this->assertSame($root->id, $course->courseUnit->parent_id);
        $this->assertSame('Database', $course->courseUnit->title);
    }

    public function test_a_course_code_that_already_exists_is_refused(): void
    {
        $faculty = Faculty::factory()->create();
        Course::factory()->create(['code' => 'IT-DB101']);

        Livewire::withQueryParams(['faculty' => $faculty->id])
            ->actingAs($this->admin)
            ->test(FacultyCourses::class)
            ->callAction('create', ['code' => 'IT-DB101', 'name' => 'Database'])
            ->assertHasActionErrors(['code']);
    }

    public function test_editing_a_course_renames_it_in_the_tree(): void
    {
        $faculty = Faculty::factory()->create();
        app(TreeService::class)->createTree($this->admin, $faculty, User::factory()->create()->assignRole(UserRole::Teacher));
        $course = Course::factory()->for($faculty)->create(['code' => 'IT-DB101', 'name' => 'Database']);

        Livewire::withQueryParams(['faculty' => $faculty->id])
            ->actingAs($this->admin)
            ->test(FacultyCourses::class)
            ->assertCanSeeTableRecords([$course])
            ->callTableAction('edit', $course, ['code' => 'IT-DB101', 'name' => 'Database Systems'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Database Systems', $course->fresh()->courseUnit->title);
    }

    public function test_the_admin_imports_courses_from_a_csv_file(): void
    {
        $faculty = Faculty::factory()->create();
        $other = Faculty::factory()->create(['name' => 'Faculty of Law']);
        app(TreeService::class)->createTree($this->admin, $faculty, User::factory()->create()->assignRole(UserRole::Teacher));
        Course::factory()->for($faculty)->create(['code' => 'IT-DB101']);
        Course::factory()->for($other)->create(['code' => 'LAW100']);

        $file = UploadedFile::fake()->createWithContent('courses.csv', "Course Code,Course Name\nIT-DB101,Database\nIT-PR101,Programming\nit-nw101,Networks\nLAW100,Civil law\n,No code\n");

        Livewire::withQueryParams(['faculty' => $faculty->id])
            ->actingAs($this->admin)
            ->test(FacultyCourses::class)
            ->callAction('import', ['file' => $file])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertEqualsCanonicalizing(['IT-DB101', 'IT-PR101', 'IT-NW101'], $faculty->courses()->pluck('code')->all());
        $this->assertSame(3, $faculty->tree->children()->count());
        $this->assertSame($other->id, Course::where('code', 'LAW100')->sole()->faculty_id);
    }

    public function test_a_course_file_with_the_wrong_columns_is_refused(): void
    {
        $faculty = Faculty::factory()->create();
        $file = UploadedFile::fake()->createWithContent('courses.csv', "Code,Title\nIT-DB101,Database\n");

        Livewire::withQueryParams(['faculty' => $faculty->id])
            ->actingAs($this->admin)
            ->test(FacultyCourses::class)
            ->callAction('import', ['file' => $file])
            ->assertNotified('Import failed');

        $this->assertSame(0, Course::count());
    }

    public function test_every_course_belongs_to_a_faculty(): void
    {
        $this->expectException(QueryException::class);

        Course::create(['code' => 'NOFAC1', 'name' => 'No faculty']);
    }

    public function test_the_dean_cannot_create_courses(): void
    {
        $faculty = Faculty::factory()->create();
        $dean = User::factory()->create()->assignRole(UserRole::Teacher);
        $root = app(TreeService::class)->createTree($this->admin, $faculty, $dean);

        $this->actingAs($dean)->get('/admin/faculties')->assertRedirect('/');
        $this->actingAs($dean)->get("/my-courses/{$root->id}")->assertInertia(fn ($page) => $page
            ->where('addableRoles', [])
            ->where('canAddSubtopic', false));
        $this->assertTrue($root->memberships()->where('role', TreeRole::Dean)->exists());
    }

    public function test_only_admins_can_open_the_faculty_pages(): void
    {
        $faculty = Faculty::factory()->create();
        $teacher = User::factory()->create()->assignRole(UserRole::Teacher);

        $this->actingAs($teacher)->get('/admin/faculties')->assertRedirect('/');
        $this->actingAs($teacher)->get("/admin/faculties/courses?faculty={$faculty->id}")->assertRedirect('/');
        $this->actingAs($this->admin)->get('/admin/faculties')->assertOk();
        $this->actingAs($this->admin)->get("/admin/faculties/courses?faculty={$faculty->id}")->assertOk();
    }
}
