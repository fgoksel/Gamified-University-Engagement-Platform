<?php

namespace App\Filament\Pages;

use App\Enums\TreeUnitKind;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * One whole tree for the admin: the dean, every course and subtopic, and
 * the people active on each unit. Opened from the Trees list.
 */
class TreeDetail extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'trees/view';

    protected string $view = 'filament.pages.tree-detail';

    #[Url]
    public ?int $tree = null;

    public TreeUnit $root;

    public function mount(): void
    {
        $this->root = TreeUnit::where('kind', TreeUnitKind::Root)->findOrFail($this->tree);
    }

    public function getTitle(): string
    {
        return $this->root->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('All trees')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Trees::getUrl()),

            Trees::addCourseAction(fn (): TreeUnit => $this->root),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $units = TreeUnit::within($this->root)->with('course')->orderBy('title')->get();

        return [
            'children' => $units->groupBy('parent_id'),
            'members' => UnitMembership::query()
                ->active()
                ->whereIn('unit_id', $units->pluck('id'))
                ->with('user')
                ->get()
                ->groupBy('unit_id'),
        ];
    }
}
