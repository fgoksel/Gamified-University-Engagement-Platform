<?php

namespace App\Filament\Pages;

use App\Enums\TaxonomyCategory;
use App\Models\TopicTag;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Global Topic Tag Taxonomy Management — UC-3.4.
 * Faculty-independent tags categorized under the four category trees.
 */
class TopicTags extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $slug = 'topic-tags';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.topic-tags';

    public static function getNavigationLabel(): string
    {
        return __('admin.topic_tags.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.topic_tags.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(TopicTag::query()->latest())
            ->modelLabel(__('admin.models.topic_tag'))
            ->pluralModelLabel(__('admin.models.topic_tags'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.topic_tags.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('admin.topic_tags.category'))
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

                TextColumn::make('description')
                    ->label(__('admin.topic_tags.description'))
                    ->limit(60)
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('is_active')
                    ->label(__('admin.topic_tags.status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('admin.topic_tags.active') : __('admin.topic_tags.inactive'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('admin.topic_tags.registration_date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('admin.topic_tags.category'))
                    ->options(TaxonomyCategory::options()),

                SelectFilter::make('is_active')
                    ->label(__('admin.topic_tags.status'))
                    ->options([
                        '1' => __('admin.topic_tags.active'),
                        '0' => __('admin.topic_tags.inactive'),
                    ]),
            ])
            ->actions([
                // Edit action (pencil icon) per UC-3.4
                Action::make('edit')
                    ->label(__('admin.topic_tags.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(__('admin.topic_tags.edit_topic_tag'))
                    ->modalSubmitActionLabel(__('admin.topic_tags.save_changes'))
                    ->fillForm(fn (TopicTag $record): array => [
                        'name' => $record->name,
                        'category' => $record->category,
                        'description' => $record->description,
                    ])
                    ->form([
                        TextInput::make('name')
                            ->label(__('admin.topic_tags.name'))
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: TopicTag::class,
                                column: 'name',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => __('admin.topic_tags.a_topic_tag'),
                            ]),

                        Select::make('category')
                            ->label(__('admin.topic_tags.category'))
                            ->options(TaxonomyCategory::options())
                            ->required(),

                        Textarea::make('description')
                            ->label(__('admin.topic_tags.description'))
                            ->rows(3)
                            ->nullable(),
                    ])
                    ->action(function (TopicTag $record, array $data): void {
                        $record->update([
                            'name' => $data['name'],
                            'category' => $data['category'],
                            'description' => $data['description'] ?? null,
                        ]);

                        Notification::make()
                            ->title(__('admin.topic_tags.topic_tag_updated'))
                            ->success()
                            ->body(__('admin.topic_tags.topic_tag_name_details_have', ['name' => $record->name]))
                            ->send();
                    }),

                // Deactivate action (lock icon) per UC-3.4
                Action::make('deactivate')
                    ->label(__('admin.topic_tags.deactivate'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.topic_tags.deactivate_topic_tag'))
                    ->modalDescription(fn (TopicTag $record): string => __('admin.topic_tags.are_you_sure_you_want', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('admin.topic_tags.yes_deactivate'))
                    ->visible(fn (TopicTag $record): bool => (bool) $record->is_active)
                    ->action(function (TopicTag $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->title(__('admin.topic_tags.topic_tag_deactivated'))
                            ->warning()
                            ->body(__('admin.topic_tags.topic_tag_name_has_been', ['name' => $record->name]))
                            ->send();
                    }),

                // Activate action (checkmark icon) per UC-3.4
                Action::make('activate')
                    ->label(__('admin.topic_tags.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.topic_tags.activate_topic_tag'))
                    ->modalDescription(fn (TopicTag $record): string => __('admin.topic_tags.activate_name_it_will_become', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('admin.topic_tags.yes_activate'))
                    ->visible(fn (TopicTag $record): bool => ! (bool) $record->is_active)
                    ->action(function (TopicTag $record): void {
                        $record->update(['is_active' => true]);

                        Notification::make()
                            ->title(__('admin.topic_tags.topic_tag_activated'))
                            ->success()
                            ->body(__('admin.topic_tags.topic_tag_name_has_been_2', ['name' => $record->name]))
                            ->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Add New Topic Tag button and modal per UC-3.4
            Action::make('create')
                ->label(__('admin.topic_tags.add_new_topic_tag'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('admin.topic_tags.add_new_topic_tag'))
                ->modalDescription(__('admin.topic_tags.add_a_global_topic_tag'))
                ->modalSubmitActionLabel(__('admin.topic_tags.save_topic_tag'))
                ->form([
                    TextInput::make('name')
                        ->label(__('admin.topic_tags.name'))
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: TopicTag::class,
                            column: 'name',
                        )
                        ->validationMessages([
                            'unique' => __('admin.topic_tags.a_topic_tag'),
                        ]),

                    Select::make('category')
                        ->label(__('admin.topic_tags.category'))
                        ->options(TaxonomyCategory::options())
                        ->required(),

                    Textarea::make('description')
                        ->label(__('admin.topic_tags.description'))
                        ->rows(3)
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    $record = TopicTag::create([
                        'name' => $data['name'],
                        'category' => $data['category'],
                        'description' => $data['description'] ?? null,
                        'is_active' => true,
                    ]);

                    Notification::make()
                        ->title(__('admin.topic_tags.topic_tag_created'))
                        ->success()
                        ->body(__('admin.topic_tags.topic_tag_name_has_been_3', ['name' => $record->name]))
                        ->send();
                }),
        ];
    }
}
