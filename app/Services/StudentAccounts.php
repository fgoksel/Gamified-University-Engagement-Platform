<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\StudentInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The one way to create a student account (UC-3.2.1, Task #104): the admin panel's "Invite
 * student", its CSV import and an area's "Import students" and "Invite a person" all come here.
 *
 * A new account is "invited": it has a random password, must change it, and gets an activation
 * link valid for 24 hours by e-mail. It cannot sign in before it is activated.
 */
class StudentAccounts
{
    /** How long the activation link works. */
    public const LINK_HOURS = 24;

    /**
     * Why these details cannot become a new account, or null when they can. Checked in the
     * order an import reports them: name, e-mail, Neptun code, year, then accounts that exist.
     */
    public function problem(string $name, string $email, string $neptun, mixed $year = null): ?string
    {
        if (trim($name) === '') {
            return __('jobs.students.the_full_name_is_missing');
        }

        $email = Str::lower(trim($email));
        $neptun = Str::upper(trim($neptun));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            return __('jobs.students.invalid_email_address_format');
        }

        if (! preg_match('/^[A-Z0-9]{6}$/', $neptun)) {
            return __('jobs.students.the_neptun_code_must');
        }

        if ($year !== null && $year !== '' && ! (is_numeric($year) && (int) $year == $year && $year >= 1 && $year <= 6)) {
            return __('jobs.students.the_year_of_study_must');
        }

        if (User::where('neptun_code', $neptun)->exists()) {
            return __('jobs.students.neptun_registered', ['code' => $neptun]);
        }

        if (User::where('email', $email)->exists()) {
            return __('jobs.students.this_email_address_is_already');
        }

        return null;
    }

    /**
     * Create the account and send the invitation. The e-mail goes out once the surrounding
     * transaction (if any) is committed, so a rolled-back account gets no invitation.
     */
    public function invite(string $name, string $email, string $neptun, ?string $major = null, int|string|null $year = null, ?int $facultyId = null): User
    {
        $token = Str::random(64);

        $user = DB::transaction(function () use ($name, $email, $neptun, $major, $year, $facultyId, $token) {
            $user = User::create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'neptun_code' => Str::upper(trim($neptun)),
                'major' => ($major !== null && trim($major) !== '') ? trim($major) : null,
                'year_of_study' => ($year !== null && $year !== '') ? (int) $year : null,
                'faculty_id' => $facultyId,
                'password' => Hash::make(Str::random(32)),
                'status' => 'invited',
                'must_change_password' => true,
                'activation_token' => $token,
                'activation_token_expires_at' => now()->addHours(self::LINK_HOURS),
            ]);

            $user->assignRole(UserRole::Student);

            return $user;
        });

        DB::afterCommit(fn () => $user->notify(new StudentInvitationNotification($token, self::LINK_HOURS)));

        return $user;
    }

    /**
     * A new activation link for an account that is still "invited" (Task #104): the old link
     * stops working, and the same invitation e-mail is sent again.
     */
    public function resend(User $user): void
    {
        $token = Str::random(64);

        $user->forceFill([
            'activation_token' => $token,
            'activation_token_expires_at' => now()->addHours(self::LINK_HOURS),
        ])->save();

        DB::afterCommit(fn () => $user->notify(new StudentInvitationNotification($token, self::LINK_HOURS)));
    }
}
