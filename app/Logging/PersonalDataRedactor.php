<?php

namespace App\Logging;

/**
 * Takes personal data out of text that is about to be written to a log (Task #62, GDPR).
 *
 * Our own code never puts a name, an address or a Neptun code into a log or an error message
 * (NoPersonalDataInLogsTest looks for it), and a failed query no longer carries the values it
 * saved (mask_bindings_in_exception_messages, config/database.php). This is what is left to
 * catch: the text of errors that other code writes. A mail server that refuses a recipient
 * says whose address it was, and MySQL says which value was a duplicate.
 *
 * It works on the finished line, so it also reaches the message of an exception and its stack
 * trace. A name cannot be recognised in a text, so it is not looked for: it is kept out of the
 * messages instead.
 */
final class PersonalDataRedactor
{
    public const EMAIL = '[email]';

    public const VALUE = '[value]';

    /**
     * @return mixed the same kind of value, with the personal data taken out of every text in it
     */
    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::clean(...), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace(
            [
                // An e-mail address, wherever it stands.
                '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/',
                // MySQL and MariaDB: Duplicate entry 'ANNA01' for key 'users.users_neptun_code_unique'
                "/Duplicate entry '.*?' for key/s",
                // PostgreSQL: Key (neptun_code)=(ANNA01) already exists
                '/Key \(([^)]*)\)=\(.*?\) already exists/s',
            ],
            [
                self::EMAIL,
                "Duplicate entry '".self::VALUE."' for key",
                'Key ($1)=('.self::VALUE.') already exists',
            ],
            $value,
        ) ?? $value;
    }
}
