<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SemesterManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Semester';

    protected static ?string $title = 'Semester management';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.coming-soon';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => 'Control the semester cycle that points and leaderboards are based on.',
            'plannedFeatures' => [
                'View the settings of the current semester',
                'Close the semester (archive and reset points)',
                'Start a new semester',
            ],
        ];
    }
}
