<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Runs the full seeder on an empty database, like "php artisan migrate --seed".
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_roles_and_an_admin_login(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, Role::count());
        $this->assertTrue(User::where('email', 'test@example.com')->firstOrFail()->hasRole(UserRole::Admin));
    }
}
