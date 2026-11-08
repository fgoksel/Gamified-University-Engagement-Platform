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

    protected static ?string $slug = 'system-admins';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.system-admins';

    public static function getNavigationLabel(): string
    {
        return __('admin.system_admins.navigation');
    }

    public function getTitle(): string
    {
        return __('admin.system_admins.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->role(UserRole::Admin)->orderBy('name'))
            ->modelLabel(__('admin.models.user'))
            ->pluralModelLabel(__('admin.models.users'))
            ->columns([
                TextColumn::make('name')->label(__('admin.system_admins.name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('admin.system_admins.email'))->searchable(),
                TextColumn::make('status')
                    ->label(__('admin.system_admins.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => __('admin.status.active'),
                        'invited' => __('admin.status.invited'),
                        'inactive' => __('admin.status.inactive'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->actions([
                Action::make('replace')
                    ->label(__('admin.system_admins.replace'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->modalHeading(fn (User $record): string => __('admin.system_admins.replace_name_as_system_admin', ['name' => $record->name]))
                    ->modalDescription(__('admin.system_admins.step_1_of_2_choose'))
                    ->modalSubmitActionLabel(__('admin.system_admins.yes_continue'))
                    ->disabled(fn (User $record): bool => $record->id === auth()->id())
                    ->tooltip(fn (User $record): ?string => $record->id === auth()->id() ? __('admin.system_admins.you_cannot_replace_yourself_another') : null)
                    ->form([
                        Select::make('incoming')
                            ->label(__('admin.system_admins.new_system_admin'))
                            ->options(fn (): array => $this->candidates())
                            ->searchable()
                            ->required(),
                        Textarea::make('reason')->label(__('admin.system_admins.reason'))->required()->maxLength(500),
                    ])
                    ->action(function (User $record, array $data): void {
                        $this->replaceMountedAction('confirmReplace', ['outgoing' => $record->id, 'incoming' => $data['incoming'], 'reason' => $data['reason']]);
                    }),

                Action::make('remove')
                    ->label(__('admin.system_admins.remove'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->modalHeading(fn (User $record): string => __('admin.system_admins.are_you_sure_you_want', ['name' => $record->name]))
                    ->modalDescription(__('admin.system_admins.step_1_of_2_they'))
                    ->modalSubmitActionLabel(__('admin.system_admins.yes_continue'))
                    ->disabled(fn (User $record): bool => $record->id === auth()->id())
                    ->tooltip(fn (User $record): ?string => $record->id === auth()->id() ? __('admin.system_admins.you_cannot_remove_yourself_another') : null)
                    ->form([
                        Textarea::make('reason')->label(__('admin.system_admins.reason'))->required()->maxLength(500),
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
                ->label(__('admin.system_admins.appoint_system_admin'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('admin.system_admins.appoint_a_system_admin'))
                ->modalDescription(__('admin.system_admins.the_person_gets_full_administrator'))
                ->modalSubmitActionLabel(__('admin.system_admins.appoint'))
                ->form([
                    Select::make('user')
                        ->label(__('admin.system_admins.account'))
                        ->options(fn (): array => $this->candidates())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->run(fn () => app(SystemAdminService::class)->appoint(auth()->user(), User::findOrFail($data['user'])), __('admin.system_admins.system_admin_appointed'));
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
            ->modalHeading(fn (array $arguments): string => __('admin.system_admins.confirm_removing', ['name' => User::find($arguments['outgoing'])?->name]))
            ->modalDescription(fn (array $arguments): string => __('admin.system_admins.step_2_remove', ['reason' => $arguments['reason']]))
            ->modalSubmitActionLabel(__('admin.system_admins.confirm_remove_system_admin'))
            ->action(function (array $arguments): void {
                $this->run(fn () => app(SystemAdminService::class)->remove(auth()->user(), User::findOrFail($arguments['outgoing']), $arguments['reason']), __('admin.system_admins.system_admin_removed'));
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
            ->modalHeading(fn (array $arguments): string => __('admin.system_admins.confirm_replacing', ['outgoing' => User::find($arguments['outgoing'])?->name, 'incoming' => User::find($arguments['incoming'])?->name]))
            ->modalDescription(fn (array $arguments): string => __('admin.system_admins.step_2_replace', ['reason' => $arguments['reason']]))
            ->modalSubmitActionLabel(__('admin.system_admins.confirm_replacement'))
            ->action(function (array $arguments): void {
                $this->run(fn () => app(SystemAdminService::class)->replace(
                    auth()->user(),
                    User::findOrFail($arguments['outgoing']),
                    User::findOrFail($arguments['incoming']),
                    $arguments['reason'],
                ), __('admin.system_admins.system_admin_replaced'));
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
            Notification::make()->title(__('admin.system_admins.action_not_allowed'))->danger()->body($exception->getMessage())->send();

            return;
        } catch (ValidationException $exception) {
            Notification::make()->title(__('admin.system_admins.nothing_was_changed'))->danger()->body(collect($exception->errors())->flatten()->first())->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }
}
