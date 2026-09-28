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
