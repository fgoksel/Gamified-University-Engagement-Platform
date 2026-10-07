<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SystemAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Who is a System Admin. At least one active System Admin always remains;
 * nobody removes or replaces themselves, so the only remaining admin first
 * appoints a second one. Removing and replacing take two confirmations.
 * A role named "Admin" inside a topic is not a System Admin.
 */
class SystemAdmins extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'System admins';

    protected static ?string $title = 'System admins';

    protected static ?string $slug = 'system-admins';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.system-admins';

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->role(UserRole::Admin)->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->actions([
                Action::make('replace')
                    ->label('Replace')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->modalHeading(fn (User $record): string => "Replace {$record->name} as System Admin?")
                    ->modalDescription('Step 1 of 2. Choose who takes over and why. The new System Admin is appointed and this one is removed in one step; nothing else changes.')
                    ->modalSubmitActionLabel('Yes, continue')
                    ->disabled(fn (User $record): bool => $record->id === auth()->id())
                    ->tooltip(fn (User $record): ?string => $record->id === auth()->id() ? 'You cannot replace yourself. Another System Admin must do it.' : null)
                    ->form([
                        Select::make('incoming')
                            ->label('New System Admin')
                            ->options(fn (): array => $this->candidates())
                            ->searchable()
                            ->required(),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(function (User $record, array $data): void {
                        $this->replaceMountedAction('confirmReplace', ['outgoing' => $record->id, 'incoming' => $data['incoming'], 'reason' => $data['reason']]);
                    }),

                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->modalHeading(fn (User $record): string => "Are you sure you want to remove {$record->name} as System Admin?")
                    ->modalDescription('Step 1 of 2. They keep their account and any topic roles; they lose administrator authority.')
                    ->modalSubmitActionLabel('Yes, continue')
                    ->disabled(fn (User $record): bool => $record->id === auth()->id())
                    ->tooltip(fn (User $record): ?string => $record->id === auth()->id() ? 'You cannot remove yourself. Another System Admin must do it.' : null)
                    ->form([
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(function (User $record, array $data): void {
                        $this->replaceMountedAction('confirmRemove', ['outgoing' => $record->id, 'reason' => $data['reason']]);
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('appoint')
                ->label('Appoint System Admin')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Appoint a System Admin')
                ->modalDescription('The person gets full administrator authority. Only active accounts can be appointed.')
                ->modalSubmitActionLabel('Appoint')
                ->form([
                    Select::make('user')
                        ->label('Account')
                        ->options(fn (): array => $this->candidates())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->run(fn () => app(SystemAdminService::class)->appoint(auth()->user(), User::findOrFail($data['user'])), 'System Admin appointed');
                }),
        ];
    }

    /**
     * Step 2 of 2 for removing a System Admin.
     */
    public function confirmRemoveAction(): Action
    {
        return Action::make('confirmRemove')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading(fn (array $arguments): string => 'Confirm removing '.User::find($arguments['outgoing'])?->name.' as System Admin?')
            ->modalDescription(fn (array $arguments): string => 'Step 2 of 2. Reason: '.$arguments['reason'].'. At least one active System Admin always remains; this is checked again when you confirm.')
            ->modalSubmitActionLabel('Confirm, remove System Admin')
            ->action(function (array $arguments): void {
                $this->run(fn () => app(SystemAdminService::class)->remove(auth()->user(), User::findOrFail($arguments['outgoing']), $arguments['reason']), 'System Admin removed');
            });
    }

    /**
     * Step 2 of 2 for replacing a System Admin.
     */
    public function confirmReplaceAction(): Action
    {
        return Action::make('confirmReplace')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading(fn (array $arguments): string => 'Confirm replacing '.User::find($arguments['outgoing'])?->name.' with '.User::find($arguments['incoming'])?->name.'?')
            ->modalDescription(fn (array $arguments): string => 'Step 2 of 2. Reason: '.$arguments['reason'].'. Nothing else changes; this is checked again when you confirm.')
            ->modalSubmitActionLabel('Confirm replacement')
            ->action(function (array $arguments): void {
                $this->run(fn () => app(SystemAdminService::class)->replace(
                    auth()->user(),
                    User::findOrFail($arguments['outgoing']),
                    User::findOrFail($arguments['incoming']),
                    $arguments['reason'],
                ), 'System Admin replaced');
            });
    }

    /**
     * Active accounts that are not System Admins yet.
     *
     * @return array<int, string>
     */
    private function candidates(): array
    {
        return User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', UserRole::Admin->value))
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    private function run(callable $change, string $success): void
    {
        try {
            $change();
        } catch (AuthorizationException $exception) {
            Notification::make()->title('Action not allowed')->danger()->body($exception->getMessage())->send();

            return;
        } catch (ValidationException $exception) {
            Notification::make()->title('Nothing was changed')->danger()->body(collect($exception->errors())->flatten()->first())->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }
}
