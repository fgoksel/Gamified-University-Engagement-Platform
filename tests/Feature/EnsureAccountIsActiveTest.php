<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureAccountIsActiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_active_user_can_access_authenticated_pages(): void
    {
        $user = User::create([
            'name' => 'Active Student',
            'email' => 'active@student.pte.hu',
            'password' => 'secret_hash',
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(UserRole::Student);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
    }

    public function test_deactivated_user_is_logged_out_on_next_request_per_uc_3_1_2_and_uc_3_2_2(): void
    {
        $user = User::create([
            'name' => 'Banned User',
            'email' => 'banned@student.pte.hu',
            'password' => 'secret_hash',
            'status' => 'inactive',
            'must_change_password' => false,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(UserRole::Student);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', 'Your account has been deactivated. Please contact an administrator.');
        $this->assertGuest();
    }

    public function test_deactivated_user_json_request_is_aborted_with_403(): void
    {
        $user = User::create([
            'name' => 'Banned User',
            'email' => 'banned.json@student.pte.hu',
            'password' => 'secret_hash',
            'status' => 'inactive',
            'must_change_password' => false,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(UserRole::Student);

        $response = $this->actingAs($user)->getJson('/');

        $response->assertForbidden();
        $this->assertGuest();
    }
}
