<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Admin Policy — Technical Specification Table 25:
 * "Admin self-lock prevention: AdminPolicy prevents an admin from deactivating their own account
 * or the last remaining admin account."
 */
class AdminPolicy
{
    /**
     * Determine whether the admin can deactivate the target user.
     */
    public function deactivate(User $admin, User $target): bool
    {
        // Only an Administrator can perform deactivation.
        if (! $admin->hasRole(UserRole::Admin)) {
            return false;
        }

        // 1. Cannot deactivate own account (UC-3.1.2 & UC-3.2.2 exceptions).
        if ($admin->id === $target->id) {
            return false;
        }

        // 2. Cannot deactivate the last remaining active Administrator (Technical Specification Table 25).
        if ($target->hasRole(UserRole::Admin)) {
            $activeAdminCount = User::role(UserRole::Admin)->where('status', 'active')->count();
            if ($activeAdminCount <= 1) {
                return false;
            }
        }

        return true;
    }
}
