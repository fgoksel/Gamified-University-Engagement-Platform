<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SubjectAreas extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Subject areas';

    protected static ?string $title = 'Subject areas & taxonomy';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.coming-soon';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => 'Maintain the subject areas and the global topic tag taxonomy.',
            'plannedFeatures' => [
                'Create, rename and deactivate subject areas',
                'Manage topic tags in the four categories',
                'Approve or reject topic tag requests from teachers',
            ],
        ];
    }
}
