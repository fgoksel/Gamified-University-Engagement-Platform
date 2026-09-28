<?php

namespace Database\Seeders;

use App\Models\Faculty;
use App\Models\SubjectArea;
use Illuminate\Database\Seeder;

/**
 * Creates representative subject areas in all four categories
 * (Technical Specification 6.4). Run FacultySeeder first.
 */
class SubjectAreaSeeder extends Seeder
{
    /**
     * Each entry: code, title, category, faculty code (null = university-wide).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: ?string}>
     */
    public const SUBJECT_AREAS = [
        ['MIK-SWE', 'Software Engineering', 'academic', 'MIK'],
        ['KTK-FIN', 'Finance and Accounting', 'academic', 'KTK'],
        ['TTK-PHY', 'Physics Research', 'scientific', 'TTK'],
        ['AOK-MED', 'Medical Research', 'scientific', 'AOK'],
        ['PTE-FOOTBALL', 'Football', 'sports', null],
        ['PTE-ATHLETICS', 'Athletics', 'sports', null],
        ['PTE-VOLUNTEER', 'Volunteering', 'community', null],
        ['BTK-CULTURE', 'Cultural Events', 'community', 'BTK'],
    ];

    public function run(): void
    {
        $faculties = Faculty::pluck('id', 'code');

        foreach (self::SUBJECT_AREAS as [$code, $title, $category, $facultyCode]) {
            SubjectArea::firstOrCreate(
                ['code' => $code],
                [
                    'title' => $title,
                    'category' => $category,
                    'faculty_id' => $facultyCode ? $faculties->get($facultyCode) : null,
                    'is_active' => true,
                ],
            );
        }
    }
}
