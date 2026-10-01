<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Widgets\SystemOverview;
use App\Models\Semester;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * System Administrator login and dashboard shell (Task #17, UC-3.0).
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['password' => 'admin-password'])->assignRole(UserRole::Admin);
    }

    public function test_admin_can_log_in_and_lands_on_the_dashboard(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => $this->admin->email, 'password' => 'admin-password'])
            ->call('authenticate')
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_dashboard_shows_the_system_overview(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSeeLivewire(SystemOverview::class);
    }

    public function test_overview_counts_organizers_and_students_by_status(): void
    {
        User::factory()->count(2)->create()->each->assignRole(UserRole::Teacher);
        User::factory()->invited()->create()->assignRole(UserRole::Teacher);
        User::factory()->count(3)->create()->each->assignRole(UserRole::Student);

        $this->actingAs($this->admin);

        Livewire::test(SystemOverview::class)
            ->assertSee('Organizers')
            ->assertSee('2 active · 1 invited')
            ->assertSee('Students')
            ->assertSee('3 active · 0 invited');
    }

    public function test_overview_shows_days_left_in_the_active_semester(): void
    {
        Semester::create([
            'name' => '2026/2027 Fall Semester',
            'starts_at' => today()->subDays(10),
            'ends_at' => today()->addDays(30),
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(SystemOverview::class)
            ->assertSee('2026/2027 Fall Semester')
            ->assertSee('30 days left');
    }

    public function test_overview_says_when_there_is_no_active_semester(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SystemOverview::class)->assertSee('No active semester');
    }

    public function test_admin_can_open_the_profile_page(): void
    {
        $this->actingAs($this->admin)->get('/admin/profile')->assertOk();
    }

    public function test_admin_can_change_own_password(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'brand-new-password',
                'passwordConfirmation' => 'brand-new-password',
                'currentPassword' => 'admin-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('brand-new-password', $this->admin->fresh()->password));
    }

    public function test_new_password_cannot_be_the_old_one(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'admin-password',
                'passwordConfirmation' => 'admin-password',
                'currentPassword' => 'admin-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    public function test_admin_with_a_temporary_password_is_sent_to_the_profile_page(): void
    {
        $this->admin->update(['must_change_password' => true]);

        $this->actingAs($this->admin);

        $this->get('/admin')->assertRedirect('/admin/profile');
        $this->get('/admin/organizers')->assertRedirect('/admin/profile');
        $this->get('/admin/profile')->assertOk()->assertSee('You are using a temporary password');
    }

    public function test_changing_the_temporary_password_unlocks_the_admin_panel(): void
    {
        $this->admin->update(['must_change_password' => true]);

        $this->actingAs($this->admin);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'my-own-password',
                'passwordConfirmation' => 'my-own-password',
                'currentPassword' => 'admin-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertFalse($this->admin->fresh()->must_change_password);
        $this->get('/admin')->assertOk();
    }

    public function test_temporary_password_must_be_replaced_not_left_empty(): void
    {
        $this->admin->update(['must_change_password' => true]);

        $this->actingAs($this->admin);

        Livewire::test(EditProfile::class)
            ->call('save')
            ->assertHasFormErrors(['password' => 'required']);

        $this->assertTrue($this->admin->fresh()->must_change_password);
    }

    public function test_seeded_admin_must_change_the_password_at_first_login(): void
    {
        $this->seed(AdminUserSeeder::class);
        $seededAdmin = User::where('email', config('app.admin.email'))->first();

        $this->actingAs($seededAdmin)->get('/admin')->assertRedirect('/admin/profile');
    }

    public function test_admin_login_page_has_a_forgot_password_link(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('/admin/password-reset/request', false);
        $this->get('/admin/password-reset/request')->assertOk();
    }
}
