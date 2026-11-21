<?php

namespace App\Jobs;

use App\Models\Faculty;
use App\Models\User;
use App\Services\OrganizerAccounts;
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
        return __('jobs.organizers.organizer_import');
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
            $this->skip($line, __('jobs.organizers.the_full_name_is_missing'));

            return;
        }

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->skip($line, __('jobs.organizers.invalid_email_address_format'));

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->skip($line, __('jobs.organizers.this_email_address_is_already'));

            return;
        }

        $facultyId = null;
        $faculty = (string) ($row['faculty'] ?? '');

        if ($faculty !== '') {
            $facultyId = $this->faculties[Str::lower($faculty)] ?? null;

            if ($facultyId === null) {
                $this->note($line, __('jobs.organizers.faculty_not_found', ['faculty' => $faculty]));
            }
        }

        app(OrganizerAccounts::class)->invite($name, $email, $facultyId);

        $this->imported++;
    }

    protected function summary(): string
    {
        return __('jobs.organizers.imported', ['count' => number_format($this->imported)]);
    }
}
