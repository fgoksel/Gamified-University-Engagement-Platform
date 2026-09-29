<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Checks the roles and permissions (Task #9, Technical Specification 6.4 and 8.2).
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_permission_tables_exist(): void
    {
        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_seeder_creates_the_four_roles_from_the_spec(): void
    {
        $this->assertEqualsCanonicalizing(
            ['admin', 'teacher', 'student', 'observer'],
            Role::pluck('name')->all(),
        );
    }

    public function test_seeder_creates_the_topic_tag_permissions_from_the_spec(): void
    {
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('topic_tags.manage'));
        $this->assertTrue(Role::findByName('teacher')->hasPermissionTo('topic_tags.request'));

        $this->assertFalse(Role::findByName('teacher')->hasPermissionTo('topic_tags.manage'));
        $this->assertFalse(Role::findByName('student')->hasPermissionTo('topic_tags.request'));
    }

    public function test_each_role_gets_exactly_its_listed_permissions(): void
    {
        foreach (RolePermissionSeeder::rolePermissions() as $role => $permissions) {
            $this->assertEqualsCanonicalizing(
                $permissions,
                Role::findByName($role)->permissions->pluck('name')->all(),
                "Wrong permissions for role: {$role}",
            );
        }
    }

    public function test_every_permission_is_used_by_at_least_one_role(): void
    {
        $used = collect(RolePermissionSeeder::rolePermissions())->flatten()->unique()->values();

        $this->assertEqualsCanonicalizing(
            array_keys(RolePermissionSeeder::PERMISSIONS),
            $used->all(),
        );
    }

    public function test_students_and_observers_cannot_do_teacher_or_admin_work(): void
    {
        $student = User::factory()->create()->assignRole(UserRole::Student);
        $observer = User::factory()->create()->assignRole(UserRole::Observer);

        $this->assertTrue($student->can('events.apply'));
        $this->assertFalse($student->can('points.credit'));
        $this->assertFalse($student->can('students.manage'));

        $this->assertTrue($observer->can('leaderboard.view'));
        $this->assertFalse($observer->can('events.view'));
    }

    public function test_seeder_can_run_again_without_duplicates(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(4, Role::count());
        $this->assertSame(count(RolePermissionSeeder::PERMISSIONS), Permission::count());
    }

    public function test_only_active_admins_can_access_the_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $admin = User::factory()->create()->assignRole(UserRole::Admin);
        $invitedAdmin = User::factory()->invited()->create()->assignRole(UserRole::Admin);
        $inactiveAdmin = User::factory()->create(['status' => 'inactive'])->assignRole(UserRole::Admin);
        $teacher = User::factory()->create()->assignRole(UserRole::Teacher);
        $student = User::factory()->create()->assignRole(UserRole::Student);
        $noRole = User::factory()->create();

        $this->assertTrue($admin->canAccessPanel($panel));
        $this->assertFalse($invitedAdmin->canAccessPanel($panel));
        $this->assertFalse($inactiveAdmin->canAccessPanel($panel));
        $this->assertFalse($teacher->canAccessPanel($panel));
        $this->assertFalse($student->canAccessPanel($panel));
        $this->assertFalse($noRole->canAccessPanel($panel));
    }

    public function test_admin_can_open_the_admin_dashboard(): void
    {
        $admin = User::factory()->create()->assignRole(UserRole::Admin);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_student_is_refused_from_the_admin_dashboard(): void
    {
        $student = User::factory()->create()->assignRole(UserRole::Student);

        $this->actingAs($student)->get('/admin')->assertForbidden();
    }

    public function test_guest_is_sent_to_the_admin_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_role_middleware_is_registered_for_routes(): void
    {
        Route::middleware(['web', 'auth', 'role:admin'])->get('/_test/admin-only', fn () => 'ok');

        $admin = User::factory()->create()->assignRole(UserRole::Admin);
        $teacher = User::factory()->create()->assignRole(UserRole::Teacher);

        $this->actingAs($admin)->get('/_test/admin-only')->assertOk();
        $this->actingAs($teacher)->get('/_test/admin-only')->assertForbidden();
    }

    public function test_frontend_receives_the_logged_in_users_role(): void
    {
        $teacher = User::factory()->create(['name' => 'Dr. Kiss Anna'])->assignRole(UserRole::Teacher);

        $this->actingAs($teacher)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.name', 'Dr. Kiss Anna')
            ->where('auth.user.role', 'teacher')
            ->missing('auth.user.password'));
    }

    public function test_frontend_receives_no_user_for_guests(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
    }
}
