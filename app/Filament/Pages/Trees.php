<?php

namespace App\Filament\Pages;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\UserRole;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Models\Faculty;
use App\Models\TreeUnit;
use App\Models\User;
use App\Services\TreeService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
 * Faculty trees, admin side: one tree per faculty, with one dean. The tree
 * is named after its faculty and shows all of the faculty's courses by
 * itself; courses are managed on the Faculties page. Everything is written
 * through TreeService, so the tree rules apply here too.
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
                    ->label('Faculty')
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
                ->modalDescription('Upload the Neptun enrolment file (max 10 MB) with the columns Neptun Code, Course Code and Course Name. Students are added to the courses of the active semester. Course codes that do not exist under a faculty yet are skipped and listed in the result.')
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
                ->modalDescription('Each faculty has one tree, named after the faculty. Its courses appear in the tree by themselves.')
                ->modalSubmitActionLabel('Create tree')
                ->form([
                    Select::make('faculty_id')
                        ->label('Faculty')
                        ->options(fn (): array => Faculty::query()->whereDoesntHave('tree')->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->helperText('Only faculties without a tree are listed.'),

                    static::deanField(),
                ])
                ->action(function (array $data): void {
                    static::attempt(function () use ($data) {
                        $root = app(TreeService::class)->createTree(static::admin(), Faculty::findOrFail($data['faculty_id']), User::findOrFail($data['dean_id']));

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
     * Only active teacher accounts that are not the dean of a tree yet.
     */
    protected static function deanField(): Select
    {
        return Select::make('dean_id')
            ->label('Dean')
            ->options(fn (): array => User::role(UserRole::Teacher)
                ->where('status', 'active')
                ->whereDoesntHave('memberships', fn (Builder $query) => $query->active()->where('role', TreeRole::Dean))
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
