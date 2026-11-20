<?php

namespace App\Filament\Pages;

use App\Exceptions\EventActionRefused;
use App\Models\PointReset;
use App\Services\PointLedger;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * "Reset all points" (Task #102, UC-6.4): the system admin starts every total and every
 * leaderboard from zero, by hand; nothing resets on its own (27 October, L3 and S4). The reset
 * asks to type a word, and the page lists every reset with who did it and when.
 *
 * "Remove all badges", the other button of #102, comes once badges exist (#103).
 */
class PointsReset extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $slug = 'points-reset';

    protected string $view = 'filament.pages.points-reset';

    public static function getNavigationLabel(): string
    {
        return __('admin.points_reset.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.points_reset.title');
    }

    public function getSubheading(): string
    {
        $last = PointReset::query()->with('resetBy:id,name')->latest('id')->first();

        if ($last === null) {
            return __('admin.points_reset.never');
        }

        return __('admin.points_reset.counting_since', [
            'date' => $last->created_at->translatedFormat('j M Y, H:i'),
            'name' => $last->resetBy->name,
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PointReset::query()->with('resetBy:id,name')->latest('id'))
            ->modelLabel(__('admin.points_reset.model'))
            ->pluralModelLabel(__('admin.points_reset.models'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.points_reset.when'))
                    ->dateTime('j M Y, H:i'),

                TextColumn::make('resetBy.name')
                    ->label(__('admin.points_reset.by')),
            ])
            ->emptyStateHeading(__('admin.points_reset.empty'))
            ->emptyStateDescription(__('admin.points_reset.empty_help'));
    }

    protected function getHeaderActions(): array
    {
        $word = __('admin.points_reset.word');

        return [
            Action::make('resetAllPoints')
                ->label(__('admin.points_reset.reset_all'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('danger')
                ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                ->modalIconColor('danger')
                ->modalHeading(__('admin.points_reset.confirm_heading'))
                ->modalDescription(__('admin.points_reset.confirm_description'))
                ->modalSubmitActionLabel(__('admin.points_reset.reset_all'))
                ->form([
                    TextInput::make('confirmation')
                        ->label(__('admin.points_reset.type_word', ['word' => $word]))
                        ->required()
                        ->autocomplete(false)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($word) {
                            // Capitals or not, but the whole word: a stray click or Enter never resets.
                            if (mb_strtolower(trim((string) $value)) !== mb_strtolower($word)) {
                                $fail(__('admin.points_reset.type_word_exactly', ['word' => $word]));
                            }
                        }),
                ])
                ->action(function (): void {
                    try {
                        app(PointLedger::class)->resetAll(Auth::user());
                    } catch (EventActionRefused $refused) {
                        Notification::make()->title(__('admin.points_reset.not_reset'))->body($refused->getMessage())->warning()->send();

                        return;
                    }

                    Notification::make()->title(__('admin.points_reset.done'))->body(__('admin.points_reset.done_help'))->success()->send();
                }),
        ];
    }
}
