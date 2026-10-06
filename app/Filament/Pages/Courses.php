<?php

namespace App\Filament\Pages;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Models\Course;
use App\Models\SubjectArea;
use App\Models\TreeUnit;
use App\Services\TreeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every Neptun course, with the tree it is in and its number of students.
 * Courses created by the enrolment import that are in no tree yet can be
 * added to one here (faculty tree).
 */
class Courses extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Courses';

    protected static ?string $title = 'Neptun courses';

    protected static ?string $slug = 'courses';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.courses';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Course::query()
                    ->with(['courseUnit' => fn ($query) => $query
                        ->with('parent')
                        ->withCount(['memberships as students_count' => fn ($membership) => $membership->active()->where('role', TreeRole::Student)])])
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

                TextColumn::make('tree')
                    ->label('Tree')
                    ->state(fn (Course $record): ?string => $record->courseUnit?->parent?->title)
                    ->placeholder('Not in a tree')
                    ->badge()
                    ->color(fn (Course $record): string => $record->courseUnit ? 'primary' : 'warning'),

                TextColumn::make('students')
                    ->label('Students')
                    ->state(fn (Course $record): int => $record->courseUnit?->students_count ?? 0),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('in_tree')
                    ->label('In a tree')
                    ->trueLabel('In a tree')
                    ->falseLabel('Not in a tree')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('courseUnit'),
                        false: fn (Builder $query) => $query->whereDoesntHave('courseUnit'),
                    ),
            ])
            ->actions([
                Action::make('viewTree')
                    ->label('View tree')
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (Course $record): bool => $record->courseUnit !== null)
                    ->url(fn (Course $record): ?string => $record->courseUnit
                        ? TreeDetail::getUrl(['tree' => $record->courseUnit->parent_id])
                        : null),

                Action::make('addToTree')
                    ->label('Add to tree')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->visible(fn (Course $record): bool => $record->courseUnit === null)
                    ->modalHeading(fn (Course $record): string => "Add {$record->code} to a tree")
                    ->modalDescription('The course becomes a course in the tree. Import the enrolment file again afterwards to add its students.')
                    ->modalSubmitActionLabel('Add to tree')
                    ->form([
                        Select::make('tree_id')
                            ->label('Tree')
                            ->options(fn (): array => TreeUnit::where('kind', TreeUnitKind::Root)->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->required(),

                        Select::make('subject_area_id')
                            ->label('Subject area (optional)')
                            ->options(fn (): array => SubjectArea::query()->where('is_active', true)->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->nullable(),
                    ])
                    ->action(function (Course $record, array $data): void {
                        Trees::attempt(function () use ($record, $data) {
                            app(TreeService::class)->addCourse(
                                Auth::user(),
                                TreeUnit::where('kind', TreeUnitKind::Root)->findOrFail($data['tree_id']),
                                $record,
                                isset($data['subject_area_id']) ? SubjectArea::find($data['subject_area_id']) : null,
                            );

                            Notification::make()
                                ->title('Course added to the tree')
                                ->success()
                                ->body("{$record->code} {$record->name} is now a course.")
                                ->send();
                        });
                    }),
            ]);
    }
}
