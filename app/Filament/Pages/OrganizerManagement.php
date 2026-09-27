<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class OrganizerManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Organizers';

    protected static ?string $title = 'Organizer management';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.coming-soon';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => 'Manage the teachers, demonstrators and student-teachers who announce events.',
            'plannedFeatures' => [
                'List organizers with their status (active / inactive)',
                'Invite a new organizer by email',
                'Edit organizer details',
                'Revoke access (ban) and resend invitations',
            ],
        ];
    }
}
