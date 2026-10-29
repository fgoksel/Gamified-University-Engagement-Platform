<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\HeadingRowImport;

/**
 * The heading row of a CSV file, read with the same settings as its rows (see ImportCsvJob::csvSettings()).
 */
class CsvHeadingsImport extends HeadingRowImport implements WithCustomCsvSettings
{
    /**
     * @param  array<string, mixed>  $settings  Empty: the reader works the settings out itself.
     */
    public function __construct(private array $settings = [])
    {
        parent::__construct();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCsvSettings(): array
    {
        return $this->settings;
    }
}
