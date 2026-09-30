<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Organizer Management — UC-3.1.1 (invite) and UC-3.1.2 (listing + deactivation).
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
                    ->label('Created At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                // UC-3.1.2: Deactivate an active or invited organizer.
                // Nulling the token prevents a pending invite link from still working
                // after the account has been deactivated.
                // Disabled for the logged-in admin's own row (UC-3.1.2 exception:
                // "cannot deactivate themselves if they also appear in the list").
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Deactivate Organizer')
                    ->modalDescription(fn (User $record): string => "Are you sure you want to deactivate {$record->name}? They will no longer be able to log in.")
                    ->modalSubmitActionLabel('Yes, Deactivate')
                    ->visible(fn (User $record): bool => $record->status !== 'inactive')
                    ->disabled(fn (User $record): bool => $record->id === auth()->id())
                    ->tooltip(fn (User $record): ?string => $record->id === auth()->id()
                        ? 'You cannot deactivate your own account.'
                        : null)
                    ->action(function (User $record): void {
                        $record->update([
                            'status' => 'inactive',
                            // Revoke any pending activation link (UC-3.1.2).
                            'activation_token' => null,
                            'activation_token_expires_at' => null,
                        ]);

                        Notification::make()
                            ->title('Organizer Deactivated')
                            ->warning()
                            ->body("{$record->name} has been deactivated and can no longer log in.")
                            ->send();
                    }),

                // UC-3.1.2: Reactivate a previously deactivated organizer.
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Reactivate Organizer')
                    ->modalDescription(fn (User $record): string => "Reactivate {$record->name}? They will be able to log in again with their existing password.")
                    ->modalSubmitActionLabel('Yes, Reactivate')
                    ->visible(fn (User $record): bool => $record->status === 'inactive')
                    ->action(function (User $record): void {
                        $record->update(['status' => 'active']);

                        Notification::make()
                            ->title('Organizer Reactivated')
                            ->success()
                            ->body("{$record->name} has been reactivated and can log in again.")
                            ->send();
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
        ];
    }
}
