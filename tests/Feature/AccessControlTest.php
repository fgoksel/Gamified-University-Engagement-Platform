<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checks the role and permission enforcement for all four roles
 * (Task #34, Technical Specification 8.2, Functional Specification UC-1.2 and UC-2.2).
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'You do not have permission to view this page.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function roles(): array
    {
        return [
            'admin' => [UserRole::Admin],
            'teacher' => [UserRole::Teacher],
            'student' => [UserRole::Student],
            'observer' => [UserRole::Observer],
        ];
    }

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    #[DataProvider('roles')]
    public function test_permission_middleware_follows_the_permission_list_of_each_role(UserRole $role): void
    {
        $user = $this->userWithRole($role);
        $granted = RolePermissionSeeder::rolePermissions()[$role->value];

        foreach (array_keys(RolePermissionSeeder::PERMISSIONS) as $permission) {
            Route::middleware(['web', 'auth', "permission:{$permission}"])
                ->get("/_rbac/permission/{$permission}", fn () => 'ok');

            $url = "/_rbac/permission/{$permission}";

            if (in_array($permission, $granted, true)) {
                $this->actingAs($user)->get($url)->assertOk();
            } else {
                $this->actingAs($user)->get($url)->assertRedirect('/');
                $this->actingAs($user)->getJson($url)->assertForbidden();
            }
        }
    }

    #[DataProvider('roles')]
    public function test_role_middleware_lets_in_only_the_named_role(UserRole $role): void
    {
        foreach (UserRole::cases() as $routeRole) {
            Route::middleware(['web', 'auth', "role:{$routeRole->value}"])
                ->get("/_rbac/role/{$routeRole->value}", fn () => 'ok');
        }

        $user = $this->userWithRole($role);

        foreach (UserRole::cases() as $routeRole) {
            $response = $this->actingAs($user)->get("/_rbac/role/{$routeRole->value}");

            $role === $routeRole ? $response->assertOk() : $response->assertRedirect('/');
        }
    }

    public function test_role_or_permission_middleware_accepts_either(): void
    {
        Route::middleware(['web', 'auth', 'role_or_permission:admin|events.apply'])
            ->get('/_rbac/either', fn () => 'ok');

        $this->actingAs($this->userWithRole(UserRole::Admin))->get('/_rbac/either')->assertOk();
        $this->actingAs($this->userWithRole(UserRole::Student))->get('/_rbac/either')->assertOk();
        $this->actingAs($this->userWithRole(UserRole::Teacher))->get('/_rbac/either')->assertRedirect('/');
    }

    public function test_a_user_without_any_role_has_no_permissions(): void
    {
        Route::middleware(['web', 'auth', 'permission:leaderboard.view'])
            ->get('/_rbac/no-role', fn () => 'ok');

        $this->actingAs(User::factory()->create())->get('/_rbac/no-role')->assertRedirect('/');
    }

    public function test_controllers_can_authorize_with_permissions(): void
    {
        $this->assertTrue(method_exists(Controller::class, 'authorize'));

        Route::middleware(['web', 'auth'])
            ->get('/_rbac/controller', [RbacProbeController::class, 'manage']);

        $this->actingAs($this->userWithRole(UserRole::Teacher))->get('/_rbac/controller')->assertOk();
        $this->actingAs($this->userWithRole(UserRole::Student))->get('/_rbac/controller')->assertRedirect('/');
        $this->actingAs($this->userWithRole(UserRole::Student))->getJson('/_rbac/controller')->assertForbidden();
    }

    public function test_a_refused_visit_goes_back_to_the_leaderboard_with_the_spec_message(): void
    {
        $student = $this->userWithRole(UserRole::Student);

        $this->actingAs($student)->get('/admin')
            ->assertRedirect('/')
            ->assertSessionHas('error', self::MESSAGE);

        $this->actingAs($student)->followingRedirects()->get('/admin')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leaderboard/Index')
                ->where('flash.error', self::MESSAGE));
    }

    public function test_the_message_is_shown_only_once(): void
    {
        $teacher = $this->userWithRole(UserRole::Teacher);

        $this->actingAs($teacher)->followingRedirects()->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('flash.error', self::MESSAGE));

        $this->actingAs($teacher)->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('flash.error', null));
    }

    public function test_teachers_and_students_cannot_open_the_admin_panel_but_admins_can(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Teacher))->get('/admin/organizers')->assertRedirect('/');
        $this->actingAs($this->userWithRole(UserRole::Student))->get('/admin/students')->assertRedirect('/');
        $this->actingAs($this->userWithRole(UserRole::Admin))->get('/admin/organizers')->assertOk();
    }

    public function test_requests_that_expect_json_still_get_a_403(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Student))
            ->getJson('/admin')
            ->assertForbidden();
    }

    public function test_the_admin_panel_still_sends_guests_to_its_own_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}

/**
 * Stand-in for a real controller: a teacher may manage own events (UC-1.3).
 */
class RbacProbeController extends Controller
{
    public function manage(): string
    {
        $this->authorize('events.manage_own');

        return 'ok';
    }
}
