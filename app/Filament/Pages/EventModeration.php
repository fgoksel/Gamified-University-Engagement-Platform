<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class EventModeration extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Event moderation';

    protected static ?string $title = 'Event moderation';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.coming-soon';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => 'Oversee every event in the system, regardless of organizer.',
            'plannedFeatures' => [
                'Search all events',
                'Delete problematic events with a required reason',
                'Review and reverse incorrect point credits (audit log)',
            ],
        ];
    }
}
