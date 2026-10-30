<?php

namespace App\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;

/**
 * A list of students as a CSV file with a "Neptun Code" column (Task #85), for example a
 * Neptun or Moodle export. Other columns are ignored. The file is read straight away with
 * the same reader as the other imports, so the organiser sees the result before anything
 * is saved.
 */
final class NeptunCodeFile
{
    /** More than any event list. A bigger file is a whole register and should be split. */
    public const MAX_ROWS = 5000;

    /** The code column: "Neptun Code", or "Neptun kód" from a Hungarian Neptun export. */
    private const COLUMNS = ['neptun_code', 'neptun_kod'];

    /**
     * The rows that are not blank, in file order. The first data row is line 2.
     *
     * @return list<array{line: int, code: string}> The code in upper case; empty when the cell is empty.
     *
     * @throws ValidationException when the file cannot be used at all
     */
    public static function read(UploadedFile $file): array
    {
        $heading = self::headingLine($file);

        if (trim($heading) === '') {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        // Counted before reading, so a huge file is refused at once instead of being read for minutes.
        if (self::lineCount($file) > self::MAX_ROWS + 1) {
            throw self::tooManyRows();
        }

        $settings = ['delimiter' => CsvDelimiter::fromHeading($heading)];
        $rows = [];

        try {
            $headings = Excel::toArray(new CsvHeadingsImport($settings), $file, null, ExcelFormat::CSV)[0][0] ?? [];
            $column = collect(self::COLUMNS)->first(fn (string $name) => in_array($name, $headings, true));

            if ($column === null) {
                throw ValidationException::withMessages(['file' => 'The first line of the file must name the columns, and one of them must be "Neptun Code".']);
            }

            Excel::import(new CsvRowsImport(function (array $row, int $line) use ($column, &$rows) {
                $rows[] = ['line' => $line, 'code' => Str::upper(trim((string) ($row[$column] ?? '')))];
            }, $settings), $file, null, ExcelFormat::CSV);
        } catch (SpreadsheetException) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Save it as a CSV file and try again.']);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has no Neptun codes below its first line.']);
        }

        // The quick count above may miss a last line without a line break, or old line endings.
        if (count($rows) > self::MAX_ROWS) {
            throw self::tooManyRows();
        }

        return $rows;
    }

    private static function tooManyRows(): ValidationException
    {
        return ValidationException::withMessages(['file' => 'The file has more than '.number_format(self::MAX_ROWS).' rows. Split it and import the parts one after the other.']);
    }

    private static function headingLine(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'r');
        $line = $handle ? (string) fgets($handle) : '';

        if ($handle) {
            fclose($handle);
        }

        return $line;
    }

    private static function lineCount(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'r');
        $lines = 0;

        while ($handle && ! feof($handle)) {
            $lines += substr_count((string) fread($handle, 65536), "\n");
        }

        if ($handle) {
            fclose($handle);
        }

        return $lines;
    }
}
