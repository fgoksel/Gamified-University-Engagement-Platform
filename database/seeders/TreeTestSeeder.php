<?php

namespace Database\Seeders;

use App\Enums\TreeUnitKind;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Semester;
use App\Models\TreeUnit;
use App\Models\User;
use App\Services\TreeService;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Accounts for trying out the faculty tree on your own computer.
 *
 * Not part of DatabaseSeeder. Run it by hand:
 *     php artisan db:seed --class=TreeTestSeeder
 *
 * Creates 4 teacher and 5 student accounts, all active, with the password
 * "password" and no forced password change, and makes sure a semester is
 * active. Existing users are never changed: an account whose email or
 * Neptun code is already taken is skipped.
 *
 * Then builds the "Faculty of Informatics" tree with dean@test.local as dean
 * and the courses of data/enrolments-test.csv as courses, unless they are
 * there already. Teachers and students are added by hand on "My courses",
 * and students also by importing data/enrolments-test.csv in the admin panel
 * (Trees > Import enrolments).
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

    public const TREE = 'Faculty of Informatics';

    /**
     * The courses of data/enrolments-test.csv, course code => name.
     *
     * @var array<string, string>
     */
    public const COURSES = [
        'IT-DB101' => 'Database',
        'IT-PR101' => 'Programming',
        'IT-NW101' => 'Networks',
    ];

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

        $this->buildTree();
    }

    /**
     * The test tree and its courses, created through TreeService so the
     * tree rules apply. Nothing that already exists is changed.
     */
    private function buildTree(): void
    {
        $admin = User::role(UserRole::Admin)->first();
        $dean = User::where('email', array_key_first(self::TEACHERS))->first();

        if ($admin === null || $dean === null) {
            $this->command?->warn('Skipped the test tree: it needs an admin account and dean@test.local.');

            return;
        }

        $tree = app(TreeService::class);

        try {
            $root = TreeUnit::where('kind', TreeUnitKind::Root)->where('title', self::TREE)->first()
                ?? $tree->createTree($admin, self::TREE, $dean);

            foreach (self::COURSES as $code => $name) {
                $course = Course::firstOrCreate(['code' => $code], ['name' => $name]);

                if ($course->courseUnit()->doesntExist()) {
                    $tree->addCourse($admin, $root, $course);
                }
            }
        } catch (ValidationException $e) {
            $this->command?->warn('Skipped part of the test tree: '.collect($e->errors())->flatten()->first());
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
