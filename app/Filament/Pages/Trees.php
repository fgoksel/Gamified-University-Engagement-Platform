<?php

namespace App\Filament\Pages;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\UserRole;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Models\Course;
use App\Models\SubjectArea;
use App\Models\TreeUnit;
use App\Models\User;
use App\Services\TreeService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Faculty tree, admin side (build step 2): the admin creates one tree per
 * dean, appoints the dean and adds Neptun courses as courses. Everything
 * is written through TreeService, so the tree rules apply here too.
 */
class Trees extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $navigationLabel = 'Trees';

    protected static ?string $title = 'Faculty trees';

    protected static ?string $slug = 'trees';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.trees';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TreeUnit::query()
                    ->where('kind', TreeUnitKind::Root)
                    ->withCount('children')
                    ->with(['memberships' => fn ($query) => $query->active()->where('role', TreeRole::Dean)->with('user')])
                    ->latest()
            )
            ->columns([
                TextColumn::make('title')
                    ->label('Tree')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('dean')
                    ->label('Dean')
                    ->state(fn (TreeUnit $record): ?string => $record->memberships->first()?->user->name)
                    ->placeholder('No dean')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'memberships',
                        fn (Builder $membership) => $membership->active()
                            ->where('role', TreeRole::Dean)
                            ->whereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")),
                    )),

                TextColumn::make('children_count')
                    ->label('Courses')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Action::make('view')
                    ->label('View tree')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (TreeUnit $record): string => TreeDetail::getUrl(['tree' => $record->id])),

                static::addCourseAction(fn (TreeUnit $record): TreeUnit => $record),

                Action::make('appointDean')
                    ->label('Appoint dean')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn (TreeUnit $record): bool => $record->memberships->isEmpty())
                    ->modalHeading('Appoint dean')
                    ->modalSubmitActionLabel('Appoint')
                    ->form([static::deanField()])
                    ->action(function (TreeUnit $record, array $data): void {
                        static::attempt(function () use ($record, $data) {
                            app(TreeService::class)->addMember(static::admin(), $record, User::findOrFail($data['dean_id']), TreeRole::Dean);

                            Notification::make()->title('Dean appointed')->success()->send();
                        });
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // UC-3.2.3: Neptun course enrolments become student memberships (build step 4)
            Action::make('importEnrolments')
                ->label('Import enrolments (CSV)')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalHeading('Import course enrolments (CSV)')
                ->modalDescription('Upload the Neptun enrolment file (max 10 MB) with the columns Neptun Code, Course Code and Course Name. Students are added to the courses of the active semester. Courses that are not in a tree yet are skipped and listed in the result.')
                ->modalSubmitActionLabel('Execute Import')
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
                        ->maxSize(10240) // 10MB per Technical Spec 7.3.1
                        ->required()
                        ->helperText(new HtmlString('<a href="/admin/sample-csv/enrolments" class="text-primary-600 underline font-medium" download>Download sample CSV template</a>')),
                ])
                ->action(function (array $data): void {
                    ImportCourseEnrollmentsJob::dispatch($data['file'], Auth::id());

                    Notification::make()
                        ->title('Enrolment Import Queued')
                        ->success()
                        ->body('The CSV file was uploaded and background import has been queued. You will receive a panel notification when the import finishes.')
                        ->send();
                }),

            Action::make('create')
                ->label('New tree')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('New tree')
                ->modalDescription('Each dean has one tree. Courses are added to it afterwards.')
                ->modalSubmitActionLabel('Create tree')
                ->form([
                    TextInput::make('title')
                        ->label('Title')
                        ->placeholder('e.g. Faculty of Informatics')
                        ->required()
                        ->maxLength(255),

                    static::deanField(),
                ])
                ->action(function (array $data): void {
                    static::attempt(function () use ($data) {
                        $root = app(TreeService::class)->createTree(static::admin(), $data['title'], User::findOrFail($data['dean_id']));

                        Notification::make()
                            ->title('Tree created')
                            ->success()
                            ->body("{$root->title} has been created.")
                            ->send();
                    });
                }),
        ];
    }

    /**
     * "Add course": pick a Neptun course that is in no tree yet, or create
     * one. Also used on the tree page.
     *
     * @param  Closure(mixed): TreeUnit  $root  Resolves the tree the course goes into.
     */
    public static function addCourseAction(Closure $root): Action
    {
        return Action::make('addCourse')
            ->label('Add course')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading('Add course')
            ->modalDescription('Pick a Neptun course. Each course can be in one tree only.')
            ->modalSubmitActionLabel('Add course')
            ->form([
                Select::make('course_id')
                    ->label('Neptun course')
                    ->options(fn (): array => Course::query()
                        ->whereDoesntHave('courseUnit')
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Course $course): array => [$course->id => "{$course->code} · {$course->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('code')
                            ->label('Course code')
                            ->required()
                            ->maxLength(50)
                            ->unique(table: Course::class, column: 'code')
                            ->validationMessages(['unique' => 'A course with this code already exists.']),

                        TextInput::make('name')
                            ->label('Course name')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->createOptionUsing(fn (array $data): int => Course::create([
                        'code' => strtoupper($data['code']),
                        'name' => $data['name'],
                    ])->id),

                Select::make('subject_area_id')
                    ->label('Subject area (optional)')
                    ->options(fn (): array => SubjectArea::query()->where('is_active', true)->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->nullable(),
            ])
            ->action(function (array $data, mixed $record = null) use ($root): void {
                static::attempt(function () use ($data, $record, $root) {
                    $courseUnit = app(TreeService::class)->addCourse(
                        static::admin(),
                        $root($record),
                        Course::findOrFail($data['course_id']),
                        isset($data['subject_area_id']) ? SubjectArea::find($data['subject_area_id']) : null,
                    );

                    Notification::make()
                        ->title('Course added')
                        ->success()
                        ->body("{$courseUnit->title} has been added.")
                        ->send();
                });
            });
    }

    /**
     * Only active teacher accounts can be deans.
     */
    protected static function deanField(): Select
    {
        return Select::make('dean_id')
            ->label('Dean')
            ->options(fn (): array => User::role(UserRole::Teacher)
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
                ->all())
            ->searchable()
            ->required();
    }

    /**
     * Run a TreeService call and show its refusal as an error message.
     */
    public static function attempt(Closure $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->title('Not saved')->danger()->body(collect($e->errors())->flatten()->first())->send();
        } catch (AuthorizationException) {
            Notification::make()->title('Not allowed')->danger()->send();
        }
    }

    protected static function admin(): User
    {
        /** @var User */
        return Auth::user();
    }
}
