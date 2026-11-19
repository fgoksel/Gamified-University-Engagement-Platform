<?php

namespace App\Filament\Pages;

use App\Models\Leaderboard;
use App\Models\Setting;
use App\Services\AreaLeaderboards;
use App\Services\TopicAudit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Time on the hall screen (Task #56): the default for every leaderboard, and the time of any
 * one leaderboard. Area admins set the time of their own leaderboards in the explorer.
 */
class HallScreen extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTv;

    protected static ?string $slug = 'hall-screen';

    protected string $view = 'filament.pages.hall-screen';

    public static function getNavigationLabel(): string
    {
        return __('admin.hall_screen.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.hall_screen.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Leaderboard::query()->inNormalBrowsing()->with('topic:id,title')->orderBy('name')->orderBy('id'))
            ->modelLabel(__('admin.models.leaderboard'))
            ->pluralModelLabel(__('admin.models.leaderboards'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.hall_screen.leaderboard'))
                    // The name opens the leaderboard itself, and the admin panel stays open.
                    ->url(fn (Leaderboard $record): string => route('leaderboards.index', ['board' => $record->id], false), shouldOpenInNewTab: true)
                    ->searchable(),

                TextColumn::make('topic.title')
                    ->label(__('admin.hall_screen.area')),

                ToggleColumn::make('on_hall_screen')
                    ->label(__('admin.hall_screen.on_hall_screen'))
                    ->getStateUsing(fn (Leaderboard $record): bool => $record->isPublic())
                    ->updateStateUsing(function (Leaderboard $record, bool $state): bool {
                        app(AreaLeaderboards::class)->setPublished(Auth::user(), $record, $state);

                        return $state;
                    }),

                TextColumn::make('hall_seconds')
                    ->label(__('admin.hall_screen.time'))
                    ->placeholder(__('admin.hall_screen.default'))
                    ->formatStateUsing(fn (int $state): string => __('admin.hall_screen.seconds_short', ['seconds' => $state])),
            ])
            ->recordActions([
                Action::make('editTime')
                    ->label(__('admin.hall_screen.edit_time'))
                    ->icon(Heroicon::OutlinedClock)
                    ->modalHeading(fn (Leaderboard $record): string => __('admin.hall_screen.time_of', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('admin.hall_screen.save'))
                    ->fillForm(fn (Leaderboard $record): array => ['hall_seconds' => $record->hall_seconds])
                    ->form([$this->secondsField()->nullable()->helperText(fn (): string => __('admin.hall_screen.leave_empty', ['seconds' => Setting::hallSeconds()]))])
                    ->action(function (Leaderboard $record, array $data): void {
                        app(AreaLeaderboards::class)->update(Auth::user(), $record, ['hall_seconds' => $data['hall_seconds'] ?: null]);

                        Notification::make()->title(__('admin.hall_screen.time_saved'))->success()->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('default')
                ->label(fn (): string => __('admin.hall_screen.default_is', ['seconds' => Setting::hallSeconds()]))
                ->icon(Heroicon::OutlinedClock)
                ->modalHeading(__('admin.hall_screen.default_time'))
                ->modalDescription(__('admin.hall_screen.default_time_help'))
                ->modalSubmitActionLabel(__('admin.hall_screen.save'))
                ->fillForm(fn (): array => ['hall_seconds' => Setting::hallSeconds()])
                ->form([$this->secondsField()->required()])
                ->action(function (array $data): void {
                    $before = Setting::hallSeconds();
                    $seconds = (int) $data['hall_seconds'];

                    Setting::setHallSeconds($seconds);
                    TopicAudit::record(Auth::user(), 'hall_seconds.default_changed', null, 'setting', 1, ['hall_seconds' => $before], ['hall_seconds' => $seconds]);

                    Notification::make()->title(__('admin.hall_screen.default_saved'))->success()->send();
                }),
        ];
    }

    private function secondsField(): TextInput
    {
        return TextInput::make('hall_seconds')
            ->label(__('admin.hall_screen.seconds'))
            ->numeric()
            ->integer()
            ->minValue(Leaderboard::HALL_SECONDS_MIN)
            ->maxValue(Leaderboard::HALL_SECONDS_MAX)
            ->validationMessages([
                'min' => Leaderboard::hallSecondsMessage(),
                'max' => Leaderboard::hallSecondsMessage(),
            ]);
    }
}
