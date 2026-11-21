<?php

namespace App\Filament\Pages;

use App\Exceptions\EventActionRefused;
use App\Models\Event;
use App\Models\EventApplication;
use App\Services\EventModerator;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Event moderation (Task #58, UC-3.5): every event in the system, whoever runs it. The system admin
 * finds one by title, area or organizer and puts it on hold, cancels it or removes it, each with a
 * reason. Removing reverses the points it gave (EventModerator).
 */
class EventModeration extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.event-moderation';

    public static function getNavigationLabel(): string
    {
        return __('admin.event_moderation.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.event_moderation.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('admin.event_moderation.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Event::query()
                ->with(['owningTopic:id,title', 'creator:id,name', 'removedBy:id,name'])
                ->withCount(['applications as open_applications_count' => fn (Builder $query) => $query
                    ->whereIn('status', [EventApplication::PENDING, EventApplication::ACCEPTED, EventApplication::WAITLISTED])])
                // Credits less reversals: what the event gives students now.
                ->withSum('pointTransactions as points_given', 'points'))
            ->modelLabel(__('admin.models.event'))
            ->pluralModelLabel(__('admin.models.events'))
            ->defaultSort('event_starts_at', 'desc')
            ->searchPlaceholder(__('admin.event_moderation.search_placeholder'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.event_moderation.event'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('owningTopic.title')
                    ->label(__('admin.event_moderation.area'))
                    ->searchable(),

                TextColumn::make('creator.name')
                    ->label(__('admin.event_moderation.organizer'))
                    ->searchable(),

                TextColumn::make('status')
                    ->label(__('admin.event_moderation.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $this->statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Event::STATUS_PUBLISHED => 'success',
                        Event::STATUS_ON_HOLD => 'warning',
                        Event::STATUS_CANCELLED, Event::STATUS_REMOVED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('event_starts_at')
                    ->label(__('admin.event_moderation.starts'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('open_applications_count')
                    ->label(__('admin.event_moderation.applicants'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('points_given')
                    ->label(__('admin.event_moderation.points_given'))
                    ->state(fn (Event $record): int => (int) $record->points_given)
                    ->numeric()
                    ->sortable(),

                TextColumn::make('reason')
                    ->label(__('admin.event_moderation.reason'))
                    ->state(fn (Event $record): ?string => match ($record->status) {
                        Event::STATUS_REMOVED => $record->removal_reason,
                        Event::STATUS_CANCELLED => $record->cancellation_reason,
                        default => null,
                    })
                    ->description(fn (Event $record): ?string => $record->isRemoved() && $record->removed_at !== null
                        ? __('admin.event_moderation.removed_by_on', ['name' => $record->removedBy?->name ?? '—', 'date' => $record->removed_at->translatedFormat('j M Y')])
                        : null)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.event_moderation.status'))
                    ->options($this->statusLabels()),
            ])
            // One menu for the three actions: the table is wide, and three buttons would push the
            // last of them out of sight.
            ->recordActions([
                ActionGroup::make([
                    Action::make('holdEvent')
                        ->label(__('admin.event_moderation.hold'))
                        ->icon(Heroicon::OutlinedPauseCircle)
                        ->color('warning')
                        ->visible(fn (Event $record): bool => app(EventModerator::class)->canHold($record))
                        ->modalHeading(fn (Event $record): string => __('admin.event_moderation.hold_heading', ['title' => $record->title]))
                        ->modalDescription(__('admin.event_moderation.hold_help'))
                        ->modalSubmitActionLabel(__('admin.event_moderation.hold'))
                        ->form([$this->reasonField()])
                        ->action(function (Event $record, array $data): void {
                            if ($this->attempt(fn () => app(EventModerator::class)->hold($record, Auth::user(), $data['reason'])) !== null) {
                                Notification::make()->title(__('events.status.title_on_hold', ['title' => $record->title]))->success()->send();
                            }
                        }),

                    Action::make('cancelEvent')
                        ->label(__('admin.event_moderation.cancel'))
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->visible(fn (Event $record): bool => app(EventModerator::class)->canCancel($record))
                        ->modalHeading(fn (Event $record): string => __('admin.event_moderation.cancel_heading', ['title' => $record->title]))
                        ->modalDescription(__('admin.event_moderation.cancel_help'))
                        ->modalSubmitActionLabel(__('admin.event_moderation.cancel'))
                        ->form([$this->reasonField()])
                        ->action(function (Event $record, array $data): void {
                            if ($this->attempt(fn () => app(EventModerator::class)->cancel($record, Auth::user(), $data['reason'])) !== null) {
                                Notification::make()->title(__('events.status.title_cancelled', ['title' => $record->title]))->success()->send();
                            }
                        }),

                    Action::make('removeEvent')
                        ->label(__('admin.event_moderation.remove'))
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->visible(fn (Event $record): bool => app(EventModerator::class)->canRemove($record))
                        ->modalIcon(Heroicon::OutlinedTrash)
                        ->modalHeading(fn (Event $record): string => __('admin.event_moderation.remove_heading', ['title' => $record->title]))
                        ->modalDescription(fn (Event $record): string => $this->removalWarning($record))
                        ->modalSubmitActionLabel(__('admin.event_moderation.remove'))
                        ->form([$this->reasonField()])
                        ->action(function (Event $record, array $data): void {
                            $taken = $this->attempt(fn () => app(EventModerator::class)->remove($record, Auth::user(), $data['reason']));

                            if ($taken !== null) {
                                Notification::make()
                                    ->title(__('admin.event_moderation.title_removed', ['title' => $record->title]))
                                    ->body(trans_choice('admin.event_moderation.removed_credits', $taken['credits'], ['points' => $taken['points']]))
                                    ->success()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * The reason is asked for every time: it is kept in the audit log and told to the organizer.
     */
    private function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label(__('admin.event_moderation.reason'))
            ->helperText(__('admin.event_moderation.reason_help'))
            ->required()
            ->maxLength(EventModerator::MAX_REASON)
            ->rows(3);
    }

    /**
     * What removing does, with the numbers of this event, shown before the admin confirms.
     */
    private function removalWarning(Event $event): string
    {
        $impact = app(EventModerator::class)->impact($event);

        return __('admin.event_moderation.remove_warning', ['title' => $event->title])
            .' '.trans_choice('admin.event_moderation.remove_credits', $impact['credits'], ['points' => $impact['points']]);
    }

    /**
     * Runs a change, and tells the admin when a rule refused it (nothing is changed then).
     * Returns what the change returned, or null when it was refused.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult|null
     */
    private function attempt(Closure $change): mixed
    {
        try {
            return $change();
        } catch (EventActionRefused $refused) {
            Notification::make()->title(__('admin.event_moderation.nothing_changed'))->danger()->body($refused->getMessage())->send();

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            Event::STATUS_DRAFT => __('event_status.draft'),
            Event::STATUS_PUBLISHED => __('event_status.published'),
            Event::STATUS_ON_HOLD => __('event_status.on_hold'),
            Event::STATUS_COMPLETED => __('event_status.completed'),
            Event::STATUS_CANCELLED => __('event_status.cancelled'),
            Event::STATUS_REMOVED => __('event_status.removed'),
        ];
    }
}
