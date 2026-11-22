<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formats a record the way the handler always did, then takes personal data out of the result
 * (Task #62, GDPR). Put in front of a handler's formatter by RedactPersonalData.
 */
final class RedactingFormatter implements FormatterInterface
{
    public function __construct(private readonly FormatterInterface $formatter) {}

    public function format(LogRecord $record): mixed
    {
        return PersonalDataRedactor::clean($this->formatter->format($record));
    }

    public function formatBatch(array $records): mixed
    {
        return PersonalDataRedactor::clean($this->formatter->formatBatch($records));
    }
}
