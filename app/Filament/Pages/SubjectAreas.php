<?php

namespace App\Filament\Pages;

use App\Enums\TaxonomyCategory;
use App\Models\Faculty;
use App\Models\SubjectArea;
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
 * Subject Areas Management — UC-3.4 (Announcing new subject areas, maintaining existing ones).
 */
class SubjectAreas extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $slug = 'subject-areas';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.subject-areas';

    public static function getNavigationLabel(): string
    {
        return __('admin.subject_areas.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.subject_areas.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SubjectArea::query()->with('faculty')->latest())
            ->modelLabel(__('admin.models.subject_area'))
            ->pluralModelLabel(__('admin.models.subject_areas'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.subject_areas.title_2'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label(__('admin.subject_areas.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('admin.subject_areas.category'))
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

                TextColumn::make('faculty.name')
                    ->label(__('admin.subject_areas.faculty'))
                    ->placeholder(__('admin.subject_areas.university_wide'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label(__('admin.subject_areas.status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('admin.subject_areas.active') : __('admin.subject_areas.inactive'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('admin.subject_areas.registration_date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('admin.subject_areas.category'))
                    ->options(TaxonomyCategory::options()),

                SelectFilter::make('faculty_id')
                    ->label(__('admin.subject_areas.faculty'))
                    ->options(fn (): array => Faculty::query()->pluck('name', 'id')->all()),

                SelectFilter::make('is_active')
                    ->label(__('admin.subject_areas.status'))
                    ->options([
                        '1' => __('admin.subject_areas.active'),
                        '0' => __('admin.subject_areas.inactive'),
                    ]),
            ])
            ->actions([
                // Edit action (pencil icon) per UC-3.4
                Action::make('edit')
                    ->label(__('admin.subject_areas.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(__('admin.subject_areas.edit_subject_area'))
                    ->modalSubmitActionLabel(__('admin.subject_areas.save_changes'))
                    ->fillForm(fn (SubjectArea $record): array => [
                        'title' => $record->title,
                        'code' => $record->code,
                        'category' => $record->category,
                        'faculty_id' => $record->faculty_id,
                        'description' => $record->description,
                    ])
                    ->form([
                        TextInput::make('title')
                            ->label(__('admin.subject_areas.title_2'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('code')
                            ->label(__('admin.subject_areas.code'))
                            ->required()
                            ->maxLength(50)
                            ->unique(
                                table: SubjectArea::class,
                                column: 'code',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => __('admin.subject_areas.a_subject_area'),
                            ]),

                        Select::make('category')
                            ->label(__('admin.subject_areas.category'))
                            ->options(TaxonomyCategory::options())
                            ->required(),

                        Select::make('faculty_id')
                            ->label(__('admin.subject_areas.assigned_faculty_department'))
                            ->options(fn (): array => Faculty::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->nullable()
                            ->exists(table: Faculty::class, column: 'id'),

                        Textarea::make('description')
                            ->label(__('admin.subject_areas.description'))
                            ->rows(3)
                            ->nullable(),
                    ])
                    ->action(function (SubjectArea $record, array $data): void {
                        $record->update([
                            'title' => $data['title'],
                            'code' => strtoupper($data['code']),
                            'category' => $data['category'],
                            'faculty_id' => $data['faculty_id'] ?? null,
                            'description' => $data['description'] ?? null,
                        ]);

                        Notification::make()
                            ->title(__('admin.subject_areas.subject_area_updated'))
                            ->success()
                            ->body(__('admin.subject_areas.title_details_have_been_successfully', ['title' => $record->title]))
                            ->send();
                    }),

                // Deactivate action (lock icon) per UC-3.4
                Action::make('deactivate')
                    ->label(__('admin.subject_areas.deactivate'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.subject_areas.deactivate_subject_area'))
                    ->modalDescription(fn (SubjectArea $record): string => __('admin.subject_areas.are_you_sure_you_want', ['title' => $record->title]))
                    ->modalSubmitActionLabel(__('admin.subject_areas.yes_deactivate'))
                    ->visible(fn (SubjectArea $record): bool => (bool) $record->is_active)
                    ->action(function (SubjectArea $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->title(__('admin.subject_areas.subject_area_deactivated'))
                            ->warning()
                            ->body(__('admin.subject_areas.title_has_been_archived', ['title' => $record->title]))
                            ->send();
                    }),

                // Activate action (checkmark icon) per UC-3.4
                Action::make('activate')
                    ->label(__('admin.subject_areas.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.subject_areas.activate_subject_area'))
                    ->modalDescription(fn (SubjectArea $record): string => __('admin.subject_areas.activate_title_it_will_become', ['title' => $record->title]))
                    ->modalSubmitActionLabel(__('admin.subject_areas.yes_activate'))
                    ->visible(fn (SubjectArea $record): bool => ! (bool) $record->is_active)
                    ->action(function (SubjectArea $record): void {
                        $record->update(['is_active' => true]);

                        Notification::make()
                            ->title(__('admin.subject_areas.subject_area_activated'))
                            ->success()
                            ->body(__('admin.subject_areas.title_has_been_activated', ['title' => $record->title]))
                            ->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Add New Subject Area button and modal per UC-3.4
            Action::make('create')
                ->label(__('admin.subject_areas.add_new_subject_area'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('admin.subject_areas.add_new_subject_area'))
                ->modalDescription(__('admin.subject_areas.create_a_university_subject_area'))
                ->modalSubmitActionLabel(__('admin.subject_areas.save_subject_area'))
                ->form([
                    TextInput::make('title')
                        ->label(__('admin.subject_areas.title_2'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('code')
                        ->label(__('admin.subject_areas.code'))
                        ->required()
                        ->maxLength(50)
                        ->unique(
                            table: SubjectArea::class,
                            column: 'code',
                        )
                        ->validationMessages([
                            'unique' => __('admin.subject_areas.a_subject_area'),
                        ]),

                    Select::make('category')
                        ->label(__('admin.subject_areas.category'))
                        ->options(TaxonomyCategory::options())
                        ->required(),

                    Select::make('faculty_id')
                        ->label(__('admin.subject_areas.assigned_faculty_department'))
                        ->options(fn (): array => Faculty::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->nullable()
                        ->exists(table: Faculty::class, column: 'id'),

                    Textarea::make('description')
                        ->label(__('admin.subject_areas.description'))
                        ->rows(3)
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    $record = SubjectArea::create([
                        'title' => $data['title'],
                        'code' => strtoupper($data['code']),
                        'category' => $data['category'],
                        'faculty_id' => $data['faculty_id'] ?? null,
                        'description' => $data['description'] ?? null,
                        'is_active' => true,
                    ]);

                    Notification::make()
                        ->title(__('admin.subject_areas.subject_area_created'))
                        ->success()
                        ->body(__('admin.subject_areas.subject_area_title_code_has', ['title' => $record->title, 'code' => $record->code]))
                        ->send();
                }),
        ];
    }
}
