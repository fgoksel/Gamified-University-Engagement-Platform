<?php

namespace App\Jobs;

use App\Imports\CsvRowsImport;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Throwable;

/**
 * Shared behaviour of the three Neptun CSV import jobs (Technical Specification 5.6, 7.3.1, 8.1, 8.4).
 *
 * A job reads the uploaded file in chunks, processes it row by row, tells the
 * administrator how it went and removes the file. Technical failures are
 * retried 3 times (10 s, 60 s, 300 s) before landing in failed_jobs; the
 * administrator is then told that the import failed.
 */
abstract class ImportCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How many rows are listed in the message to the administrator. */
    private const MAX_LISTED_WARNINGS = 20;

    public int $tries = 3;

    /** A file of up to 10 MB must not be cut off by the worker (Technical Specification 7.3.1). */
    public int $timeout = 900;

    /** @var list<string> */
    protected array $warnings = [];

    protected int $skipped = 0;

    private int $hiddenWarnings = 0;

    /**
     * @param  string  $path  Path of the uploaded CSV on the given disk.
     * @param  int  $adminId  The administrator who started the import and receives the result.
     */
    public function __construct(
        public readonly string $path,
        public readonly int $adminId,
        public readonly string $disk = 'local',
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Names of the columns the file must have, as slugs (for example "full_name").
     *
     * @return list<string>
     */
    abstract protected function requiredColumns(): array;

    /**
     * Human readable column names, used in the error for a wrong file structure.
     *
     * @return list<string>
     */
    abstract protected function requiredColumnLabels(): array;

    /**
     * Title of the notification, for example "Organizer import".
     */
    abstract protected function title(): string;

    /**
     * Handle one row. Call skip() for rows that cannot be imported.
     *
     * @param  array<string, mixed>  $row
     */
    abstract protected function processRow(array $row, int $line): void;

    /**
     * Runs once before the first row (for example to look up the current semester).
     */
    protected function prepare(): void {}

    /**
     * Runs after the last row; returns the summary sentence for the administrator.
     */
    abstract protected function summary(): string;

    public function handle(): void
    {
        $this->warnings = [];
        $this->skipped = 0;
        $this->hiddenWarnings = 0;

        try {
            $this->assertFileStructure();
            $this->prepare();
        } catch (InvalidArgumentException $e) {
            // Not a technical problem: the same file would fail again, so it is not retried.
            $this->notifyAdmin($this->title().' failed', $e->getMessage(), false);
            $this->deleteFile();
            $this->delete();

            return;
        }

        Excel::import(
            new CsvRowsImport(fn (array $row, int $line) => $this->processRow($row, $line)),
            $this->path,
            $this->disk,
            \Maatwebsite\Excel\Excel::CSV,
        );

        $this->notifyAdmin($this->title().' finished', $this->summary().$this->skippedRowsText(), true);
        $this->deleteFile();
    }

    /**
     * Called by the queue after the last retry: tell the administrator (Technical Specification 8.4).
     */
    public function failed(?Throwable $exception): void
    {
        $this->notifyAdmin(
            $this->title().' failed',
            'The import could not be completed because of a technical error. Please try again or contact support.',
            false,
        );
        $this->deleteFile();
    }

    protected function skip(int $line, string $reason): void
    {
        $this->skipped++;
        $this->addWarning("Row {$line} skipped: {$reason}");
    }

    /**
     * A row that was imported, but the administrator should know about something.
     */
    protected function note(int $line, string $text): void
    {
        $this->addWarning("Row {$line}: {$text}");
    }

    private function addWarning(string $text): void
    {
        if (count($this->warnings) < self::MAX_LISTED_WARNINGS) {
            $this->warnings[] = $text;
        } else {
            $this->hiddenWarnings++;
        }
    }

    /**
     * An incorrect file structure blocks the whole import (Functional Specification UC-3.1.1, UC-3.2.1).
     */
    private function assertFileStructure(): void
    {
        $headings = Excel::toArray(new HeadingRowImport, $this->path, $this->disk, \Maatwebsite\Excel\Excel::CSV)[0][0] ?? [];

        if (array_diff($this->requiredColumns(), $headings) !== []) {
            throw new InvalidArgumentException(
                'Incorrect file structure. The CSV file needs the columns: '.implode(', ', $this->requiredColumnLabels()).'.'
            );
        }
    }

    private function skippedRowsText(): string
    {
        $text = $this->skipped > 0 ? " {$this->skipped} row(s) were skipped." : '';

        foreach ($this->warnings as $warning) {
            $text .= "\n".$warning;
        }

        if ($this->hiddenWarnings > 0) {
            $text .= "\n... and {$this->hiddenWarnings} more.";
        }

        return $text;
    }

    private function notifyAdmin(string $title, string $body, bool $success): void
    {
        $admin = User::find($this->adminId);

        if ($admin === null) {
            return;
        }

        $notification = Notification::make()->title($title)->body($body);

        ($success ? $notification->success() : $notification->danger())->sendToDatabase($admin);
    }

    private function deleteFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
