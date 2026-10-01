<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\OrganizerInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bulk import of organizers (UC-3.1.1, Technical Specification 7.3.1).
 *
 * Columns: Full Name, Email (required), Faculty (optional, faculty name or code).
 * The import is idempotent: an email address that already exists is skipped.
 * Every new organizer gets the "Invited" status and an activation e-mail.
 */
class ImportOrganizersJob extends ImportCsvJob
{
    private int $imported = 0;

    /** @var array<string, int> faculty name / code (lower case) => id */
    private array $faculties = [];

    protected function requiredColumns(): array
    {
        return ['full_name', 'email'];
    }

    protected function requiredColumnLabels(): array
    {
        return ['Full Name', 'Email'];
    }

    protected function title(): string
    {
        return 'Organizer import';
    }

    protected function prepare(): void
    {
        $this->imported = 0;
        $this->faculties = [];

        foreach (Faculty::all(['id', 'name', 'code']) as $faculty) {
            $this->faculties[Str::lower($faculty->name)] = $faculty->id;
            $this->faculties[Str::lower((string) $faculty->code)] = $faculty->id;
        }
    }

    protected function processRow(array $row, int $line): void
    {
        $name = (string) ($row['full_name'] ?? '');
        $email = Str::lower((string) ($row['email'] ?? ''));

        if ($name === '') {
            $this->skip($line, 'The full name is missing.');

            return;
        }

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->skip($line, 'Invalid email address format.');

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->skip($line, 'This email address is already registered in the system.');

            return;
        }

        $facultyId = null;
        $faculty = (string) ($row['faculty'] ?? '');

        if ($faculty !== '') {
            $facultyId = $this->faculties[Str::lower($faculty)] ?? null;

            if ($facultyId === null) {
                $this->note($line, "Faculty \"{$faculty}\" was not found, the organizer was imported without a faculty.");
            }
        }

        $token = Str::random(64);

        $user = DB::transaction(function () use ($name, $email, $facultyId, $token) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
                'faculty_id' => $facultyId,
                'status' => 'invited',
                'must_change_password' => true,
                'activation_token' => $token,
                'activation_token_expires_at' => now()->addHours(24),
            ]);

            $user->assignRole(UserRole::Teacher);

            return $user;
        });

        $user->notify(new OrganizerInvitationNotification($token, 24));

        $this->imported++;
    }

    protected function summary(): string
    {
        return 'Successfully imported '.number_format($this->imported).' organizers.';
    }
}
