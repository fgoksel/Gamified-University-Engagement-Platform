<?php

namespace App\Filament\Pages;

use App\Imports\CsvRowsImport;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\RoleAssignment;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;

/**
 * The courses of one faculty. This is the only place where courses are
 * created: added one by one or imported from a CSV file.
 */
class FacultyCourses extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'faculties/courses';

    protected string $view = 'filament.pages.faculty-courses';

    #[Url]
    public ?int $faculty = null;

    public Faculty $record;

    public function mount(): void
    {
        $this->record = Faculty::findOrFail($this->faculty);
    }

    public function getTitle(): string
    {
        return "Courses of {$this->record->name}";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Course::query()
                    ->where('faculty_id', $this->record->id)
                    ->with('legacyTopic')
                    ->orderBy('code')
            )
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('students')
                    ->label('Students')
                    ->state(fn (Course $record): int => $record->legacyTopic === null ? 0 : RoleAssignment::query()
                        ->active()
                        ->where('topic_id', $record->legacyTopic->id)
                        ->whereHas('role', fn ($query) => $query->where('legacy_key', 'student'))
                        ->count()),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit course')
                    ->modalSubmitActionLabel('Save Changes')
                    ->fillForm(fn (Course $record): array => ['code' => $record->code, 'name' => $record->name])
                    ->form(fn (Course $record): array => static::courseFields($record))
                    ->action(function (Course $record, array $data): void {
                        $record->update(['code' => strtoupper(trim($data['code'])), 'name' => trim($data['name'])]);

                        Notification::make()->title('Course updated')->success()->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('All faculties')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Faculties::getUrl()),

            Action::make('import')
                ->label('Import courses (CSV)')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalHeading('Import courses (CSV)')
                ->modalDescription('Upload a CSV file with the columns Course Code and Course Name. New courses are added to this faculty; codes that already exist are skipped.')
                ->modalSubmitActionLabel('Import')
                ->form([
                    FileUpload::make('file')
                        ->label('CSV File')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'text/comma-separated-values',
                            'text/x-csv',
                            'application/vnd.ms-excel',
                        ])
                        ->maxFiles(1)
                        ->maxSize(10240)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->importCourses($data['file'])),

            Action::make('create')
                ->label('Add course')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Add course')
                ->modalSubmitActionLabel('Add course')
                ->form(static::courseFields())
                ->action(function (array $data): void {
                    $course = Course::create([
                        'faculty_id' => $this->record->id,
                        'code' => strtoupper(trim($data['code'])),
                        'name' => trim($data['name']),
                    ]);

                    Notification::make()
                        ->title('Course added')
                        ->success()
                        ->body("{$course->code} {$course->name} has been added.")
                        ->send();
                }),
        ];
    }

    /**
     * Read the uploaded file and add every new course to this faculty.
     */
    public function importCourses(string $path): void
    {
        $added = 0;
        $existing = 0;
        $problems = [];

        $columns = Excel::toArray(new HeadingRowImport, $path, 'local', ExcelFormat::CSV)[0][0] ?? [];

        if (array_diff(['course_code', 'course_name'], $columns) !== []) {
            Storage::disk('local')->delete($path);
            Notification::make()->title('Import failed')->danger()->body('Incorrect file structure. The CSV file needs the columns: Course Code, Course Name.')->send();

            return;
        }

        Excel::import(new CsvRowsImport(function (array $row, int $line) use (&$added, &$existing, &$problems) {
            $code = strtoupper((string) ($row['course_code'] ?? ''));
            $name = (string) ($row['course_name'] ?? '');

            if ($code === '' || $name === '') {
                $problems[] = "Row {$line} skipped: Course Code and Course Name are both required.";

                return;
            }

            $course = Course::where('code', $code)->first();

            if ($course === null) {
                Course::create(['faculty_id' => $this->record->id, 'code' => $code, 'name' => $name]);
                $added++;
            } elseif ($course->faculty_id === $this->record->id) {
                $existing++;
            } else {
                $problems[] = "Row {$line} skipped: {$code} belongs to {$course->faculty->name}.";
            }
        }), $path, 'local', ExcelFormat::CSV);

        Storage::disk('local')->delete($path);

        $body = "{$added} courses added. {$existing} were already in this faculty.";

        if ($problems !== []) {
            $body .= ' '.count($problems).' rows skipped. '.implode(' ', array_slice($problems, 0, 5));
        }

        Notification::make()->title('Course import finished')->success()->body($body)->send();
    }

    /**
     * @return list<TextInput>
     */
    protected static function courseFields(?Course $record = null): array
    {
        return [
            TextInput::make('code')
                ->label('Course code')
                ->required()
                ->maxLength(50)
                ->unique(table: Course::class, column: 'code', ignorable: $record)
                ->validationMessages(['unique' => 'A course with this code already exists.']),

            TextInput::make('name')
                ->label('Course name')
                ->required()
                ->maxLength(255),
        ];
    }
}
