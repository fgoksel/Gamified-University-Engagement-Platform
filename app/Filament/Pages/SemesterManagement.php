<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SemesterManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.coming-soon';

    public static function getNavigationLabel(): string
    {
        return __('admin.semester.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.semester.title');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => __('admin.semester.control_the_semester_cycle'),
            'plannedFeatures' => [
                __('admin.semester.view_the_settings'),
                __('admin.semester.close_the_semester_archive'),
                __('admin.semester.start_a_new_semester'),
            ],
        ];
    }
}
