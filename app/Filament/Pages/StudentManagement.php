<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Jobs\ImportStudentsJob;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\StudentInvitationNotification;
use App\Services\SystemAdminService;
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
use Illuminate\Support\HtmlString;
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

    protected static ?string $navigationLabel = 'Students';

    protected static ?string $title = 'Student Management';

    protected static ?string $slug = 'students';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.student-management';

    public function table(Table $table): Table
    {
        return $table
            ->query(User::role(UserRole::Student)->latest())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('neptun_code')
                    ->label('Neptun Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('major')
                    ->label('Major')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('year_of_study')
                    ->label('Year')
                    ->sortable(),

                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'invited' => 'warning',
                        'inactive' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Registration Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                // UC-3.2.2: Edit student details (pencil icon).
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit Student')
                    ->modalSubmitActionLabel('Save Changes')
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
                            ->label('Full Name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Institutional Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: User::class,
                                column: 'email',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => 'This email address is already registered in the system.',
                                'email' => 'Invalid email address format.',
                            ]),

                        TextInput::make('neptun_code')
                            ->label('Neptun Code')
                            ->required()
                            ->length(6)
                            ->regex('/^[A-Za-z0-9]{6}$/')
                            ->unique(
                                table: User::class,
                                column: 'neptun_code',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => 'This Neptun code is already registered in the system.',
                                'regex' => 'The Neptun code must be exactly 6 alphanumeric characters.',
                            ]),

                        TextInput::make('major')
                            ->label('Major / Programme')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('year_of_study')
                            ->label('Year of Study')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(6),

                        Select::make('faculty_id')
                            ->label('Organizational Unit / Faculty')
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
                            ->title('Student Updated')
                            ->success()
                            ->body("{$record->name}'s details have been successfully updated.")
                            ->send();
                    }),

                // UC-3.2.2: Resend activation email for invited student.
                Action::make('resend_invitation')
                    ->label('Resend Invite')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Resend Invitation')
                    ->modalDescription(fn (User $record): string => "Generate a new activation link and resend the invitation email to {$record->email}?")
                    ->modalSubmitActionLabel('Yes, Resend')
                    ->visible(fn (User $record): bool => $record->status === 'invited')
                    ->action(function (User $record): void {
                        $token = Str::random(64);

                        $record->update([
                            'activation_token' => $token,
                            'activation_token_expires_at' => now()->addHours(24),
                        ]);

                        $record->notify(new StudentInvitationNotification($token, 24));

                        Notification::make()
                            ->title('Invitation Resent')
                            ->success()
                            ->body('Activation link successfully resent.')
                            ->send();
                    }),

                // UC-3.2.2: Deactivate an active student.
                // Nulling the token prevents a pending invite link from still working
                // after the account has been deactivated.
                // Guarded by AdminPolicy (Technical Specification Table 25, Task #32).
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Deactivate Student')
                    ->modalDescription(fn (User $record): string => "Are you sure you want to deactivate {$record->name}?".static::topicRolesNote($record))
                    ->modalSubmitActionLabel('Yes, Deactivate')
                    ->visible(fn (User $record): bool => $record->status === 'active')
                    ->disabled(fn (User $record): bool => ! auth()->user()?->can('deactivate', $record))
                    ->tooltip(function (User $record): ?string {
                        if ($record->id === auth()->id()) {
                            return 'You cannot deactivate your own account.';
                        }
                        if ($record->hasRole(UserRole::Admin) && User::role(UserRole::Admin)->where('status', 'active')->count() <= 1) {
                            return 'Cannot deactivate the last remaining Administrator.';
                        }

                        return null;
                    })
                    ->action(function (User $record): void {
                        if (! auth()->user()?->can('deactivate', $record)) {
                            Notification::make()
                                ->title('Action Not Allowed')
                                ->danger()
                                ->body($record->id === auth()->id()
                                    ? 'You cannot deactivate your own account.'
                                    : 'Cannot deactivate the last remaining Administrator.')
                                ->send();

                            return;
                        }

                        // One locked transaction: sessions end and the last-active-admin rule holds
                        // even when two requests arrive at the same time. Topic roles are kept.
                        try {
                            app(SystemAdminService::class)->deactivate(auth()->user(), $record);
                        } catch (AuthorizationException $exception) {
                            Notification::make()->title('Action Not Allowed')->danger()->body($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title('Student Deactivated')
                            ->warning()
                            ->body("{$record->name} has been deactivated and can no longer log in.")
                            ->send();
                    }),

                // UC-3.2.2: Activate a previously deactivated student (green checkmark).
                // If the user never activated (email_verified_at is null), put them back to invited and send a fresh link.
                Action::make('activate')
                    ->label('Activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Activate Student')
                    ->modalDescription(fn (User $record): string => ($record->email_verified_at === null || $record->must_change_password)
                        ? "Activate {$record->name} ({$record->neptun_code})? Since this account has not activated yet, their status will become Invited and a fresh activation email will be sent."
                        : "Activate {$record->name} ({$record->neptun_code})? They will be able to log in again with their existing password.")
                    ->modalSubmitActionLabel('Yes, Activate')
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
                                ->title('Student Activated')
                                ->success()
                                ->body("{$record->name} has not activated yet. Their status was set to Invited and a new activation link has been sent.")
                                ->send();
                        } else {
                            $record->update(['status' => 'active']);

                            Notification::make()
                                ->title('Student Activated')
                                ->success()
                                ->body("{$record->name} ({$record->neptun_code}) has been activated and can log in again.")
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite Student')
                ->icon(Heroicon::OutlinedEnvelope)
                ->modalHeading('Invite New Student')
                ->modalDescription('Send an activation invitation email to a student.')
                ->modalSubmitActionLabel('Send Invitation')
                ->form([
                    TextInput::make('name')
                        ->label('Full Name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Institutional Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: User::class,
                            column: 'email',
                        )
                        ->validationMessages([
                            'unique' => 'This email address is already registered in the system.',
                            'email' => 'Invalid email address format.',
                        ]),

                    TextInput::make('neptun_code')
                        ->label('Neptun Code')
                        ->required()
                        ->length(6)
                        ->regex('/^[A-Za-z0-9]{6}$/')
                        ->unique(
                            table: User::class,
                            column: 'neptun_code',
                        )
                        ->validationMessages([
                            'unique' => 'This Neptun code is already registered in the system.',
                            'regex' => 'The Neptun code must be exactly 6 alphanumeric characters.',
                        ]),

                    TextInput::make('major')
                        ->label('Major / Programme')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('year_of_study')
                        ->label('Year of Study')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(6),

                    Select::make('faculty_id')
                        ->label('Organizational Unit / Faculty')
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
                            ->title('Invitation Sent')
                            ->success()
                            ->body("Invitation successfully sent to {$user->email}.")
                            ->send();
                    });
                }),

            // UC-3.2.1: Bulk CSV import for students (Task #27)
            Action::make('import')
                ->label('Import Students (CSV)')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading('Bulk Import Students (CSV)')
                ->modalDescription('Upload a Neptun CSV file (max 10 MB) containing student accounts (Full Name, Email, Neptun Code, optional Major, and optional Year of Study).')
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
                        ->helperText(new HtmlString('<a href="/admin/sample-csv/students" class="text-primary-600 underline font-medium" download>Download sample CSV template</a>')),
                ])
                ->action(function (array $data): void {
                    $path = $data['file'];
                    ImportStudentsJob::dispatch($path, auth()->id());

                    Notification::make()
                        ->title('Student Import Queued')
                        ->success()
                        ->body('The CSV file was uploaded and background import has been queued. You will receive a panel notification when the import finishes.')
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
            : " They hold {$count} active topic role(s). These stay in place (nothing is removed); hand them over in Topics when needed.";
    }
}
