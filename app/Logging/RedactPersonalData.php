<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;

/**
 * Log channel tap (Task #62, GDPR): every handler of the channel writes through
 * PersonalDataRedactor. Named in the "tap" of each channel in config/logging.php.
 */
class RedactPersonalData
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $formatter = $handler->getFormatter();

            // Once is enough, also when a channel is built again.
            if (! $formatter instanceof RedactingFormatter) {
                $handler->setFormatter(new RedactingFormatter($formatter));
            }
        }
    }
}
