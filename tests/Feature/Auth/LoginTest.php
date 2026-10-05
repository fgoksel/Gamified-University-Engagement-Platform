<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Database\Seeders\DevUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Login and logout for teachers and students (Tasks #14 and #15: UC-1.1 B, UC-2.1).
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_login_page_is_shown(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_teacher_and_student_are_taken_to_the_home_page_after_login(): void
    {
        foreach ([UserRole::Teacher, UserRole::Student] as $role) {
            $user = User::factory()->create(['password' => 'correct-password'])->assignRole($role);

            $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
                ->assertRedirect('/');

            $this->assertAuthenticatedAs($user);
            $this->get('/')->assertOk();

            $this->post('/logout');
        }
    }

    public function test_wrong_password_shows_a_generic_message(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Incorrect username or password.']);

        $this->assertGuest();
    }

    public function test_unknown_email_shows_the_same_generic_message(): void
    {
        $this->post('/login', ['email' => 'nobody@pte.hu', 'password' => 'whatever-password'])
            ->assertSessionHasErrors(['email' => 'Incorrect username or password.']);
    }

    public function test_invited_and_deactivated_accounts_cannot_log_in(): void
    {
        $invited = User::factory()->invited()->create(['password' => 'correct-password']);
        $inactive = User::factory()->create(['password' => 'correct-password', 'status' => 'inactive']);

        foreach ([$invited, $inactive] as $user) {
            $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
                ->assertSessionHasErrors(['email' => 'Incorrect username or password.']);
        }

        $this->assertGuest();
    }

    public function test_user_with_a_temporary_password_must_change_it_first(): void
    {
        $user = User::factory()->create([
            'password' => 'temporary-password',
            'must_change_password' => true,
        ])->assignRole(UserRole::Student);

        $this->post('/login', ['email' => $user->email, 'password' => 'temporary-password'])
            ->assertRedirect('/password/change');

        // Every other page sends them back to the Password Change page.
        $this->get('/')->assertRedirect('/password/change');
    }

    public function test_administrators_are_sent_to_the_admin_panel(): void
    {
        $admin = User::factory()->create(['password' => 'correct-password'])->assignRole(UserRole::Admin);

        $this->post('/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertRedirect('/admin');

        $this->get('/')->assertRedirect('/admin');
    }

    public function test_administrators_leave_the_vue_app_with_a_full_page_visit(): void
    {
        $admin = User::factory()->create(['password' => 'correct-password'])->assignRole(UserRole::Admin);

        // The login form is sent by Inertia. A plain redirect would open the
        // admin panel inside Inertia's error dialog, so a 409 tells the
        // browser to load /admin as a normal page instead.
        $this->withHeader('X-Inertia', 'true')
            ->post('/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', '/admin');

        // Inertia GET visits also carry the asset version; without it the
        // middleware asks for a reload of "/" before the controller runs.
        $this->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', (string) app(HandleInertiaRequests::class)->version(request()))
            ->get('/')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', '/admin');
    }

    public function test_logged_in_users_do_not_see_the_login_page(): void
    {
        $user = User::factory()->create()->assignRole(UserRole::Student);

        $this->actingAs($user)->get('/login')->assertRedirect();
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create()->assignRole(UserRole::Teacher);

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_too_many_attempts_show_a_message_on_the_form(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Too many attempts. Please wait a minute and try again.']);

        $this->assertGuest();
    }

    public function test_dev_test_accounts_can_log_in(): void
    {
        $this->seed(DevUserSeeder::class);

        $this->post('/login', ['email' => 'student@campusengage.test', 'password' => DevUserSeeder::PASSWORD])
            ->assertRedirect('/password/change');

        $this->assertTrue(User::where('email', 'teacher@campusengage.test')->first()->hasRole(UserRole::Teacher));
    }
}
