<?php

namespace App\Filament\Pages\Auth;

use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * The administrator's own profile: name, email and password (Task #17, UC-3.0
 * "Admin Profile": change own password).
 *
 * While the admin still has the temporary password from the seeder, every
 * admin page sends them here first (see EnsureAdminPasswordIsChanged).
 */
class EditProfile extends BaseEditProfile
{
    /** Was the password temporary when the page opened? */
    public bool $mustChangePassword = false;

    public function mount(): void
    {
        parent::mount();

        $this->mustChangePassword = (bool) $this->getUser()->must_change_password;
    }

    public function getSubheading(): ?string
    {
        return $this->mustChangePassword
            ? 'You are using a temporary password. Choose your own password to continue.'
            : null;
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->required(fn (): bool => $this->mustChangePassword)
            ->rule(fn () => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                if (Hash::check($value, $this->getUser()->getAuthPassword())) {
                    $fail('The new password cannot be the same as the old one.');
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        // A new password was chosen, so it is no longer temporary.
        if (array_key_exists('password', $data)) {
            $data['must_change_password'] = false;
        }

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        // After replacing the temporary password, continue to the Dashboard.
        return $this->mustChangePassword ? Filament::getUrl() : null;
    }
}
