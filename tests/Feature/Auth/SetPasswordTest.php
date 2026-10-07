<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Account activation from the invitation email and "Forgot password"
 * (Tasks #14 and #15: UC-1.1 A and C, UC-2.1).
 */
class SetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function invitedUser(string $token = 'valid-token', int $hoursLeft = 24): User
    {
        return User::factory()->invited()->create([
            'activation_token' => $token,
            'activation_token_expires_at' => now()->addHours($hoursLeft),
        ]);
    }

    public function test_invitation_link_opens_the_password_setup_page(): void
    {
        $user = $this->invitedUser();

        $this->get('/auth/activate/valid-token')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/SetPassword')
                ->where('email', $user->email)
                ->where('isActivation', true));
    }

    public function test_the_link_works_even_when_someone_else_is_logged_in_in_the_same_browser(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['name' => 'Admin Person'])->assignRole(UserRole::Admin);
        $student = $this->invitedUser();

        // E.g. the admin imported the students and opens the email in Mailpit.
        $this->actingAs($admin)
            ->get('/auth/activate/valid-token')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/SetPassword')
                ->where('email', $student->email)
                ->where('loggedOutName', 'Admin Person'));

        $this->assertGuest();

        $this->post('/auth/set-password', [
            'token' => 'valid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('login'));

        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_an_unknown_link_does_not_log_anyone_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/auth/activate/no-such-token')->assertRedirect(route('login'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_or_unknown_link_goes_to_login_with_a_message(): void
    {
        $this->invitedUser('old-token', hoursLeft: -1);

        foreach (['old-token', 'made-up-token'] as $token) {
            $this->get('/auth/activate/'.$token)
                ->assertRedirect('/login')
                ->assertSessionHas('error', SetPasswordController::EXPIRED_MESSAGE);
        }
    }

    public function test_setting_a_password_activates_the_account(): void
    {
        $user = $this->invitedUser();

        $this->post('/auth/set-password', [
            'token' => 'valid-token',
            'password' => 'my-new-password',
            'password_confirmation' => 'my-new-password',
        ])->assertRedirect('/login')->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->activation_token);
        $this->assertTrue(Hash::check('my-new-password', $user->password));

        // The user can now log in.
        $this->post('/login', ['email' => $user->email, 'password' => 'my-new-password'])->assertRedirect('/');
    }

    public function test_link_works_only_once(): void
    {
        $this->invitedUser();

        $form = ['token' => 'valid-token', 'password' => 'my-new-password', 'password_confirmation' => 'my-new-password'];

        $this->post('/auth/set-password', $form);

        $this->post('/auth/set-password', $form)
            ->assertRedirect('/login')
            ->assertSessionHas('error', SetPasswordController::EXPIRED_MESSAGE);
    }

    public function test_passwords_must_match_and_be_long_enough(): void
    {
        $this->invitedUser();

        $this->post('/auth/set-password', [
            'token' => 'valid-token',
            'password' => 'my-new-password',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors(['password' => 'The two passwords do not match.']);

        $this->post('/auth/set-password', [
            'token' => 'valid-token',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertSame('invited', User::first()->status);
    }

    public function test_deactivated_account_cannot_use_a_link(): void
    {
        User::factory()->create([
            'status' => 'inactive',
            'activation_token' => 'valid-token',
            'activation_token_expires_at' => now()->addDay(),
        ]);

        $this->get('/auth/activate/valid-token')->assertRedirect('/login');
    }

    public function test_forgot_password_shows_the_same_message_for_any_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        foreach ([$user->email, 'nobody@pte.hu'] as $email) {
            $this->from('/forgot-password')
                ->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')
                ->assertSessionHas('success', ForgotPasswordController::SENT_MESSAGE);
        }

        Notification::assertSentTo($user, PasswordResetNotification::class);
        Notification::assertSentTimes(PasswordResetNotification::class, 1);
    }

    public function test_reset_link_from_the_email_lets_the_user_set_a_new_password(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => 'old-password']);

        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, PasswordResetNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return $notification->expiresInHours === 24;
        });

        $this->get('/auth/activate/'.$token)
            ->assertInertia(fn (Assert $page) => $page->where('isActivation', false));

        // The new password cannot be the old one.
        $this->post('/auth/set-password', [
            'token' => $token,
            'password' => 'old-password',
            'password_confirmation' => 'old-password',
        ])->assertSessionHasErrors(['password' => 'The new password cannot be the same as the old one.']);

        $this->post('/auth/set-password', [
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_invited_user_with_an_expired_link_can_get_a_new_one(): void
    {
        Notification::fake();

        $user = $this->invitedUser('old-token', hoursLeft: -1);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, PasswordResetNotification::class);
        $this->assertNotSame('old-token', $user->fresh()->activation_token);
    }

    public function test_deactivated_account_gets_no_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['status' => 'inactive']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('success', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertNothingSent();
    }
}
