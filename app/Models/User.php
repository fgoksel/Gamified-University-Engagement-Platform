<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'password',
    'avatar',
    'status',
    'must_change_password',
    'neptun_code',
    'major',
    'year_of_study',
    'appearance',
    'faculty_id',
    'activation_token',
    'activation_token_expires_at',
])]
#[Hidden(['password', 'remember_token', 'activation_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Decide who may open the Filament admin panel (/admin).
     *
     * Only active administrators may enter. Invited (not yet activated) and
     * inactive (banned) admin accounts are refused too.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active' && $this->hasRole(UserRole::Admin);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'year_of_study' => 'integer',
            'activation_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the faculty the user belongs to.
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * Check if user account is in invited status.
     */
    public function isInvited(): bool
    {
        return $this->status === 'invited';
    }

    /**
     * Check if the activation token is present and not expired.
     */
    public function hasValidActivationToken(): bool
    {
        return ! empty($this->activation_token)
            && $this->activation_token_expires_at !== null
            && $this->activation_token_expires_at->isFuture();
    }
}
