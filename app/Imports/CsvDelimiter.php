<?php

namespace App\Imports;

/**
 * Which character separates the columns of a CSV file, read from its heading line.
 *
 * A file with only a "Neptun Code" column has no separator at all, and the reader
 * would then take the space in the heading for one. Exports with more columns use a
 * comma, a semicolon (Excel in Hungarian) or a tab. Used by the student import into a
 * topic (Task #72) and the points import (Task #85).
 */
final class CsvDelimiter
{
    public static function fromHeading(string $heading): string
    {
        return match (true) {
            str_contains($heading, ';') => ';',
            str_contains($heading, "\t") => "\t",
            default => ',',
        };
    }
}
