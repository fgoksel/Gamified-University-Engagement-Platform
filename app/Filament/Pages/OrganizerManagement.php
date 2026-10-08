<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Jobs\ImportOrganizersJob;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
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
 * Organizer Management — UC-3.1.1 (invite) and UC-3.1.2 (listing + deactivation + resend invitation).
 *
 * "Organizer" is the UI term for the 'teacher' role (Technical Specification 6.4).
 * The UserRole::Teacher enum case is the canonical Spatie role name in the database.
 *
 * UC-3.1.2 exception: an admin who also holds the teacher role will appear in this
 * table. In that case the deactivate button is disabled to prevent self-lock-out,
 * per the spec: "The System Administrator cannot deactivate themselves (if they
 * also appear in the list)."
 */
class OrganizerManagement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Organizers';

    protected static ?string $title = 'Organizer Management';

    protected static ?string $slug = 'organizers';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.organizer-management';

    public function table(Table $table): Table
    {
        return $table
            ->query(User::role(UserRole::Teacher)->latest())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('faculty.name')
                    ->label('Organizational Unit / Faculty')
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
                // UC-3.1.2: Edit organizer details (pencil icon).
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit Organizer')
                    ->modalSubmitActionLabel('Save Changes')
                    ->fillForm(fn (User $record): array => [
                        'name' => $record->name,
                        'email' => $record->email,
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
                            ]),

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
                            'faculty_id' => $data['faculty_id'] ?? null,
                        ]);

                        Notification::make()
                            ->title('Organizer Updated')
                            ->success()
                            ->body("{$record->name}'s details have been successfully updated.")
                            ->send();
                    }),

                // UC-3.1.2: Resend activation email for invited organizer.
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

                        $record->notify(new OrganizerInvitationNotification($token, 24));

                        Notification::make()
                            ->title('Invitation Resent')
                            ->success()
                            ->body('The activation link has been successfully resent.')
                            ->send();
                    }),

                // UC-3.1.2: Deactivate an active organizer.
                // Nulling the token prevents a pending invite link from still working
                // after the account has been deactivated.
                // Guarded by AdminPolicy (Technical Specification Table 25, Task #32).
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Deactivate Organizer')
                    ->modalDescription(fn (User $record): string => "Are you sure you want to deactivate {$record->name}? The Organizer will not be able to log in, but their previous events will remain.".static::topicRolesNote($record))
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
                            ->title('Organizer Deactivated')
                            ->warning()
                            ->body("{$record->name} has been deactivated and can no longer log in.")
                            ->send();
                    }),

                // UC-3.1.2: Activate a previously deactivated organizer (green checkmark).
                // If the user never activated (email_verified_at is null), put them back to invited and send a fresh link.
                Action::make('activate')
                    ->label('Activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Activate Organizer')
                    ->modalDescription(fn (User $record): string => ($record->email_verified_at === null || $record->must_change_password)
                        ? "Activate {$record->name}? Since this account has not activated yet, their status will become Invited and a fresh activation email will be sent."
                        : "Activate {$record->name}? They will be able to log in again with their existing password.")
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

                            $record->notify(new OrganizerInvitationNotification($token, 24));

                            Notification::make()
                                ->title('Organizer Activated')
                                ->success()
                                ->body("{$record->name} has not activated yet. Their status was set to Invited and a new activation link has been sent.")
                                ->send();
                        } else {
                            $record->update(['status' => 'active']);

                            Notification::make()
                                ->title('Organizer Activated')
                                ->success()
                                ->body("{$record->name} has been activated and can log in again.")
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite Organizer')
                ->icon(Heroicon::OutlinedEnvelope)
                ->modalHeading('Invite New Organizer')
                ->modalDescription('Send an invitation email to a teacher, demonstrator, or student organizer.')
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
                        ]),

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
                            'password' => Hash::make(Str::random(32)),
                            'faculty_id' => $data['faculty_id'] ?? null,
                            'status' => 'invited',
                            'must_change_password' => true,
                            'activation_token' => $token,
                            'activation_token_expires_at' => now()->addHours(24),
                        ]);

                        $user->assignRole(UserRole::Teacher);

                        $user->notify(new OrganizerInvitationNotification($token, 24));

                        Notification::make()
                            ->title('Invitation Sent')
                            ->success()
                            ->body("The invitation was successfully sent to {$user->email}.")
                            ->send();
                    });
                }),

            // UC-3.1.1: Bulk CSV import for organizers (Task #24)
            Action::make('import')
                ->label('Import Organizers (CSV)')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading('Bulk Import Organizers (CSV)')
                ->modalDescription('Upload a Neptun CSV file (max 10 MB) containing organizer accounts (Full Name, Email, and optional Faculty).')
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
                        ->helperText(new HtmlString('<a href="/admin/sample-csv/organizers" class="text-primary-600 underline font-medium" download>Download sample CSV template</a>')),
                ])
                ->action(function (array $data): void {
                    $path = $data['file'];
                    ImportOrganizersJob::dispatch($path, auth()->id());

                    Notification::make()
                        ->title('Organizer Import Queued')
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
