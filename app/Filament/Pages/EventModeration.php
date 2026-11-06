<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class EventModeration extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.coming-soon';

    public static function getNavigationLabel(): string
    {
        return __('admin.event_moderation.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.event_moderation.title');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'description' => __('admin.event_moderation.oversee_every_event'),
            'plannedFeatures' => [
                __('admin.event_moderation.search_all_events'),
                __('admin.event_moderation.delete_problematic_events'),
                __('admin.event_moderation.review_and_reverse_incorrect_point'),
            ],
        ];
    }
}
