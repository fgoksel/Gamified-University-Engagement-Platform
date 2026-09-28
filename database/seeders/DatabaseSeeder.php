<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles and permissions must exist before any user gets a role.
        $this->call(RolePermissionSeeder::class);

        // Local development login for the admin panel (password: "password").
        // Replaced by AdminUserSeeder in Task #11.
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])->assignRole(UserRole::Admin);
    }
}
