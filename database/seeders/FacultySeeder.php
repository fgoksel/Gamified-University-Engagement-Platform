<?php

namespace Database\Seeders;

use App\Models\Faculty;
use Illuminate\Database\Seeder;

/**
 * Creates the faculties of the University of Pécs (Technical Specification 6.4).
 */
class FacultySeeder extends Seeder
{
    /**
     * Faculty code => English name.
     *
     * @var array<string, string>
     */
    public const FACULTIES = [
        'AJK' => 'Faculty of Law',
        'AOK' => 'Medical School',
        'BTK' => 'Faculty of Humanities and Social Sciences',
        'ETK' => 'Faculty of Health Sciences',
        'GYTK' => 'Faculty of Pharmacy',
        'KPVK' => 'Faculty of Cultural Sciences, Education and Regional Development',
        'KTK' => 'Faculty of Business and Economics',
        'MIK' => 'Faculty of Engineering and Information Technology',
        'MK' => 'Faculty of Music and Visual Arts',
        'TTK' => 'Faculty of Sciences',
    ];

    public function run(): void
    {
        foreach (self::FACULTIES as $code => $name) {
            Faculty::firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
