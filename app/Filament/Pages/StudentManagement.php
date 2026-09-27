<?php

namespace App\Filament\Pages;

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
            ->query(User::query()->whereNotNull('neptun_code')->latest())
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

                        if (method_exists($user, 'assignRole')) {
                            $user->assignRole('student');
                        }

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
