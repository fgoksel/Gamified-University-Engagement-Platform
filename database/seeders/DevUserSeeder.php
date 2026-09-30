<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Test accounts for trying out login on your own computer (Tasks #14 and #15).
 *
 * Not part of DatabaseSeeder. Run it by hand after the normal seeders:
 *     php artisan db:seed --class=DevUserSeeder
 *
 * Both accounts use the temporary password "password" and must change it at
 * first login, like real users who got a temporary password (UC-2.1 A).
 */
class DevUserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * Email => [name, role].
     *
     * @var array<string, array{0: string, 1: UserRole}>
     */
    public const USERS = [
        'teacher@campusengage.test' => ['Test Teacher', UserRole::Teacher],
        'student@campusengage.test' => ['Test Student', UserRole::Student],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DevUserSeeder must never run in production.');
        }

        foreach (self::USERS as $email => [$name, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => self::PASSWORD,
                    'status' => 'active',
                    'must_change_password' => true,
                    'email_verified_at' => now(),
                    'neptun_code' => $role === UserRole::Student ? 'TEST01' : null,
                ],
            );

            $user->syncRoles([$role]);
        }
    }
}
