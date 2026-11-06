<?php

namespace App\Filament\Pages;

use App\Imports\CsvRowsImport;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Topic;
use App\Services\CourseEnrollmentScope;
use App\Support\AdminUpload;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        return __('admin.faculty_courses.courses_of_name', ['name' => $this->record->name]);
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
            ->modelLabel(__('admin.models.course'))
            ->pluralModelLabel(__('admin.models.courses'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.faculty_courses.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('admin.faculty_courses.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('legacyTopic.title')
                    ->label(__('admin.faculty_courses.linked_area'))
                    ->placeholder(__('admin.faculty_courses.not_linked'))
                    ->badge()
                    ->color(fn (Course $record): string => $record->legacyTopic ? 'success' : 'gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('students')
                    ->label(__('admin.faculty_courses.students'))
                    ->state(fn (Course $record): int => $record->legacyTopic === null ? 0 : RoleAssignment::query()
                        ->active()
                        ->where('topic_id', $record->legacyTopic->id)
                        ->whereHas('role', fn ($query) => $query->where('legacy_key', 'student'))
                        ->count()),

                TextColumn::make('created_at')
                    ->label(__('admin.faculty_courses.added'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Action::make('linkTopic')
                    ->label(fn (Course $record): string => $record->legacyTopic ? __('admin.faculty_courses.change_link') : __('admin.faculty_courses.link_to_area'))
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->modalHeading(fn (Course $record): string => __('admin.faculty_courses.link_course_code', ['code' => $record->code]))
                    ->modalDescription(__('admin.faculty_courses.link_this_course'))
                    ->modalSubmitActionLabel(__('admin.faculty_courses.save_link'))
                    ->fillForm(fn (Course $record): array => [
                        'topic_id' => $record->legacyTopic?->id,
                    ])
                    ->form(fn (Course $record): array => [
                        Select::make('topic_id')
                            ->label(__('admin.faculty_courses.area_node'))
                            ->searchable()
                            ->preload()
                            ->options(function () use ($record): array {
                                return Topic::query()
                                    ->notArchived()
                                    ->where(function ($query) use ($record) {
                                        $query->whereNull('course_id');
                                        if ($record->legacyTopic) {
                                            $query->orWhere('id', $record->legacyTopic->id);
                                        }
                                    })
                                    ->orderBy('title')
                                    ->pluck('title', 'id')
                                    ->all();
                            })
                            ->placeholder(__('admin.faculty_courses.unlink_no_area'))
                            ->nullable(),
                    ])
                    ->action(function (Course $record, array $data): void {
                        $newTopicId = ! empty($data['topic_id']) ? (int) $data['topic_id'] : null;

                        if ($record->legacyTopic && $record->legacyTopic->id !== $newTopicId) {
                            $record->legacyTopic->update(['course_id' => null]);
                        }

                        if ($newTopicId !== null) {
                            Topic::where('id', $newTopicId)->update(['course_id' => $record->id]);
                            $topicTitle = Topic::find($newTopicId)?->title ?? __('admin.faculty_courses.area');
                            Notification::make()
                                ->title(__('admin.faculty_courses.course_linked_to_area'))
                                ->success()
                                ->body(__('admin.faculty_courses.code_is_now_linked', ['code' => $record->code, 'area_title' => $topicTitle]))
                                ->send();
                        } else {
                            Notification::make()
                                ->title(__('admin.faculty_courses.course_unlinked_from_area'))
                                ->success()
                                ->body(__('admin.faculty_courses.code_is_no_longer_linked', ['code' => $record->code]))
                                ->send();
                        }
                    }),

                Action::make('importEnrolments')
                    ->label(__('admin.faculty_courses.import_enrolments'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->color('success')
                    ->visible(fn (Course $record): bool => $record->legacyTopic !== null)
                    ->modalHeading(fn (Course $record): string => __('admin.faculty_courses.import_enrolments_code_name', ['code' => $record->code, 'name' => $record->name]))
                    ->modalDescription(__('admin.faculty_courses.choose_the_course_area'))
                    ->modalSubmitActionLabel(__('admin.faculty_courses.execute_import'))
                    ->form(function (Course $record): array {
                        $topic = $record->legacyTopic;
                        if (! $topic) {
                            return [];
                        }

                        return [
                            FileUpload::make('file')
                                ->placeholder(AdminUpload::placeholder())
                                ->label(__('admin.faculty_courses.neptun_enrolment_csv'))
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
                                ->required()
                                ->helperText(__('admin.faculty_courses.required_columns_neptun_code_course')),

                            Select::make('semester_id')
                                ->label(__('admin.faculty_courses.semester'))
                                ->options(Semester::query()->orderByDesc('starts_at')->pluck('name', 'id'))
                                ->default(fn () => Semester::where('status', 'active')->value('id'))
                                ->required(),

                            Select::make('target_topic_id')
                                ->label(__('admin.faculty_courses.import_destination'))
                                ->options(fn (): array => CourseEnrollmentScope::destinations($record)->orderBy('depth')->orderBy('title')->pluck('title', 'id')->all())
                                ->default($topic->id)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('nodes_above', []);
                                    $set('nodes_below', []);
                                })
                                ->helperText(__('admin.faculty_courses.for_a_lab_subset_choose')),

                            Section::make(__('admin.faculty_courses.which_other_nodes_should_these'))
                                ->description(__('admin.faculty_courses.membership_is_added_only'))
                                ->schema([
                                    CheckboxList::make('nodes_above')
                                        ->label(__('admin.faculty_courses.nodes_directly_above_ancestors'))
                                        ->options(fn (Get $get): array => self::importNodeOptions((int) $get('target_topic_id'), true))
                                        ->helperText(__('admin.faculty_courses.optional_include_parent_nodes_only'))
                                        ->bulkToggleable(),

                                    CheckboxList::make('nodes_below')
                                        ->label(__('admin.faculty_courses.nodes_directly_below_descendants'))
                                        ->options(fn (Get $get): array => self::importNodeOptions((int) $get('target_topic_id'), false))
                                        ->helperText(__('admin.faculty_courses.optional_include_child_nodes_only'))
                                        ->bulkToggleable(),
                                ]),
                        ];
                    })
                    ->action(function (Course $record, array $data): void {
                        $targetTopicId = (int) $data['target_topic_id'];
                        if (CourseEnrollmentScope::destination($record, $targetTopicId) === null) {
                            throw ValidationException::withMessages([
                                'target_topic_id' => __('admin.faculty_courses.choose_the_linked_course_area'),
                            ]);
                        }

                        $path = $data['file'];
                        $semesterId = (int) $data['semester_id'];
                        $selectedTopicIds = array_map('intval', array_merge(
                            $data['nodes_above'] ?? [],
                            $data['nodes_below'] ?? []
                        ));

                        ImportCourseEnrollmentsJob::dispatch(
                            $path,
                            auth()->id(),
                            semesterId: $semesterId,
                            additionalTopicIds: $selectedTopicIds,
                            targetTopicId: $targetTopicId,
                            courseId: $record->id,
                        );

                        Notification::make()
                            ->title(__('admin.faculty_courses.enrolment_import_queued'))
                            ->success()
                            ->body(__('admin.faculty_courses.enrolment_import_for_code_has', ['code' => $record->code]))
                            ->send();
                    }),

                Action::make('edit')
                    ->label(__('admin.faculty_courses.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(__('admin.faculty_courses.edit_course'))
                    ->modalSubmitActionLabel(__('admin.faculty_courses.save_changes'))
                    ->fillForm(fn (Course $record): array => ['code' => $record->code, 'name' => $record->name])
                    ->form(fn (Course $record): array => static::courseFields($record))
                    ->action(function (Course $record, array $data): void {
                        $record->update(['code' => strtoupper(trim($data['code'])), 'name' => trim($data['name'])]);

                        Notification::make()->title(__('admin.faculty_courses.course_updated'))->success()->send();
                    }),
            ]);
    }

    /** @return array<int, string> */
    private static function importNodeOptions(int $topicId, bool $ancestors): array
    {
        $ids = DB::table('topic_closure')
            ->where($ancestors ? 'descendant_id' : 'ancestor_id', $topicId)
            ->where('distance', '>', 0)
            ->pluck($ancestors ? 'ancestor_id' : 'descendant_id');

        return Topic::query()->whereIn('id', $ids)->notArchived()
            ->orderBy('depth')->orderBy('title')
            ->get()->mapWithKeys(fn (Topic $topic): array => [
                $topic->id => $topic->title.($topic->code ? " ({$topic->code})" : ''),
            ])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('admin.faculty_courses.all_faculties'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Faculties::getUrl()),

            Action::make('importEnrolments')
                ->label(__('admin.faculty_courses.import_enrolments_csv'))
                ->icon(Heroicon::OutlinedUsers)
                ->color('gray')
                ->modalHeading(__('admin.faculty_courses.bulk_import_course_enrolments_csv'))
                ->modalDescription(__('admin.faculty_courses.upload_a_neptun_enrolment_csv'))
                ->modalSubmitActionLabel(__('admin.faculty_courses.import'))
                ->form([
                    FileUpload::make('file')
                        ->placeholder(AdminUpload::placeholder())
                        ->label(__('admin.faculty_courses.csv_file'))
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
                        ->required()
                        ->helperText(__('admin.faculty_courses.required_columns_neptun_code_course')),

                    Select::make('semester_id')
                        ->label(__('admin.faculty_courses.semester'))
                        ->options(Semester::query()->orderByDesc('starts_at')->pluck('name', 'id'))
                        ->default(fn () => Semester::where('status', 'active')->value('id'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    ImportCourseEnrollmentsJob::dispatch(
                        $data['file'],
                        auth()->id(),
                        semesterId: (int) $data['semester_id']
                    );

                    Notification::make()
                        ->title(__('admin.faculty_courses.enrolment_import_queued'))
                        ->success()
                        ->body(__('admin.faculty_courses.the_enrolment_csv_has_been'))
                        ->send();
                }),

            Action::make('import')
                ->label(__('admin.faculty_courses.import_courses_csv'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalHeading(__('admin.faculty_courses.import_courses_csv'))
                ->modalDescription(__('admin.faculty_courses.upload_a_csv_file'))
                ->modalSubmitActionLabel(__('admin.faculty_courses.import'))
                ->form([
                    FileUpload::make('file')
                        ->placeholder(AdminUpload::placeholder())
                        ->label(__('admin.faculty_courses.csv_file'))
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
                ->label(__('admin.faculty_courses.add_course'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('admin.faculty_courses.add_course'))
                ->modalSubmitActionLabel(__('admin.faculty_courses.add_course'))
                ->form(static::courseFields())
                ->action(function (array $data): void {
                    $course = Course::create([
                        'faculty_id' => $this->record->id,
                        'code' => strtoupper(trim($data['code'])),
                        'name' => trim($data['name']),
                    ]);

                    Notification::make()
                        ->title(__('admin.faculty_courses.course_added'))
                        ->success()
                        ->body(__('admin.faculty_courses.code_name_has_been_added', ['code' => $course->code, 'name' => $course->name]))
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
            Notification::make()->title(__('admin.faculty_courses.import_failed'))->danger()->body(__('admin.faculty_courses.incorrect_file_structure_the_csv'))->send();

            return;
        }

        Excel::import(new CsvRowsImport(function (array $row, int $line) use (&$added, &$existing, &$problems) {
            $code = strtoupper((string) ($row['course_code'] ?? ''));
            $name = (string) ($row['course_name'] ?? '');

            if ($code === '' || $name === '') {
                $problems[] = __('admin.faculty_courses.row_line_skipped_course_code', ['line' => $line]);

                return;
            }

            $course = Course::where('code', $code)->first();

            if ($course === null) {
                Course::create(['faculty_id' => $this->record->id, 'code' => $code, 'name' => $name]);
                $added++;
            } elseif ($course->faculty_id === $this->record->id) {
                $existing++;
            } else {
                $problems[] = __('admin.faculty_courses.row_line_skipped_code_belongs', ['line' => $line, 'code' => $code, 'name' => $course->faculty->name]);
            }
        }), $path, 'local', ExcelFormat::CSV);

        Storage::disk('local')->delete($path);

        $body = __('admin.faculty_courses.added_courses_added_existing_were', ['added' => $added, 'existing' => $existing]);

        if ($problems !== []) {
            $body .= ' '.__('admin.faculty_courses.rows_skipped', ['count' => count($problems), 'problems' => implode(' ', array_slice($problems, 0, 5))]);
        }

        Notification::make()->title(__('admin.faculty_courses.course_import_finished'))->success()->body($body)->send();
    }

    /**
     * @return list<TextInput>
     */
    protected static function courseFields(?Course $record = null): array
    {
        return [
            TextInput::make('code')
                ->label(__('admin.faculty_courses.course_code'))
                ->required()
                ->maxLength(50)
                ->unique(table: Course::class, column: 'code', ignorable: $record)
                ->validationMessages(['unique' => __('admin.faculty_courses.a_course_with_this_code')]),

            TextInput::make('name')
                ->label(__('admin.faculty_courses.course_name'))
                ->required()
                ->maxLength(255),
        ];
    }
}
