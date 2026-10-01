<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\StudentInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bulk import of students (UC-3.2.1, Technical Specification 7.3.1).
 *
 * Columns: Full Name, Email, Neptun Code (required), Major, Year of Study (optional).
 * The import is idempotent: a Neptun code or email address that already exists is
 * skipped. Every new student gets the "Invited" status and an activation e-mail.
 */
class ImportStudentsJob extends ImportCsvJob
{
    private int $imported = 0;

    protected function requiredColumns(): array
    {
        return ['full_name', 'email', 'neptun_code'];
    }

    protected function requiredColumnLabels(): array
    {
        return ['Full Name', 'Email', 'Neptun Code'];
    }

    protected function title(): string
    {
        return 'Student import';
    }

    protected function prepare(): void
    {
        $this->imported = 0;
    }

    protected function processRow(array $row, int $line): void
    {
        $name = (string) ($row['full_name'] ?? '');
        $email = Str::lower((string) ($row['email'] ?? ''));
        $neptun = Str::upper((string) ($row['neptun_code'] ?? ''));
        $major = (string) ($row['major'] ?? '');
        $year = $row['year_of_study'] ?? null;

        if ($name === '') {
            $this->skip($line, 'The full name is missing.');

            return;
        }

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->skip($line, 'Invalid email address format.');

            return;
        }

        if (! preg_match('/^[A-Z0-9]{6}$/', $neptun)) {
            $this->skip($line, 'The Neptun code must be exactly 6 alphanumeric characters.');

            return;
        }

        if ($year !== null && $year !== '' && ! (is_numeric($year) && (int) $year == $year && $year >= 1 && $year <= 6)) {
            $this->skip($line, 'The year of study must be a number from 1 to 6.');

            return;
        }

        if (User::where('neptun_code', $neptun)->exists()) {
            $this->skip($line, "Neptun Code {$neptun} is already registered.");

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->skip($line, 'This email address is already registered in the system.');

            return;
        }

        $token = Str::random(64);

        $user = DB::transaction(function () use ($name, $email, $neptun, $major, $year, $token) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'neptun_code' => $neptun,
                'major' => $major !== '' ? $major : null,
                'year_of_study' => ($year !== null && $year !== '') ? (int) $year : null,
                'password' => Hash::make(Str::random(32)),
                'status' => 'invited',
                'must_change_password' => true,
                'activation_token' => $token,
                'activation_token_expires_at' => now()->addHours(24),
            ]);

            $user->assignRole(UserRole::Student);

            return $user;
        });

        $user->notify(new StudentInvitationNotification($token, 24));

        $this->imported++;
    }

    protected function summary(): string
    {
        return 'Successfully imported '.number_format($this->imported).' students.';
    }
}
