<?php

namespace Database\Seeders;

use App\Models\Semester;
use Illuminate\Database\Seeder;

/**
 * Creates the initial active semester (Technical Specification 6.4).
 *
 * Skipped when an active semester already exists, because only one
 * semester may be active at a time (Technical Specification 6.2.6).
 */
class SemesterSeeder extends Seeder
{
    public function run(): void
    {
        if (Semester::where('status', 'active')->exists()) {
            return;
        }

        Semester::firstOrCreate(
            ['name' => '2026/2027 Fall Semester'],
            [
                'starts_at' => '2026-09-07',
                'ends_at' => '2027-01-31',
                'status' => 'active',
            ],
        );
    }
}
