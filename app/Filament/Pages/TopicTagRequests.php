<?php

namespace App\Filament\Pages;

use App\Enums\TaxonomyCategory;
use App\Models\TopicTagRequest;
use App\Services\TopicTagRequestService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Topic Tag Requests Queue — UC-1.9 & UC-3.4.
 * System Administrators review teacher-submitted topic tag requests (Approve / Reject).
 */
class TopicTagRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Tag Requests';

    protected static ?string $title = 'Topic Tag Requests Queue';

    protected static ?string $slug = 'topic-tag-requests';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.topic-tag-requests';

    public static function getNavigationBadge(): ?string
    {
        $count = TopicTagRequest::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(TopicTagRequest::query()->with(['requestedBy', 'resolvedBy'])->latest())
            ->columns([
                TextColumn::make('proposed_name')
                    ->label('Proposed Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TaxonomyCategory::tryFrom($state)?->label() ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'academic' => 'info',
                        'scientific' => 'primary',
                        'sports' => 'warning',
                        'community' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('justification')
                    ->label('Justification')
                    ->limit(50)
                    ->searchable(),

                TextColumn::make('requestedBy.name')
                    ->label('Requested By')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('requestedBy.email')
                    ->label('Teacher Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('resolvedBy.name')
                    ->label('Resolved By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),

                SelectFilter::make('category')
                    ->label('Category')
                    ->options(TaxonomyCategory::options()),
            ])
            ->actions([
                // UC-3.4: Approve action
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Topic Tag Request')
                    ->modalDescription(fn (TopicTagRequest $record): string => "Approve \"{$record->proposed_name}\"? A new active topic tag will be created and {$record->requestedBy?->name} will be notified.")
                    ->modalSubmitActionLabel('Yes, Approve')
                    ->visible(fn (TopicTagRequest $record): bool => $record->status === 'pending')
                    ->action(function (TopicTagRequest $record): void {
                        try {
                            app(TopicTagRequestService::class)->approve($record, auth()->user());

                            Notification::make()
                                ->title('Topic Tag Request Approved')
                                ->success()
                                ->body("Topic tag \"{$record->proposed_name}\" is now live and {$record->requestedBy?->name} has been notified.")
                                ->send();
                        } catch (ValidationException $e) {
                            $message = $e->validator->errors()->first('proposed_name')
                                ?: $e->validator->errors()->first('status')
                                ?: 'Could not approve request.';

                            Notification::make()
                                ->title('Approval Not Allowed')
                                ->danger()
                                ->body($message)
                                ->send();
                        }
                    }),

                // UC-3.4: Reject action
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->modalHeading('Reject Topic Tag Request')
                    ->modalDescription(fn (TopicTagRequest $record): string => "Reject request for \"{$record->proposed_name}\"? State an optional reason to explain why to {$record->requestedBy?->name}.")
                    ->modalSubmitActionLabel('Reject Request')
                    ->visible(fn (TopicTagRequest $record): bool => $record->status === 'pending')
                    ->form([
                        Textarea::make('reason')
                            ->label('Rejection Reason')
                            ->placeholder('e.g. A similar topic tag already exists in the Academic category.')
                            ->rows(3)
                            ->nullable(),
                    ])
                    ->action(function (TopicTagRequest $record, array $data): void {
                        try {
                            app(TopicTagRequestService::class)->reject(
                                $record,
                                auth()->user(),
                                $data['reason'] ?? null
                            );

                            Notification::make()
                                ->title('Topic Tag Request Rejected')
                                ->warning()
                                ->body("The request has been marked as rejected and {$record->requestedBy?->name} has been notified.")
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Action Failed')
                                ->danger()
                                ->body($e->validator->errors()->first() ?: 'Could not reject request.')
                                ->send();
                        }
                    }),
            ]);
    }
}
