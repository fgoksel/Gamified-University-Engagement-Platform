<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The light / dark theme is saved in users.appearance and loaded at login.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_theme_is_saved_for_the_user(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($user)
            ->from('/')
            ->put('/appearance', ['appearance' => 'dark'])
            ->assertRedirect('/');

        $this->assertSame('dark', $user->refresh()->appearance);
    }

    public function test_only_light_or_dark_is_accepted(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($user)
            ->put('/appearance', ['appearance' => 'purple'])
            ->assertSessionHasErrors('appearance');

        $this->assertSame('light', $user->refresh()->appearance);
    }

    public function test_guests_cannot_save_a_theme(): void
    {
        $this->put('/appearance', ['appearance' => 'dark'])->assertRedirect('/login');
    }

    public function test_frontend_receives_the_saved_theme(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'appearance' => 'dark',
        ]);

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.appearance', 'dark'));
    }
}
