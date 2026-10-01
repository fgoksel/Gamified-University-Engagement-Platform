<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads a Neptun CSV file in chunks (Technical Specification 7.3.1, 8.1) and hands
 * every non-empty row to a callback together with its line number in the file.
 *
 * The first line holds the column names, so the first data row is line 2.
 * Headings such as "Full Name" arrive as "full_name".
 */
class CsvRowsImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    /** Rows read per chunk, so a large file never has to fit into memory at once. */
    public const CHUNK_SIZE = 500;

    private int $line = 1;

    /**
     * @param  callable(array<string, mixed>, int): void  $onRow
     */
    public function __construct(private $onRow) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $this->line++;

            $values = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row->toArray());

            if (collect($values)->filter(fn ($value) => $value !== null && $value !== '')->isEmpty()) {
                continue;
            }

            ($this->onRow)($values, $this->line);
        }
    }

    public function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }
}
