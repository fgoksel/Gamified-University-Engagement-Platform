<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Jobs\ImportStudentsJob;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\StudentInvitationNotification;
use App\Services\SystemAdminService;
use App\Support\AdminUpload;
use BackedEnum;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Student Management — UC-3.2.1 (invite) and UC-3.2.2 (listing + deactivation + resend invitation).
 *
 * Students are identified by a non-null neptun_code and carry UserRole::Student.
 */
class StudentManagement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $slug = 'students';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.student-management';

    public static function getNavigationLabel(): string
    {
        return __('admin.students.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.students.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::role(UserRole::Student)->latest())
            ->modelLabel(__('admin.models.user'))
            ->pluralModelLabel(__('admin.models.users'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.students.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('neptun_code')
                    ->label(__('admin.students.neptun_code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('admin.students.email'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('major')
                    ->label(__('admin.students.major'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('year_of_study')
                    ->label(__('admin.students.year'))
                    ->sortable(),

                TextColumn::make('faculty.name')
                    ->label(__('admin.students.faculty'))
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('admin.students.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => __('admin.status.active'),
                        'invited' => __('admin.status.invited'),
                        'inactive' => __('admin.status.inactive'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'invited' => 'warning',
                        'inactive' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label(__('admin.students.registration_date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                // UC-3.2.2: Edit student details (pencil icon).
                Action::make('edit')
                    ->label(__('admin.students.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(__('admin.students.edit_student'))
                    ->modalSubmitActionLabel(__('admin.students.save_changes'))
                    ->fillForm(fn (User $record): array => [
                        'name' => $record->name,
                        'email' => $record->email,
                        'neptun_code' => $record->neptun_code,
                        'major' => $record->major,
                        'year_of_study' => $record->year_of_study,
                        'faculty_id' => $record->faculty_id,
                    ])
                    ->form([
                        TextInput::make('name')
                            ->label(__('admin.students.full_name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(__('admin.students.institutional_email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: User::class,
                                column: 'email',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => __('admin.students.this_email_address_is_already'),
                                'email' => __('admin.students.invalid_email_address_format'),
                            ]),

                        TextInput::make('neptun_code')
                            ->label(__('admin.students.neptun_code'))
                            ->required()
                            ->length(6)
                            ->regex('/^[A-Za-z0-9]{6}$/')
                            ->unique(
                                table: User::class,
                                column: 'neptun_code',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => __('admin.students.this_neptun_code_is_already'),
                                'regex' => __('admin.students.the_neptun_code_must'),
                            ]),

                        TextInput::make('major')
                            ->label(__('admin.students.major_programme'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('year_of_study')
                            ->label(__('admin.students.year_of_study'))
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(6),

                        Select::make('faculty_id')
                            ->label(__('admin.students.organizational_unit_faculty'))
                            ->options(fn (): array => Faculty::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->nullable()
                            ->exists(table: Faculty::class, column: 'id'),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->update([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'neptun_code' => strtoupper($data['neptun_code']),
                            'major' => $data['major'],
                            'year_of_study' => (int) $data['year_of_study'],
                            'faculty_id' => $data['faculty_id'] ?? null,
                        ]);

                        Notification::make()
                            ->title(__('admin.students.student_updated'))
                            ->success()
                            ->body(__('admin.students.name_s_details_have_been', ['name' => $record->name]))
                            ->send();
                    }),

                // UC-3.2.2: Resend activation email for invited student.
                Action::make('resend_invitation')
                    ->label(__('admin.students.resend_invite'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.students.resend_invitation'))
                    ->modalDescription(fn (User $record): string => __('admin.students.generate_a_new_activation_link', ['email' => $record->email]))
                    ->modalSubmitActionLabel(__('admin.students.yes_resend'))
                    ->visible(fn (User $record): bool => $record->status === 'invited')
                    ->action(function (User $record): void {
                        $token = Str::random(64);

                        $record->update([
                            'activation_token' => $token,
                            'activation_token_expires_at' => now()->addHours(24),
                        ]);

                        $record->notify(new StudentInvitationNotification($token, 24));

                        Notification::make()
                            ->title(__('admin.students.invitation_resent'))
                            ->success()
                            ->body(__('admin.students.activation_link_successfully_resent'))
                            ->send();
                    }),

                // UC-3.2.2: Deactivate an active student.
                // Nulling the token prevents a pending invite link from still working
                // after the account has been deactivated.
                // Guarded by AdminPolicy (Technical Specification Table 25, Task #32).
                Action::make('deactivate')
                    ->label(__('admin.students.deactivate'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.students.deactivate_student'))
                    ->modalDescription(fn (User $record): string => __('admin.students.are_you_sure_you_want', ['name' => $record->name]).static::topicRolesNote($record))
                    ->modalSubmitActionLabel(__('admin.students.yes_deactivate'))
                    ->visible(fn (User $record): bool => $record->status === 'active')
                    ->disabled(fn (User $record): bool => ! auth()->user()?->can('deactivate', $record))
                    ->tooltip(function (User $record): ?string {
                        if ($record->id === auth()->id()) {
                            return __('admin.students.you_cannot_deactivate_your_own');
                        }
                        if ($record->hasRole(UserRole::Admin) && User::role(UserRole::Admin)->where('status', 'active')->count() <= 1) {
                            return __('admin.students.cannot_deactivate_the_last_remaining');
                        }

                        return null;
                    })
                    ->action(function (User $record): void {
                        if (! auth()->user()?->can('deactivate', $record)) {
                            Notification::make()
                                ->title(__('admin.students.action_not_allowed'))
                                ->danger()
                                ->body($record->id === auth()->id()
                                    ? __('admin.students.you_cannot_deactivate_your_own')
                                    : __('admin.students.cannot_deactivate_the_last_remaining'))
                                ->send();

                            return;
                        }

                        // One locked transaction: sessions end and the last-active-admin rule holds
                        // even when two requests arrive at the same time. Topic roles are kept.
                        try {
                            app(SystemAdminService::class)->deactivate(auth()->user(), $record);
                        } catch (AuthorizationException $exception) {
                            Notification::make()->title(__('admin.students.action_not_allowed'))->danger()->body($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.students.student_deactivated'))
                            ->warning()
                            ->body(__('admin.students.name_has_been_deactivated', ['name' => $record->name]))
                            ->send();
                    }),

                // UC-3.2.2: Activate a previously deactivated student (green checkmark).
                // If the user never activated (email_verified_at is null), put them back to invited and send a fresh link.
                Action::make('activate')
                    ->label(__('admin.students.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.students.activate_student'))
                    ->modalDescription(fn (User $record): string => ($record->email_verified_at === null || $record->must_change_password)
                        ? __('admin.students.activate_name_neptun_code_since', ['name' => $record->name, 'neptun_code' => $record->neptun_code])
                        : __('admin.students.activate_name_neptun_code_they', ['name' => $record->name, 'neptun_code' => $record->neptun_code]))
                    ->modalSubmitActionLabel(__('admin.students.yes_activate'))
                    ->visible(fn (User $record): bool => $record->status === 'inactive')
                    ->action(function (User $record): void {
                        if ($record->email_verified_at === null || $record->must_change_password) {
                            $token = Str::random(64);

                            $record->update([
                                'status' => 'invited',
                                'activation_token' => $token,
                                'activation_token_expires_at' => now()->addHours(24),
                            ]);

                            $record->notify(new StudentInvitationNotification($token, 24));

                            Notification::make()
                                ->title(__('admin.students.student_activated'))
                                ->success()
                                ->body(__('admin.students.name_has_not_activated_yet', ['name' => $record->name]))
                                ->send();
                        } else {
                            $record->update(['status' => 'active']);

                            Notification::make()
                                ->title(__('admin.students.student_activated'))
                                ->success()
                                ->body(__('admin.students.name_neptun_code_has_been', ['name' => $record->name, 'neptun_code' => $record->neptun_code]))
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('admin.students.invite_student'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->modalHeading(__('admin.students.invite_new_student'))
                ->modalDescription(__('admin.students.send_an_activation_invitation_email'))
                ->modalSubmitActionLabel(__('admin.students.send_invitation'))
                ->form([
                    TextInput::make('name')
                        ->label(__('admin.students.full_name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label(__('admin.students.institutional_email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: User::class,
                            column: 'email',
                        )
                        ->validationMessages([
                            'unique' => __('admin.students.this_email_address_is_already'),
                            'email' => __('admin.students.invalid_email_address_format'),
                        ]),

                    TextInput::make('neptun_code')
                        ->label(__('admin.students.neptun_code'))
                        ->required()
                        ->length(6)
                        ->regex('/^[A-Za-z0-9]{6}$/')
                        ->unique(
                            table: User::class,
                            column: 'neptun_code',
                        )
                        ->validationMessages([
                            'unique' => __('admin.students.this_neptun_code_is_already'),
                            'regex' => __('admin.students.the_neptun_code_must'),
                        ]),

                    TextInput::make('major')
                        ->label(__('admin.students.major_programme'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('year_of_study')
                        ->label(__('admin.students.year_of_study'))
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(6),

                    Select::make('faculty_id')
                        ->label(__('admin.students.organizational_unit_faculty'))
                        ->options(fn (): array => Faculty::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->nullable()
                        ->exists(table: Faculty::class, column: 'id'),
                ])
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data) {
                        $token = Str::random(64);

                        $user = User::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'neptun_code' => strtoupper($data['neptun_code']),
                            'major' => $data['major'],
                            'year_of_study' => (int) $data['year_of_study'],
                            'faculty_id' => $data['faculty_id'] ?? null,
                            'password' => Hash::make(Str::random(32)),
                            'status' => 'invited',
                            'must_change_password' => true,
                            'activation_token' => $token,
                            'activation_token_expires_at' => now()->addHours(24),
                        ]);

                        $user->assignRole(UserRole::Student);

                        $user->notify(new StudentInvitationNotification($token, 24));

                        Notification::make()
                            ->title(__('admin.students.invitation_sent'))
                            ->success()
                            ->body(__('admin.students.invitation_successfully_sent_to_email', ['email' => $user->email]))
                            ->send();
                    });
                }),

            // UC-3.2.1: Bulk CSV import for students (Task #27)
            Action::make('import')
                ->label(__('admin.students.import_students_csv'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading(__('admin.students.bulk_import_students_csv'))
                ->modalDescription(__('admin.students.upload_a_neptun_csv_file'))
                ->modalSubmitActionLabel(__('admin.students.execute_import'))
                ->form([
                    FileUpload::make('file')
                        ->placeholder(AdminUpload::placeholder())
                        ->label(__('admin.students.csv_file'))
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
                        ->helperText(AdminUpload::sampleLink('/admin/sample-csv/students', __('admin.students.download_sample_csv_template'))),
                ])
                ->action(function (array $data): void {
                    $path = $data['file'];
                    ImportStudentsJob::dispatch($path, auth()->id());

                    Notification::make()
                        ->title(__('admin.students.student_import_queued'))
                        ->success()
                        ->body(__('admin.students.the_csv_file_was_uploaded'))
                        ->send();
                }),
        ];
    }

    /**
     * Topic roles are kept when an account is deactivated: say so, and where to hand them over.
     */
    protected static function topicRolesNote(User $record): string
    {
        $count = app(SystemAdminService::class)->activeAssignmentCount($record);

        return $count === 0
            ? ''
            : ' '.__('admin.students.they_hold_count_active_topic', ['count' => $count]);
    }
}
