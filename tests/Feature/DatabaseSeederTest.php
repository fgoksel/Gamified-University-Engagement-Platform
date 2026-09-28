<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Faculty;
use App\Models\Semester;
use App\Models\SubjectArea;
use App\Models\TopicTag;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FacultySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SemesterSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Checks the seeders (Task #11, Technical Specification 6.4).
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIES = ['academic', 'scientific', 'sports', 'community'];

    public function test_full_seeder_creates_roles_and_the_initial_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, Role::count());

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->hasRole(UserRole::Admin));
        $this->assertSame('active', $admin->status);
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_admin_login_details_come_from_env(): void
    {
        config([
            'app.admin.name' => 'Dr. Admin',
            'app.admin.email' => 'admin@pte.hu',
            'app.admin.password' => 'temporary-secret',
        ]);

        $this->seed([RolePermissionSeeder::class, AdminUserSeeder::class]);

        $admin = User::where('email', 'admin@pte.hu')->firstOrFail();

        $this->assertSame('Dr. Admin', $admin->name);
        $this->assertTrue(Hash::check('temporary-secret', $admin->password));
    }

    public function test_production_requires_an_admin_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->app['env'] = 'production';
        config(['app.admin.password' => null]);

        $this->expectException(RuntimeException::class);

        // Called directly: "db:seed" would first ask for confirmation in production.
        $this->app->make(AdminUserSeeder::class)->run();
    }

    public function test_seeder_creates_the_university_faculties(): void
    {
        $this->seed(FacultySeeder::class);

        $this->assertSame(count(FacultySeeder::FACULTIES), Faculty::count());
        $this->assertSame(
            'Faculty of Engineering and Information Technology',
            Faculty::where('code', 'MIK')->value('name'),
        );
    }

    public function test_seeder_creates_exactly_one_active_semester(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Semester::where('status', 'active')->count());
    }

    public function test_semester_seeder_keeps_an_existing_active_semester(): void
    {
        Semester::create([
            'name' => '2027 Spring Semester',
            'starts_at' => '2027-02-01',
            'ends_at' => '2027-06-30',
            'status' => 'active',
        ]);

        $this->seed(SemesterSeeder::class);

        $this->assertSame(1, Semester::count());
    }

    public function test_every_category_has_active_subject_areas_and_topic_tags(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (self::CATEGORIES as $category) {
            $this->assertTrue(
                SubjectArea::where('category', $category)->where('is_active', true)->exists(),
                "No active subject area in category: {$category}",
            );
            $this->assertTrue(
                TopicTag::where('category', $category)->where('is_active', true)->exists(),
                "No active topic tag in category: {$category}",
            );
        }
    }

    public function test_subject_areas_are_linked_to_their_faculty(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('MIK', SubjectArea::where('code', 'MIK-SWE')->firstOrFail()->faculty->code);
        $this->assertNull(SubjectArea::where('code', 'PTE-FOOTBALL')->firstOrFail()->faculty_id);
    }

    public function test_seeder_can_run_again_without_duplicates_or_password_reset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->update(['password' => 'changed-by-admin']);

        $counts = [User::count(), Faculty::count(), Semester::count(), SubjectArea::count(), TopicTag::count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            $counts,
            [User::count(), Faculty::count(), Semester::count(), SubjectArea::count(), TopicTag::count()],
        );
        $this->assertTrue(Hash::check('changed-by-admin', $admin->fresh()->password));
    }
}
