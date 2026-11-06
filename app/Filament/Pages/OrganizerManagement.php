<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Jobs\ImportOrganizersJob;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
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

    protected static ?string $slug = 'organizers';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.organizer-management';

    public static function getNavigationLabel(): string
    {
        return __('admin.organizers.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.organizers.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::role(UserRole::Teacher)->latest())
            ->modelLabel(__('admin.models.user'))
            ->pluralModelLabel(__('admin.models.users'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.organizers.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('admin.organizers.email'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('faculty.name')
                    ->label(__('admin.organizers.organizational_unit_faculty'))
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('admin.organizers.status'))
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
                    ->label(__('admin.organizers.registration_date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                // UC-3.1.2: Edit organizer details (pencil icon).
                Action::make('edit')
                    ->label(__('admin.organizers.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(__('admin.organizers.edit_organizer'))
                    ->modalSubmitActionLabel(__('admin.organizers.save_changes'))
                    ->fillForm(fn (User $record): array => [
                        'name' => $record->name,
                        'email' => $record->email,
                        'faculty_id' => $record->faculty_id,
                    ])
                    ->form([
                        TextInput::make('name')
                            ->label(__('admin.organizers.full_name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(__('admin.organizers.institutional_email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: User::class,
                                column: 'email',
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => __('admin.organizers.this_email_address_is_already'),
                            ]),

                        Select::make('faculty_id')
                            ->label(__('admin.organizers.organizational_unit_faculty'))
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
                            ->title(__('admin.organizers.organizer_updated'))
                            ->success()
                            ->body(__('admin.organizers.name_s_details_have_been', ['name' => $record->name]))
                            ->send();
                    }),

                // UC-3.1.2: Resend activation email for invited organizer.
                Action::make('resend_invitation')
                    ->label(__('admin.organizers.resend_invite'))
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.organizers.resend_invitation'))
                    ->modalDescription(fn (User $record): string => __('admin.organizers.generate_a_new_activation_link', ['email' => $record->email]))
                    ->modalSubmitActionLabel(__('admin.organizers.yes_resend'))
                    ->visible(fn (User $record): bool => $record->status === 'invited')
                    ->action(function (User $record): void {
                        $token = Str::random(64);

                        $record->update([
                            'activation_token' => $token,
                            'activation_token_expires_at' => now()->addHours(24),
                        ]);

                        $record->notify(new OrganizerInvitationNotification($token, 24));

                        Notification::make()
                            ->title(__('admin.organizers.invitation_resent'))
                            ->success()
                            ->body(__('admin.organizers.the_activation_link_has_been'))
                            ->send();
                    }),

                // UC-3.1.2: Deactivate an active organizer.
                // Nulling the token prevents a pending invite link from still working
                // after the account has been deactivated.
                // Guarded by AdminPolicy (Technical Specification Table 25, Task #32).
                Action::make('deactivate')
                    ->label(__('admin.organizers.deactivate'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.organizers.deactivate_organizer'))
                    ->modalDescription(fn (User $record): string => __('admin.organizers.are_you_sure_you_want', ['name' => $record->name]).static::topicRolesNote($record))
                    ->modalSubmitActionLabel(__('admin.organizers.yes_deactivate'))
                    ->visible(fn (User $record): bool => $record->status === 'active')
                    ->disabled(fn (User $record): bool => ! auth()->user()?->can('deactivate', $record))
                    ->tooltip(function (User $record): ?string {
                        if ($record->id === auth()->id()) {
                            return __('admin.organizers.you_cannot_deactivate_your_own');
                        }
                        if ($record->hasRole(UserRole::Admin) && User::role(UserRole::Admin)->where('status', 'active')->count() <= 1) {
                            return __('admin.organizers.cannot_deactivate_the_last_remaining');
                        }

                        return null;
                    })
                    ->action(function (User $record): void {
                        if (! auth()->user()?->can('deactivate', $record)) {
                            Notification::make()
                                ->title(__('admin.organizers.action_not_allowed'))
                                ->danger()
                                ->body($record->id === auth()->id()
                                    ? __('admin.organizers.you_cannot_deactivate_your_own')
                                    : __('admin.organizers.cannot_deactivate_the_last_remaining'))
                                ->send();

                            return;
                        }

                        // One locked transaction: sessions end and the last-active-admin rule holds
                        // even when two requests arrive at the same time. Topic roles are kept.
                        try {
                            app(SystemAdminService::class)->deactivate(auth()->user(), $record);
                        } catch (AuthorizationException $exception) {
                            Notification::make()->title(__('admin.organizers.action_not_allowed'))->danger()->body($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.organizers.organizer_deactivated'))
                            ->warning()
                            ->body(__('admin.organizers.name_has_been_deactivated', ['name' => $record->name]))
                            ->send();
                    }),

                // UC-3.1.2: Activate a previously deactivated organizer (green checkmark).
                // If the user never activated (email_verified_at is null), put them back to invited and send a fresh link.
                Action::make('activate')
                    ->label(__('admin.organizers.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.organizers.activate_organizer'))
                    ->modalDescription(fn (User $record): string => ($record->email_verified_at === null || $record->must_change_password)
                        ? __('admin.organizers.activate_name_since_this_account', ['name' => $record->name])
                        : __('admin.organizers.activate_name_they_will', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('admin.organizers.yes_activate'))
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
                                ->title(__('admin.organizers.organizer_activated'))
                                ->success()
                                ->body(__('admin.organizers.name_has_not_activated_yet', ['name' => $record->name]))
                                ->send();
                        } else {
                            $record->update(['status' => 'active']);

                            Notification::make()
                                ->title(__('admin.organizers.organizer_activated'))
                                ->success()
                                ->body(__('admin.organizers.name_has_been_activated', ['name' => $record->name]))
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('admin.organizers.invite_organizer'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->modalHeading(__('admin.organizers.invite_new_organizer'))
                ->modalDescription(__('admin.organizers.send_an_invitation_email'))
                ->modalSubmitActionLabel(__('admin.organizers.send_invitation'))
                ->form([
                    TextInput::make('name')
                        ->label(__('admin.organizers.full_name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label(__('admin.organizers.institutional_email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            table: User::class,
                            column: 'email',
                        )
                        ->validationMessages([
                            'unique' => __('admin.organizers.this_email_address_is_already'),
                        ]),

                    Select::make('faculty_id')
                        ->label(__('admin.organizers.organizational_unit_faculty'))
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
                            ->title(__('admin.organizers.invitation_sent'))
                            ->success()
                            ->body(__('admin.organizers.the_invitation_was_successfully_sent', ['email' => $user->email]))
                            ->send();
                    });
                }),

            // UC-3.1.1: Bulk CSV import for organizers (Task #24)
            Action::make('import')
                ->label(__('admin.organizers.import_organizers_csv'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading(__('admin.organizers.bulk_import_organizers_csv'))
                ->modalDescription(__('admin.organizers.upload_a_neptun_csv_file'))
                ->modalSubmitActionLabel(__('admin.organizers.execute_import'))
                ->form([
                    FileUpload::make('file')
                        ->placeholder(AdminUpload::placeholder())
                        ->label(__('admin.organizers.csv_file'))
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
                        ->helperText(AdminUpload::sampleLink('/admin/sample-csv/organizers', __('admin.organizers.download_sample_csv_template'))),
                ])
                ->action(function (array $data): void {
                    $path = $data['file'];
                    ImportOrganizersJob::dispatch($path, auth()->id());

                    Notification::make()
                        ->title(__('admin.organizers.organizer_import_queued'))
                        ->success()
                        ->body(__('admin.organizers.the_csv_file_was_uploaded'))
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
            : ' '.__('admin.organizers.they_hold_count_active_area', ['count' => $count]);
    }
}
