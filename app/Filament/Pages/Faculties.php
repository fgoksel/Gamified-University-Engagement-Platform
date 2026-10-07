<?php

namespace App\Filament\Pages;

use App\Enums\TreeRole;
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
 * "Courses") and can have one tree, which shows all of those courses.
 */
class Faculties extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Faculties';

    protected static ?string $title = 'Faculties';

    protected static ?string $slug = 'faculties';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.faculties';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Faculty::query()
                    ->withCount('courses')
                    ->with(['tree.memberships' => fn ($query) => $query->active()->where('role', TreeRole::Dean)->with('user')])
                    ->orderBy('name')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Faculty')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('courses_count')
                    ->label('Courses')
                    ->sortable(),

                TextColumn::make('tree')
                    ->label('Tree')
                    ->state(fn (Faculty $record): ?string => $record->tree
                        ? 'Dean: '.($record->tree->memberships->first()?->user->name ?? 'none')
                        : null)
                    ->placeholder('No tree yet')
                    ->badge()
                    ->color(fn (Faculty $record): string => $record->tree ? 'primary' : 'gray'),
            ])
            ->actions([
                Action::make('courses')
                    ->label('Courses')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->url(fn (Faculty $record): string => FacultyCourses::getUrl(['faculty' => $record->id])),

                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit faculty')
                    ->modalSubmitActionLabel('Save Changes')
                    ->fillForm(fn (Faculty $record): array => ['name' => $record->name, 'code' => $record->code])
                    ->form(fn (Faculty $record): array => static::facultyFields($record))
                    ->action(function (Faculty $record, array $data): void {
                        $record->update(['name' => trim($data['name']), 'code' => strtoupper(trim($data['code']))]);

                        Notification::make()->title('Faculty updated')->success()->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New faculty')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('New faculty')
                ->modalSubmitActionLabel('Save faculty')
                ->form(static::facultyFields())
                ->action(function (array $data): void {
                    $faculty = Faculty::create(['name' => trim($data['name']), 'code' => strtoupper(trim($data['code']))]);

                    Notification::make()
                        ->title('Faculty created')
                        ->success()
                        ->body("{$faculty->name} has been created. Open Courses to add its courses.")
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
                ->label('Name')
                ->required()
                ->maxLength(255)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                    $taken = Faculty::query()
                        ->whereRaw('lower(name) = ?', [Str::lower(trim((string) $value))])
                        ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                        ->exists();

                    if ($taken) {
                        $fail('A faculty with this name already exists.');
                    }
                }),

            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(20)
                ->unique(table: Faculty::class, column: 'code', ignorable: $record)
                ->validationMessages(['unique' => 'A faculty with this code already exists.']),
        ];
    }
}
