<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class StudentManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Students';

    protected static ?string $title = 'Student management';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.coming-soon';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => 'Manage student accounts and their Neptun course enrolments.',
            'plannedFeatures' => [
                'Student directory with status, major, events and points',
                'Invite a single student',
                'Bulk import students from a CSV file',
                'Assign courses taken by students per semester',
            ],
        ];
    }
}
