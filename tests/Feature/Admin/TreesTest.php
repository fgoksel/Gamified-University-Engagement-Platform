<?php

namespace Tests\Feature\Admin;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\UserRole;
use App\Filament\Pages\TreeDetail;
use App\Filament\Pages\Trees;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\TreeUnit;
use App\Models\User;
use App\Services\TreeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faculty trees, admin side: one tree per faculty with one dean, named
 * after the faculty and holding all of its courses; viewing a whole tree.
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

    public function test_the_admin_sees_every_tree_with_its_dean_and_course_count(): void
    {
        $faculty = Faculty::factory()->create(['name' => 'Faculty of Informatics']);
        Course::factory()->for($faculty)->count(3)->create();
        $root = $this->tree->createTree($this->admin, $faculty, $this->teacher('Dr. Kovács'));

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->assertOk()
            ->assertSee('Faculty trees')
            ->assertCanSeeTableRecords([$root])
            ->assertTableColumnStateSet('children_count', 3, $root)
            ->assertSee('Faculty of Informatics')
            ->assertSee('Dr. Kovács');
    }

    public function test_the_admin_creates_a_tree_for_a_faculty_with_its_dean(): void
    {
        $faculty = Faculty::factory()->create(['name' => 'Faculty of Informatics']);
        Course::factory()->for($faculty)->create(['name' => 'Database']);
        $dean = $this->teacher();

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callAction('create', ['faculty_id' => $faculty->id, 'dean_id' => $dean->id])
            ->assertHasNoActionErrors();

        $root = TreeUnit::where('kind', TreeUnitKind::Root)->sole();
        $this->assertSame('Faculty of Informatics', $root->title);
        $this->assertSame($faculty->id, $root->faculty_id);
        $this->assertTrue($root->memberships()->active()->where('user_id', $dean->id)->where('role', TreeRole::Dean)->exists());
        $this->assertSame(['Database'], $root->children()->pluck('title')->all());
    }

    public function test_new_tree_offers_only_faculties_without_a_tree_and_people_who_are_not_deans(): void
    {
        $taken = Faculty::factory()->create(['name' => 'Faculty of Informatics']);
        $free = Faculty::factory()->create(['name' => 'Faculty of Medicine']);
        $busyDean = $this->teacher('Busy Dean');
        $this->tree->createTree($this->admin, $taken, $busyDean);
        $this->teacher('Free Teacher');

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->mountAction('create')
            ->assertFormFieldExists('faculty_id', 'mountedActionSchema0', fn ($field) => array_values($field->getOptions()) === ['Faculty of Medicine'])
            ->assertFormFieldExists('dean_id', 'mountedActionSchema0', fn ($field) => count($field->getOptions()) === 1
                && str_starts_with(array_values($field->getOptions())[0], 'Free Teacher'));

        $this->assertTrue($free->tree()->doesntExist());
    }

    public function test_a_faculty_that_has_a_tree_and_a_person_who_is_a_dean_are_refused(): void
    {
        $faculty = Faculty::factory()->create();
        $dean = $this->teacher();
        $this->tree->createTree($this->admin, $faculty, $dean);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callAction('create', ['faculty_id' => $faculty->id, 'dean_id' => $this->teacher()->id]);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callAction('create', ['faculty_id' => Faculty::factory()->create()->id, 'dean_id' => $dean->id]);

        $this->assertSame(1, TreeUnit::where('kind', TreeUnitKind::Root)->count());
    }

    public function test_a_student_cannot_be_made_dean(): void
    {
        $student = User::factory()->create()->assignRole(UserRole::Student);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callAction('create', ['faculty_id' => Faculty::factory()->create()->id, 'dean_id' => $student->id]);

        $this->assertSame(0, TreeUnit::count());
    }

    public function test_the_trees_table_has_no_add_course_action(): void
    {
        $root = $this->tree->createTree($this->admin, Faculty::factory()->create(), $this->teacher());

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->assertTableActionDoesNotExist('addCourse')
            ->assertTableActionVisible('view', $root);
    }

    public function test_the_tree_page_shows_every_unit_and_the_people_on_it(): void
    {
        $faculty = Faculty::factory()->create(['name' => 'Faculty of Informatics']);
        Course::factory()->for($faculty)->create(['code' => 'BMEVIDB101', 'name' => 'Database']);
        Course::factory()->for($faculty)->create(['name' => 'Networks']);
        $dean = $this->teacher('Dr. Kovács');
        $teacher = $this->teacher('Dr. Szabó');
        $root = $this->tree->createTree($this->admin, $faculty, $dean);
        $database = $root->children()->where('title', 'Database')->sole();
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
        $root = $this->tree->createTree($this->admin, Faculty::factory()->create(), $this->teacher());

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

    public function test_the_admin_replaces_a_dean_from_the_trees_table(): void
    {
        $oldDean = $this->teacher('Old Dean');
        $root = $this->tree->createTree($this->admin, Faculty::factory()->create(), $oldDean);
        $newDean = $this->teacher('New Dean');

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->assertTableActionVisible('replaceDean', $root)
            ->assertTableActionHidden('appointDean', $root)
            ->callTableAction('replaceDean', $root, ['dean_id' => $newDean->id, 'ended_reason' => 'End of mandate'])
            ->assertHasNoTableActionErrors();

        $this->assertSame($newDean->id, $root->memberships()->active()->where('role', TreeRole::Dean)->sole()->user_id);
        $this->assertSame('End of mandate', $root->memberships()->where('user_id', $oldDean->id)->sole()->ended_reason);
    }

    public function test_replacing_a_dean_needs_a_reason(): void
    {
        $oldDean = $this->teacher();
        $root = $this->tree->createTree($this->admin, Faculty::factory()->create(), $oldDean);

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callTableAction('replaceDean', $root, ['dean_id' => $this->teacher()->id, 'ended_reason' => ''])
            ->assertHasTableActionErrors(['ended_reason']);

        $this->assertSame($oldDean->id, $root->memberships()->active()->sole()->user_id);
    }

    public function test_the_admin_replaces_a_dean_from_the_tree_page(): void
    {
        $root = $this->tree->createTree($this->admin, Faculty::factory()->create(), $this->teacher());
        $newDean = $this->teacher();

        Livewire::withQueryParams(['tree' => $root->id])
            ->actingAs($this->admin)
            ->test(TreeDetail::class)
            ->callAction('replaceDean', ['dean_id' => $newDean->id, 'ended_reason' => 'Retired'])
            ->assertHasNoActionErrors();

        $this->assertSame($newDean->id, $root->memberships()->active()->sole()->user_id);
    }

    public function test_the_admin_uploads_an_enrolment_file_and_the_import_is_queued(): void
    {
        Queue::fake();
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent('enrolments.csv', "Neptun Code,Course Code,Course Name\nABC123,BMEVIDB101,Database\n");

        Livewire::actingAs($this->admin)
            ->test(Trees::class)
            ->callAction('importEnrolments', ['file' => $file])
            ->assertHasNoActionErrors();

        Queue::assertPushed(ImportCourseEnrollmentsJob::class, fn (ImportCourseEnrollmentsJob $job) => $job->adminId === $this->admin->id);
    }

    public function test_the_admin_downloads_the_sample_enrolment_file(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/sample-csv/enrolments');

        $response->assertOk();
        $this->assertStringStartsWith('"Neptun Code","Course Code","Course Name"', $response->streamedContent());
    }

    public function test_only_admins_can_open_the_tree_pages(): void
    {
        $root = $this->tree->createTree($this->admin, Faculty::factory()->create(), $dean = $this->teacher());

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
