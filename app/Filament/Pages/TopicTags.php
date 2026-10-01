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

    protected static ?string $navigationLabel = 'Topic Tags';

    protected static ?string $title = 'Topic Tag Taxonomy';

    protected static ?string $slug = 'topic-tags';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.topic-tags';

    public function table(Table $table): Table
    {
        return $table
            ->query(TopicTag::query()->latest())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
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

                TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Registration Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(TaxonomyCategory::options()),

                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->actions([
                // Edit action (pencil icon) per UC-3.4
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit Topic Tag')
                    ->modalSubmitActionLabel('Save Changes')
                    ->fillForm(fn (TopicTag $record): array => [
                        'name' => $record->name,
                        'category' => $record->category,
                        'description' => $record->description,
                    ])
                    ->form([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: TopicTag::class,
                                column: 'name',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => 'A topic tag with this name already exists.',
                            ]),

                        Select::make('category')
                            ->label('Category')
                            ->options(TaxonomyCategory::options())
                            ->required(),

                        Textarea::make('description')
                            ->label('Description')
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
                            ->title('Topic Tag Updated')
                            ->success()
                            ->body("Topic tag {$record->name} details have been successfully updated.")
                            ->send();
                    }),

                // Deactivate action (lock icon) per UC-3.4
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Deactivate Topic Tag')
                    ->modalDescription(fn (TopicTag $record): string => "Are you sure you want to deactivate {$record->name}? Deactivated tags stay on historical events but cannot be picked for new ones.")
                    ->modalSubmitActionLabel('Yes, Deactivate')
                    ->visible(fn (TopicTag $record): bool => (bool) $record->is_active)
                    ->action(function (TopicTag $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->title('Topic Tag Deactivated')
                            ->warning()
                            ->body("Topic tag {$record->name} has been deactivated.")
                            ->send();
                    }),

                // Activate action (checkmark icon) per UC-3.4
                Action::make('activate')
                    ->label('Activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Activate Topic Tag')
                    ->modalDescription(fn (TopicTag $record): string => "Activate {$record->name}? It will become available again for selection in new events.")
                    ->modalSubmitActionLabel('Yes, Activate')
                    ->visible(fn (TopicTag $record): bool => ! (bool) $record->is_active)
                    ->action(function (TopicTag $record): void {
                        $record->update(['is_active' => true]);

                        Notification::make()
                            ->title('Topic Tag Activated')
                            ->success()
                            ->body("Topic tag {$record->name} has been activated.")
                            ->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Add New Topic Tag button and modal per UC-3.4
            Action::make('create')
                ->label('Add New Topic Tag')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Add New Topic Tag')
                ->modalDescription('Add a global topic tag to the university engagement taxonomy.')
                ->modalSubmitActionLabel('Save Topic Tag')
                ->form([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: TopicTag::class,
                            column: 'name',
                        )
                        ->validationMessages([
                            'unique' => 'A topic tag with this name already exists.',
                        ]),

                    Select::make('category')
                        ->label('Category')
                        ->options(TaxonomyCategory::options())
                        ->required(),

                    Textarea::make('description')
                        ->label('Description')
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
                        ->title('Topic Tag Created')
                        ->success()
                        ->body("Topic tag {$record->name} has been created successfully.")
                        ->send();
                }),
        ];
    }
}
