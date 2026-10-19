<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A rule of the event, points or QR flow said no. The message is written for
 * the person who triggered it and is safe to show; the code is for callers and tests.
 */
class EventActionRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
