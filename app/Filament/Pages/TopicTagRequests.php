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

    protected static ?string $slug = 'topic-tag-requests';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.topic-tag-requests';

    public static function getNavigationLabel(): string
    {
        return __('admin.tag_requests.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.tag_requests.title');
    }

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
            ->modelLabel(__('admin.models.topic_tag_request'))
            ->pluralModelLabel(__('admin.models.topic_tag_requests'))
            ->columns([
                TextColumn::make('proposed_name')
                    ->label(__('admin.tag_requests.proposed_name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('admin.tag_requests.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TaxonomyCategory::tryFrom($state)?->label() ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'academic' => 'info',
                        'scientific' => 'primary',
                        'sports' => 'warning',
                        'community' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('justification')
                    ->label(__('admin.tag_requests.justification'))
                    ->limit(50)
                    ->searchable(),

                TextColumn::make('requestedBy.name')
                    ->label(__('admin.tag_requests.requested'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('requestedBy.email')
                    ->label(__('admin.tag_requests.teacher_email'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label(__('admin.tag_requests.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => __('admin.status.pending'),
                        'approved' => __('admin.status.approved'),
                        'rejected' => __('admin.status.rejected'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('resolvedBy.name')
                    ->label(__('admin.tag_requests.resolved'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rejection_reason')
                    ->label(__('admin.tag_requests.rejection_reason'))
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('admin.tag_requests.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.tag_requests.status'))
                    ->options([
                        'pending' => __('admin.tag_requests.pending'),
                        'approved' => __('admin.tag_requests.approved'),
                        'rejected' => __('admin.tag_requests.rejected'),
                    ]),

                SelectFilter::make('category')
                    ->label(__('admin.tag_requests.category'))
                    ->options(TaxonomyCategory::options()),
            ])
            ->actions([
                // UC-3.4: Approve action
                Action::make('approve')
                    ->label(__('admin.tag_requests.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.tag_requests.approve_topic_tag_request'))
                    ->modalDescription(fn (TopicTagRequest $record): string => __('admin.tag_requests.approve_proposed_name_a_new', ['proposed_name' => $record->proposed_name, 'name' => $record->requestedBy?->name]))
                    ->modalSubmitActionLabel(__('admin.tag_requests.yes_approve'))
                    ->visible(fn (TopicTagRequest $record): bool => $record->status === 'pending')
                    ->action(function (TopicTagRequest $record): void {
                        try {
                            app(TopicTagRequestService::class)->approve($record, auth()->user());

                            Notification::make()
                                ->title(__('admin.tag_requests.topic_tag_request_approved'))
                                ->success()
                                ->body(__('admin.tag_requests.topic_tag_proposed_name', ['proposed_name' => $record->proposed_name, 'name' => $record->requestedBy?->name]))
                                ->send();
                        } catch (ValidationException $e) {
                            $message = $e->validator->errors()->first('proposed_name')
                                ?: $e->validator->errors()->first('status')
                                ?: __('admin.tag_requests.could_not_approve_request');

                            Notification::make()
                                ->title(__('admin.tag_requests.approval_not_allowed'))
                                ->danger()
                                ->body($message)
                                ->send();
                        }
                    }),

                // UC-3.4: Reject action
                Action::make('reject')
                    ->label(__('admin.tag_requests.reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->modalHeading(__('admin.tag_requests.reject_topic_tag_request'))
                    ->modalDescription(fn (TopicTagRequest $record): string => __('admin.tag_requests.reject_request_for_proposed_name', ['proposed_name' => $record->proposed_name, 'name' => $record->requestedBy?->name]))
                    ->modalSubmitActionLabel(__('admin.tag_requests.reject_request'))
                    ->visible(fn (TopicTagRequest $record): bool => $record->status === 'pending')
                    ->form([
                        Textarea::make('reason')
                            ->label(__('admin.tag_requests.rejection_reason'))
                            ->placeholder(__('admin.tag_requests.e_g_a_similar_topic'))
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
                                ->title(__('admin.tag_requests.topic_tag_request_rejected'))
                                ->warning()
                                ->body(__('admin.tag_requests.the_request_has_been_marked', ['name' => $record->requestedBy?->name]))
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title(__('admin.tag_requests.action_failed'))
                                ->danger()
                                ->body($e->validator->errors()->first() ?: __('admin.tag_requests.could_not_reject_request'))
                                ->send();
                        }
                    }),
            ]);
    }
}
