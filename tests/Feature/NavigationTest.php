<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Main menu pages of teachers and students (AppLayout): Leaderboard, Events,
 * Profile, and the QR scanner for students.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /**
     * @return array<string, array{0: UserRole, 1: string, 2: string}>
     */
    public static function pagesPerRole(): array
    {
        return [
            'teacher leaderboard' => [UserRole::Teacher, '/', 'Leaderboard/Index'],
            'teacher all events' => [UserRole::Teacher, '/events', 'Events/Index'],
            'teacher my events' => [UserRole::Teacher, '/my-events', 'Events/Organized'],
            'teacher profile' => [UserRole::Teacher, '/profile', 'Profile/Edit'],
            'teacher my courses' => [UserRole::Teacher, '/my-courses', 'Tree/Index'],
            'student leaderboard' => [UserRole::Student, '/', 'Leaderboard/Index'],
            'student all events' => [UserRole::Student, '/events', 'Events/Index'],
            'student my events' => [UserRole::Student, '/my-events', 'Events/Applied'],
            'student profile' => [UserRole::Student, '/profile', 'Profile/Edit'],
            'student scanner' => [UserRole::Student, '/scan', 'Scan/Index'],
        ];
    }

    #[DataProvider('pagesPerRole')]
    public function test_each_role_opens_its_menu_pages(UserRole $role, string $url, string $component): void
    {
        $this->actingAs($this->userWithRole($role))
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->where('auth.user.role', $role->value));
    }

    public function test_teachers_cannot_open_the_qr_scanner(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Teacher))
            ->get('/scan')
            ->assertRedirect('/')
            ->assertSessionHas('error', 'You do not have permission to view this page.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function appPages(): array
    {
        return [
            'all events' => ['/events'],
            'my events' => ['/my-events'],
            'scanner' => ['/scan'],
            'profile' => ['/profile'],
        ];
    }

    #[DataProvider('appPages')]
    public function test_guests_are_sent_to_login(string $url): void
    {
        $this->get($url)->assertRedirect('/login');
    }

    #[DataProvider('appPages')]
    public function test_admins_and_observers_cannot_open_the_app_pages(string $url): void
    {
        $this->actingAs($this->userWithRole(UserRole::Admin))->get($url)->assertRedirect('/');
        $this->actingAs($this->userWithRole(UserRole::Observer))->get($url)->assertRedirect('/');
    }

    #[DataProvider('appPages')]
    public function test_a_temporary_password_must_be_changed_first(string $url): void
    {
        $student = User::factory()->create(['must_change_password' => true])
            ->assignRole(UserRole::Student);

        $this->actingAs($student)->get($url)->assertRedirect('/password/change');
    }

    public function test_admins_are_sent_from_the_leaderboard_to_the_admin_panel(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Admin))->get('/')->assertRedirect('/admin');
    }
}
