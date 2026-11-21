<?php

namespace App\Jobs;

use App\Services\StudentAccounts;

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
        return __('jobs.students.student_import');
    }

    protected function prepare(): void
    {
        $this->imported = 0;
    }

    protected function processRow(array $row, int $line): void
    {
        $name = (string) ($row['full_name'] ?? '');
        $email = (string) ($row['email'] ?? '');
        $neptun = (string) ($row['neptun_code'] ?? '');
        $year = $row['year_of_study'] ?? null;
        $accounts = app(StudentAccounts::class);

        if (($problem = $accounts->problem($name, $email, $neptun, $year)) !== null) {
            $this->skip($line, $problem);

            return;
        }

        $accounts->invite($name, $email, $neptun, (string) ($row['major'] ?? ''), $year);

        $this->imported++;
    }

    protected function summary(): string
    {
        return __('jobs.students.imported', ['count' => number_format($this->imported)]);
    }
}
