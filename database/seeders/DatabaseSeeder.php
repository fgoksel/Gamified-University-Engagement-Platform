<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database, in the order of Technical Specification 6.4.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            FacultySeeder::class,
            SemesterSeeder::class,
            SubjectAreaSeeder::class,
            TopicTagSeeder::class,
        ]);
    }
}
