<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\StudentInvitationNotification;
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
                    ->label('Created At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
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

                // UC-3.2.2: Deactivate an active or invited student.
                // Nulling the token prevents a pending invite link from still working
                // after the account has been deactivated.
                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Deactivate Student')
                    ->modalDescription(fn (User $record): string => "Are you sure you want to deactivate {$record->name} ({$record->neptun_code})? They will no longer be able to log in.")
                    ->modalSubmitActionLabel('Yes, Deactivate')
                    ->visible(fn (User $record): bool => $record->status !== 'inactive')
                    ->disabled(fn (User $record): bool => $record->id === auth()->id())
                    ->tooltip(fn (User $record): ?string => $record->id === auth()->id()
                        ? 'You cannot deactivate your own account.'
                        : null)
                    ->action(function (User $record): void {
                        $record->update([
                            'status' => 'inactive',
                            // Revoke any pending activation link (UC-3.2.2).
                            'activation_token' => null,
                            'activation_token_expires_at' => null,
                        ]);

                        Notification::make()
                            ->title('Student Deactivated')
                            ->warning()
                            ->body("{$record->name} ({$record->neptun_code}) has been deactivated and can no longer log in.")
                            ->send();
                    }),

                // UC-3.2.2: Reactivate a previously deactivated student.
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Reactivate Student')
                    ->modalDescription(fn (User $record): string => "Reactivate {$record->name} ({$record->neptun_code})? They will be able to log in again with their existing password.")
                    ->modalSubmitActionLabel('Yes, Reactivate')
                    ->visible(fn (User $record): bool => $record->status === 'inactive')
                    ->action(function (User $record): void {
                        $record->update(['status' => 'active']);

                        Notification::make()
                            ->title('Student Reactivated')
                            ->success()
                            ->body("{$record->name} ({$record->neptun_code}) has been reactivated and can log in again.")
                            ->send();
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
        ];
    }
}
