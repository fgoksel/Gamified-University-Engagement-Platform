<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the initial System Administrator (Technical Specification 6.4).
 *
 * Login details come from ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD in .env.
 * The password is temporary: must_change_password forces a new one at first
 * login. Running the seeder again never resets an existing admin's password.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('app.admin.password');

        if (blank($password)) {
            if (app()->isProduction()) {
                throw new RuntimeException('Set ADMIN_PASSWORD in .env before seeding the production database.');
            }

            $password = 'password';
        }

        $admin = User::firstOrCreate(
            ['email' => config('app.admin.email')],
            [
                'name' => config('app.admin.name'),
                'password' => $password,
                'status' => 'active',
                'must_change_password' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole(UserRole::Admin);
    }
}
