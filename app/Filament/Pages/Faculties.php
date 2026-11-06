<?php

namespace App\Filament\Pages;

use App\Models\Faculty;
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
use Illuminate\Support\Str;

/**
 * The faculties. Each faculty owns its Neptun courses (opened with
 * "Courses"). Faculties are not part of the topic tree.
 */
class Faculties extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $slug = 'faculties';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.faculties';

    public static function getNavigationLabel(): string
    {
        return __('admin.faculties.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.faculties.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Faculty::query()
                    ->withCount('courses')
                    ->orderBy('name')
            )
            ->modelLabel(__('admin.models.faculty'))
            ->pluralModelLabel(__('admin.models.faculties'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.faculties.faculty'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label(__('admin.faculties.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('courses_count')
                    ->label(__('admin.faculties.courses'))
                    ->sortable(),
            ])
            ->actions([
                Action::make('courses')
                    ->label(__('admin.faculties.courses'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->url(fn (Faculty $record): string => FacultyCourses::getUrl(['faculty' => $record->id])),

                Action::make('edit')
                    ->label(__('admin.faculties.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(__('admin.faculties.edit_faculty'))
                    ->modalSubmitActionLabel(__('admin.faculties.save_changes'))
                    ->fillForm(fn (Faculty $record): array => ['name' => $record->name, 'code' => $record->code])
                    ->form(fn (Faculty $record): array => static::facultyFields($record))
                    ->action(function (Faculty $record, array $data): void {
                        $record->update(['name' => trim($data['name']), 'code' => strtoupper(trim($data['code']))]);

                        Notification::make()->title(__('admin.faculties.faculty_updated'))->success()->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('admin.faculties.new_faculty'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('admin.faculties.new_faculty'))
                ->modalSubmitActionLabel(__('admin.faculties.save_faculty'))
                ->form(static::facultyFields())
                ->action(function (array $data): void {
                    $faculty = Faculty::create(['name' => trim($data['name']), 'code' => strtoupper(trim($data['code']))]);

                    Notification::make()
                        ->title(__('admin.faculties.faculty_created'))
                        ->success()
                        ->body(__('admin.faculties.name_has_been_created_open', ['name' => $faculty->name]))
                        ->send();
                }),
        ];
    }

    /**
     * Name (unique in any letter case) and code (unique).
     *
     * @return list<TextInput>
     */
    protected static function facultyFields(?Faculty $record = null): array
    {
        return [
            TextInput::make('name')
                ->label(__('admin.faculties.name'))
                ->required()
                ->maxLength(255)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                    $taken = Faculty::query()
                        ->whereRaw('lower(name) = ?', [Str::lower(trim((string) $value))])
                        ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('admin.faculties.a_faculty_with_this_name'));
                    }
                }),

            TextInput::make('code')
                ->label(__('admin.faculties.code'))
                ->required()
                ->maxLength(20)
                ->unique(table: Faculty::class, column: 'code', ignorable: $record)
                ->validationMessages(['unique' => __('admin.faculties.a_faculty_with_this_code')]),
        ];
    }
}
