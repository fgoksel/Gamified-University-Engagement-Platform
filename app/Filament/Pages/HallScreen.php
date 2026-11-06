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

    protected static ?string $navigationLabel = 'Hall screen';

    protected static ?string $title = 'Hall screen';

    protected static ?string $slug = 'hall-screen';

    protected string $view = 'filament.pages.hall-screen';

    public function table(Table $table): Table
    {
        return $table
            ->query(Leaderboard::query()->inNormalBrowsing()->with('topic:id,title')->orderBy('name')->orderBy('id'))
            ->columns([
                TextColumn::make('name')
                    ->label('Leaderboard')
                    ->searchable(),

                TextColumn::make('topic.title')
                    ->label('Area'),

                TextColumn::make('visibility')
                    ->label('Shown')
                    ->formatStateUsing(fn (string $state): string => $state === Leaderboard::PUBLIC ? 'Published' : 'Area only'),

                TextColumn::make('hall_seconds')
                    ->label('Time on the hall screen')
                    ->placeholder('Default')
                    ->formatStateUsing(fn (int $state): string => "{$state} s"),
            ])
            ->recordActions([
                Action::make('editTime')
                    ->label('Edit time')
                    ->icon(Heroicon::OutlinedClock)
                    ->modalHeading(fn (Leaderboard $record): string => "Time on the hall screen: {$record->name}")
                    ->modalSubmitActionLabel('Save')
                    ->fillForm(fn (Leaderboard $record): array => ['hall_seconds' => $record->hall_seconds])
                    ->form([$this->secondsField()->nullable()->helperText(fn (): string => 'Leave empty to use the default ('.Setting::hallSeconds().' s).')])
                    ->action(function (Leaderboard $record, array $data): void {
                        app(AreaLeaderboards::class)->update(Auth::user(), $record, ['hall_seconds' => $data['hall_seconds'] ?: null]);

                        Notification::make()->title('Time saved')->success()->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('default')
                ->label(fn (): string => 'Default: '.Setting::hallSeconds().' s')
                ->icon(Heroicon::OutlinedClock)
                ->modalHeading('Default time on the hall screen')
                ->modalDescription('Used by every leaderboard that has no time of its own.')
                ->modalSubmitActionLabel('Save')
                ->fillForm(fn (): array => ['hall_seconds' => Setting::hallSeconds()])
                ->form([$this->secondsField()->required()])
                ->action(function (array $data): void {
                    $before = Setting::hallSeconds();
                    $seconds = (int) $data['hall_seconds'];

                    Setting::setHallSeconds($seconds);
                    TopicAudit::record(Auth::user(), 'hall_seconds.default_changed', null, 'setting', 1, ['hall_seconds' => $before], ['hall_seconds' => $seconds]);

                    Notification::make()->title('Default saved')->success()->send();
                }),
        ];
    }

    private function secondsField(): TextInput
    {
        return TextInput::make('hall_seconds')
            ->label('Seconds')
            ->numeric()
            ->integer()
            ->minValue(Leaderboard::HALL_SECONDS_MIN)
            ->maxValue(Leaderboard::HALL_SECONDS_MAX)
            ->validationMessages([
                'min' => Leaderboard::HALL_SECONDS_MESSAGE,
                'max' => Leaderboard::HALL_SECONDS_MESSAGE,
            ]);
    }
}
