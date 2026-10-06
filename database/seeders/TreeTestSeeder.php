<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Accounts for trying out the subject tree on your own computer.
 *
 * Not part of DatabaseSeeder. Run it by hand:
 *     php artisan db:seed --class=TreeTestSeeder
 *
 * Creates 4 teacher and 5 student accounts, all active, with the password
 * "password" and no forced password change, and makes sure a semester is
 * active. Existing users are never changed: an account whose email or
 * Neptun code is already taken is skipped. The tree itself is built by
 * hand in the admin panel and on "My subjects".
 */
class TreeTestSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * Email => name.
     *
     * @var array<string, string>
     */
    public const TEACHERS = [
        'dean@test.local' => 'Test Dean',
        'teacher.a@test.local' => 'Teacher A',
        'teacher.b@test.local' => 'Teacher B',
        'coteacher.c@test.local' => 'Co-teacher C',
    ];

    /**
     * Neptun codes. The email is the lowercase code at test.local.
     *
     * @var list<string>
     */
    public const STUDENTS = ['ABC001', 'ABC002', 'ABC003', 'ABC004', 'ABC005'];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('TreeTestSeeder must never run in production.');
        }

        $this->call([RolePermissionSeeder::class, SemesterSeeder::class]);
        $this->ensureActiveSemester();

        foreach (self::TEACHERS as $email => $name) {
            $this->createAccount($email, $name, UserRole::Teacher);
        }

        foreach (self::STUDENTS as $neptun) {
            $this->createAccount(strtolower($neptun).'@test.local', "Student {$neptun}", UserRole::Student, $neptun);
        }
    }

    /**
     * SemesterSeeder skips its semester when the name already exists, so
     * check that one is really active.
     */
    private function ensureActiveSemester(): void
    {
        if (Semester::where('status', 'active')->exists()) {
            return;
        }

        Semester::create([
            'name' => 'Test semester',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->addMonths(5)->endOfMonth(),
            'status' => 'active',
        ]);
    }

    private function createAccount(string $email, string $name, UserRole $role, ?string $neptun = null): void
    {
        if (User::where('email', $email)->exists()) {
            $this->command?->warn("Skipped {$email}: the account already exists and was not changed.");

            return;
        }

        if ($neptun !== null && User::where('neptun_code', $neptun)->exists()) {
            $this->command?->warn("Skipped {$email}: Neptun code {$neptun} belongs to another account.");

            return;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'neptun_code' => $neptun,
        ])->assignRole($role);
    }
}
