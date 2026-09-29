<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Password Change page on first login (Tasks #14 and #15: UC-2.1 A).
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => 'temporary-password',
            'must_change_password' => true,
        ]);
    }

    public function test_password_change_page_is_shown(): void
    {
        $this->actingAs($this->user)
            ->get('/password/change')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ChangePassword'));
    }

    public function test_guests_cannot_open_it(): void
    {
        $this->get('/password/change')->assertRedirect('/login');
    }

    public function test_changing_the_password_goes_to_home_with_a_message(): void
    {
        $this->actingAs($this->user)
            ->put('/password/change', [
                'current_password' => 'temporary-password',
                'password' => 'my-own-password',
                'password_confirmation' => 'my-own-password',
            ])
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Your password has been changed.');

        $this->user->refresh();
        $this->assertFalse($this->user->must_change_password);
        $this->assertTrue(Hash::check('my-own-password', $this->user->password));

        $this->get('/')->assertOk();
    }

    public function test_old_password_must_be_correct(): void
    {
        $this->actingAs($this->user)
            ->put('/password/change', [
                'current_password' => 'wrong-password',
                'password' => 'my-own-password',
                'password_confirmation' => 'my-own-password',
            ])
            ->assertSessionHasErrors(['current_password' => 'The old password is incorrect.']);
    }

    public function test_new_password_cannot_be_the_old_one(): void
    {
        $this->actingAs($this->user)
            ->put('/password/change', [
                'current_password' => 'temporary-password',
                'password' => 'temporary-password',
                'password_confirmation' => 'temporary-password',
            ])
            ->assertSessionHasErrors(['password' => 'The new password cannot be the same as the old one.']);
    }

    public function test_passwords_must_match_and_be_long_enough(): void
    {
        $this->actingAs($this->user)
            ->put('/password/change', [
                'current_password' => 'temporary-password',
                'password' => 'my-own-password',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);

        $this->actingAs($this->user)
            ->put('/password/change', [
                'current_password' => 'temporary-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($this->user->fresh()->must_change_password);
    }
}
