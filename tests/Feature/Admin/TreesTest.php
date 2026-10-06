<?php

namespace Tests\Feature\Admin;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\UserRole;
use App\Filament\Pages\TreeDetail;
use App\Filament\Pages\Trees;
use App\Models\Course;
use App\Models\TreeUnit;
use App\Models\User;
use App\Services\TreeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Subject tree, admin side (build step 2): create a tree with its dean,
 * add Neptun courses as subjects and view a whole tree.
 */
class TreesTest extends TestCase
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

    public function test_the_admin_sees_every_tree_with_its_dean_and_subject_count(): void
    {
        $dean = $this->teacher('Dr. Kovács');
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', $dean);
        $this->tree->addSubject($this->admin, $root, Course::factory()->create());

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$root])
            ->assertSee('Faculty of Informatics')
            ->assertSee('Dr. Kovács');
    }

    public function test_the_admin_creates_a_tree_with_its_dean(): void
    {
        $dean = $this->teacher();

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callAction('create', ['title' => 'Faculty of Informatics', 'dean_id' => $dean->id])
            ->assertHasNoActionErrors();

        $root = TreeUnit::where('kind', TreeUnitKind::Root)->sole();
        $this->assertSame('Faculty of Informatics', $root->title);
        $this->assertTrue($root->memberships()->active()->where('user_id', $dean->id)->where('role', TreeRole::Dean)->exists());
    }

    public function test_a_student_cannot_be_made_dean(): void
    {
        $student = User::factory()->create()->assignRole(UserRole::Student);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callAction('create', ['title' => 'Student tree', 'dean_id' => $student->id]);

        $this->assertSame(0, TreeUnit::count());
    }

    public function test_the_admin_adds_a_course_as_a_subject(): void
    {
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', $this->teacher());
        $course = Course::factory()->create(['code' => 'BMEVIDB101', 'name' => 'Database']);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callTableAction('addSubject', $root, ['course_id' => $course->id])
            ->assertHasNoTableActionErrors();

        $subject = $root->children()->sole();
        $this->assertSame(TreeUnitKind::Subject, $subject->kind);
        $this->assertSame('Database', $subject->title);
        $this->assertSame($course->id, $subject->course_id);
    }

    public function test_a_course_already_in_a_tree_cannot_be_added_again(): void
    {
        $course = Course::factory()->create();
        $first = $this->tree->createTree($this->admin, 'Faculty of Informatics', $this->teacher());
        $second = $this->tree->createTree($this->admin, 'Faculty of Engineering', $this->teacher());
        $this->tree->addSubject($this->admin, $first, $course);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callTableAction('addSubject', $second, ['course_id' => $course->id]);

        $this->assertSame(0, $second->children()->count());
    }

    public function test_the_tree_page_shows_every_unit_and_the_people_on_it(): void
    {
        $dean = $this->teacher('Dr. Kovács');
        $teacher = $this->teacher('Dr. Szabó');
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', $dean);
        $database = $this->tree->addSubject($this->admin, $root, Course::factory()->create(['code' => 'BMEVIDB101', 'name' => 'Database']));
        $this->tree->addSubject($this->admin, $root, Course::factory()->create(['name' => 'Networks']));
        $this->tree->addMember($dean, $database, $teacher, TreeRole::Teacher);
        $this->tree->addSubtopic($teacher, $database, 'SQL');
        $this->tree->addMember($teacher, $database, User::factory()->create()->assignRole(UserRole::Student), TreeRole::Student);

        Livewire::withQueryParams(['tree' => $root->id])
            ->actingAs($this->admin)
            ->test(TreeDetail::class)
            ->assertOk()
            ->assertSee('Faculty of Informatics')
            ->assertSee('Dean: Dr. Kovács')
            ->assertSee('BMEVIDB101')
            ->assertSee('Teacher: Dr. Szabó')
            ->assertSee('SQL')
            ->assertSee('Students: 1')
            ->assertSee('(1 added by hand)', false)
            ->assertSee('Networks')
            ->assertSee('No teacher yet');
    }

    public function test_a_dean_can_be_appointed_only_when_the_tree_has_none(): void
    {
        $dean = $this->teacher();
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', $dean);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->assertTableActionHidden('appointDean', $root);

        $this->tree->endMembership($this->admin, $root->memberships()->sole(), 'Retired');
        $newDean = $this->teacher();

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->assertTableActionVisible('appointDean', $root)
            ->callTableAction('appointDean', $root, ['dean_id' => $newDean->id])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($root->memberships()->active()->where('user_id', $newDean->id)->exists());
    }

    public function test_only_admins_can_open_the_tree_pages(): void
    {
        $root = $this->tree->createTree($this->admin, 'Faculty of Informatics', $dean = $this->teacher());

        // Like every admin page, non-admins are sent back to the Vue app.
        $this->actingAs($dean)->get('/admin/trees')->assertRedirect('/');
        $this->actingAs($dean)->get("/admin/trees/view?tree={$root->id}")->assertRedirect('/');

        $this->actingAs($this->admin)->get('/admin/trees')->assertOk();
        $this->actingAs($this->admin)->get("/admin/trees/view?tree={$root->id}")->assertOk();
    }

    private function teacher(?string $name = null): User
    {
        return User::factory()->create($name ? ['name' => $name] : [])->assignRole(UserRole::Teacher);
    }
}
