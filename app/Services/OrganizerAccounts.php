<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The one way to create an organiser account (UC-3.1.1, Task #104): the admin panel's "Invite
 * organiser", its CSV import and an area's "Invite a person" all come here. Organisers have the
 * teacher account role (UserRole::Teacher).
 *
 * A new account is "invited", like a student's (StudentAccounts): random password, must change
 * it, activation link valid for 24 hours.
 */
class OrganizerAccounts
{
    /**
     * Create the account and send the invitation, once the surrounding transaction is committed.
     */
    public function invite(string $name, string $email, ?int $facultyId = null, ?string $neptun = null): User
    {
        $token = Str::random(64);

        $user = DB::transaction(function () use ($name, $email, $facultyId, $neptun, $token) {
            $user = User::create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'neptun_code' => ($neptun !== null && trim($neptun) !== '') ? Str::upper(trim($neptun)) : null,
                'password' => Hash::make(Str::random(32)),
                'faculty_id' => $facultyId,
                'status' => 'invited',
                'must_change_password' => true,
                'activation_token' => $token,
                'activation_token_expires_at' => now()->addHours(StudentAccounts::LINK_HOURS),
            ]);

            $user->assignRole(UserRole::Teacher);

            return $user;
        });

        DB::afterCommit(fn () => $user->notify(new OrganizerInvitationNotification($token, StudentAccounts::LINK_HOURS)));

        return $user;
    }
}
