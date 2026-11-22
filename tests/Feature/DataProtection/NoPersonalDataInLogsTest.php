<?php

namespace Tests\Feature\DataProtection;

use App\Logging\PersonalDataRedactor;
use App\Logging\RedactingFormatter;
use App\Logging\RedactPersonalData;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Task #62 (GDPR, Technical Specification 8.6): no personal data in the logs.
 *
 * A log line is written by our own code, by the framework or by a library, and each of them can
 * carry a name, an address or a Neptun code: a failed query holds the values it saved, a mail
 * server names the recipient it refused, a stack trace shows the start of a text argument, and
 * the web server writes the visitor's address. The tests below look at each of these. What the
 * platform stores and shows is in docs/data-protection.md.
 */
class NoPersonalDataInLogsTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    private string|false $exceptionArgs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'gup-log-');
        // What docker/php/data-protection.ini sets in the containers: a trace shows no argument values.
        $this->exceptionArgs = ini_set('zend.exception_ignore_args', '1');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->exceptionArgs === false ? '0' : $this->exceptionArgs);
        @unlink($this->logFile);

        parent::tearDown();
    }

    /**
     * What the "single" channel writes for $write, through the real channel and its tap.
     */
    private function written(callable $write): string
    {
        config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);
        Log::forgetChannel('single');

        $write();

        return (string) file_get_contents($this->logFile);
    }

    /**
     * A failed query: a second account with the e-mail address of the first.
     */
    private function failedQuery(): QueryException
    {
        User::factory()->create(['email' => 'quixote.zephyrine@example.test']);

        try {
            User::factory()->create(['name' => 'Quixote Zephyrine', 'email' => 'quixote.zephyrine@example.test']);
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('The second account should have been refused.');
    }

    // ---------------------------------------------------------------- the database

    public function test_a_failed_query_does_not_carry_the_values_that_were_saved(): void
    {
        $message = $this->failedQuery()->getMessage();

        $this->assertStringNotContainsString('quixote', strtolower($message));
        $this->assertStringNotContainsString('Zephyrine', $message);
        // What went wrong and which statement stays, so the message is still useful.
        $this->assertStringContainsString('UNIQUE constraint failed: users.email', $message);
        $this->assertStringContainsString('insert into "users"', $message);
        $this->assertStringContainsString('values (?, ?', $message);
    }

    public function test_every_database_connection_hides_the_values_in_error_messages(): void
    {
        foreach (config('database.connections') as $name => $connection) {
            $this->assertTrue(
                $connection['mask_bindings_in_exception_messages'] ?? false,
                "The {$name} connection puts the saved values into the message of a failed query.",
            );
        }
    }

    // ---------------------------------------------------------------- the log

    public function test_an_error_report_reaches_the_log_without_the_values_of_the_failed_query(): void
    {
        $exception = $this->failedQuery();

        $log = $this->written(fn () => report($exception));

        $this->assertStringContainsString('UNIQUE constraint failed: users.email', $log);
        $this->assertStringContainsString('insert into "users"', $log);
        $this->assertStringNotContainsString('quixote', strtolower($log));
        $this->assertStringNotContainsString('Zephyrine', $log);
    }

    public function test_the_log_takes_an_email_address_out_of_whatever_is_written(): void
    {
        $log = $this->written(function () {
            Log::channel('single')->warning('Could not deliver to anna.nagy@example.test', [
                'recipient' => 'bence.toth@example.test',
                'nested' => ['list' => ['erik.molnar@example.test']],
            ]);
            // A mail server says whose address it refused, in the message of the exception.
            Log::channel('single')->error('The mail server said no', [
                'exception' => new RuntimeException('550 5.1.1 <csilla.varga@example.test>: Recipient address rejected'),
            ]);
        });

        foreach (['anna.nagy', 'bence.toth', 'erik.molnar', 'csilla.varga', 'example.test'] as $part) {
            $this->assertStringNotContainsString($part, $log);
        }

        $this->assertStringContainsString('Could not deliver to '.PersonalDataRedactor::EMAIL, $log);
        // What happened is still there.
        $this->assertStringContainsString('Recipient address rejected', $log);
    }

    public function test_the_log_takes_the_value_of_a_duplicate_entry_out(): void
    {
        $log = $this->written(function () {
            Log::channel('single')->error("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ANNA01' for key 'users.users_neptun_code_unique'");
            Log::channel('single')->error('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_neptun_code_unique" DETAIL: Key (neptun_code)=(BENC02) already exists.');
        });

        $this->assertStringNotContainsString('ANNA01', $log);
        $this->assertStringNotContainsString('BENC02', $log);
        $this->assertStringContainsString("Duplicate entry '[value]' for key 'users.users_neptun_code_unique'", $log);
        $this->assertStringContainsString('Key (neptun_code)=([value]) already exists', $log);
    }

    public function test_a_line_without_personal_data_is_written_as_it_was(): void
    {
        $log = $this->written(fn () => Log::channel('single')->warning(
            'The leaderboard cache is not available, so the totals are worked out on each request.',
            ['store' => 'redis', 'error' => 'Connection refused'],
        ));

        $this->assertStringContainsString('The leaderboard cache is not available, so the totals are worked out on each request.', $log);
        $this->assertStringContainsString('"store":"redis","error":"Connection refused"', $log);
        $this->assertStringNotContainsString(PersonalDataRedactor::EMAIL, $log);
        $this->assertStringNotContainsString(PersonalDataRedactor::VALUE, $log);
    }

    public function test_the_redaction_leaves_numbers_and_empty_values_alone(): void
    {
        $this->assertSame(
            ['mail' => '[email]', 'count' => 5, 'none' => null, 'on' => true],
            PersonalDataRedactor::clean(['mail' => 'anna@example.test', 'count' => 5, 'none' => null, 'on' => true]),
        );
    }

    public function test_every_log_channel_that_writes_text_takes_personal_data_out(): void
    {
        $exempt = [
            'stack' => 'its own channels have the tap',
            'slack' => 'its handler builds the message itself, and the channel is not used here',
            'null' => 'writes nothing',
            'emergency' => 'Laravel builds it in code when a channel fails',
        ];

        foreach (config('logging.channels') as $name => $channel) {
            if (array_key_exists($name, $exempt)) {
                continue;
            }

            $this->assertContains(
                RedactPersonalData::class,
                $channel['tap'] ?? [],
                "The {$name} channel writes text without taking personal data out. Add the tap, as the other channels have it.",
            );
        }

        // And the handlers that are really built carry it.
        foreach (['single', 'stderr'] as $name) {
            Log::forgetChannel($name);

            $formatter = Log::channel($name)->getLogger()->getHandlers()[0]->getFormatter();

            $this->assertInstanceOf(RedactingFormatter::class, $formatter, "The {$name} channel.");
        }
    }

    // ---------------------------------------------------------------- our own code

    private const PERSONAL = '/->(?:email|name|neptun_code|first_name|last_name)\b'
        .'|\$(?:email|neptun|neptunCode|firstName|lastName)\b'
        .'|\[\s*[\'"](?:email|name|neptun_code|first_name|last_name)[\'"]\s*\]/';

    private function withoutComments(string $code): string
    {
        $clean = '';

        foreach (token_get_all($code) as $token) {
            $clean .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
        }

        return $clean;
    }

    /**
     * The text from the opening to the closing parenthesis that starts at $open, texts skipped.
     */
    private function arguments(string $code, int $open): string
    {
        $depth = 0;
        $quote = null;

        for ($i = $open, $length = strlen($code); $i < $length; $i++) {
            $char = $code[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }

        return substr($code, $open);
    }

    /**
     * The calls in $code that write a log or build an error, each with its arguments.
     *
     * @return list<string>
     */
    private function callsThatWrite(string $code): array
    {
        $code = $this->withoutComments($code);

        // The ways a log is written: Log::error(), Log::channel('x')->error(), logger()->error(), logger(),
        // report() and the creation of an exception. A chained call is named with the call before it.
        preg_match_all(
            '/(?:\blogger\s*\(\s*\)\s*->\s*\w+|\bLog::channel\s*\([^)]*\)\s*->\s*\w+|\bLog::\w+|\blogger|\breport|\bnew\s+\\\\?[A-Za-z\\\\]*Exception)\s*\(/',
            $code,
            $found,
            PREG_OFFSET_CAPTURE,
        );

        return array_map(
            fn (array $match) => $match[0].$this->arguments($code, $match[1] + strlen($match[0]) - 1),
            $found[0],
        );
    }

    public function test_our_code_never_puts_a_name_an_address_or_a_neptun_code_into_a_log_or_an_error(): void
    {
        $files = Finder::create()->files()->name('*.php')->in([base_path('app'), base_path('routes'), base_path('bootstrap')])->exclude('cache');
        $found = [];

        foreach ($files as $file) {
            foreach ($this->callsThatWrite($file->getContents()) as $call) {
                if (preg_match(self::PERSONAL, $call)) {
                    $found[] = $file->getRelativePathname().': '.Str::squish($call);
                }
            }
        }

        $this->assertSame([], $found, "These calls put personal data into a log or into the message of an error. Leave it out, an id says as much:\n".implode("\n", $found));
    }

    public function test_the_search_finds_a_call_that_writes_personal_data_and_leaves_the_others(): void
    {
        $bad = [
            'Log::warning("Import failed for {$user->email}");',
            "report(new RuntimeException('Account of '.\$student->name.' is not valid'));",
            'throw new RuntimeException("Account {$row[\'email\']} exists");',
            'logger()->error("No place for {$neptun}");',
            'Log::info(\'x\', [\'name\' => $user->name]);',
            'Log::channel(\'single\')->error("Refused for {$user->email}");',
        ];
        $fine = [
            "Log::warning('The leaderboard cache is not available', ['store' => 'redis']);",
            'report(new RuntimeException("An import file could not be saved to storage (topic {$topic->id})."));',
            "throw new LogicException('Ledger rows cannot be changed.');",
            '$user->name; // Log::info($user->email) in a comment',
            "\$title = \"It's \".\$student->name;",
        ];

        foreach ($bad as $code) {
            $this->assertTrue($this->flags($code), "Not found: {$code}");
        }

        foreach ($fine as $code) {
            $this->assertFalse($this->flags($code), "Wrongly found: {$code}");
        }
    }

    private function flags(string $code): bool
    {
        foreach ($this->callsThatWrite('<?php '.$code) as $call) {
            if (preg_match(self::PERSONAL, $call)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- the PHP containers

    private function composeService(string $service): string
    {
        preg_match('/^  '.$service.':\n((?:    .*\n|\n)*)/m', (string) file_get_contents(base_path('docker-compose.yml')), $block);

        return $block[1] ?? '';
    }

    public function test_a_stack_trace_in_the_containers_shows_no_argument_values(): void
    {
        $ini = (string) file_get_contents(base_path('docker/php/data-protection.ini'));

        $this->assertMatchesRegularExpression('/^\s*zend\.exception_ignore_args\s*=\s*(On|1|true)\s*$/mi', $ini);

        // Mounted into every container that runs the application. The one that builds the front end runs no PHP.
        foreach (['app', 'queue', 'scheduler'] as $service) {
            $this->assertStringContainsString(
                './docker/php/data-protection.ini:/usr/local/etc/php/conf.d/',
                $this->composeService($service),
                "The {$service} container does not get docker/php/data-protection.ini.",
            );
        }
    }

    // ---------------------------------------------------------------- failed jobs

    public function test_failed_jobs_are_deleted_after_three_days(): void
    {
        $runs = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'queue:prune-failed'));

        $this->assertCount(1, $runs);
        $this->assertSame(1, preg_match('/queue:prune-failed --hours=(\d+)$/', $runs->first()->command, $hours));
        $this->assertSame('72', $hours[1], 'A failed job is kept three days.');
        $this->assertSame('0 0 * * *', $runs->first()->expression);

        $failed = fn (string $exception, int $hoursAgo) => [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => $exception,
            'failed_at' => now()->subHours($hoursAgo),
        ];
        DB::table('failed_jobs')->insert([$failed('older than three days', 73), $failed('recent', 71)]);

        // The command as the scheduler runs it.
        $this->artisan('queue:prune-failed', ['--hours' => (int) $hours[1]])->assertSuccessful();

        $this->assertSame(['recent'], DB::table('failed_jobs')->pluck('exception')->all());
    }

    // ---------------------------------------------------------------- the web server

    /**
     * @return array<string, string> file => its text
     */
    private function nginxFiles(): array
    {
        return [
            'default.conf' => (string) file_get_contents(base_path('docker/nginx/default.conf')),
            'production.conf' => (string) file_get_contents(base_path('docker/nginx/production.conf')),
        ];
    }

    public function test_the_web_server_logs_a_shortened_address_and_no_query_string(): void
    {
        foreach ($this->nginxFiles() as $file => $conf) {
            $this->assertSame(1, preg_match('/log_format\s+anonymised\s+(.*?);/s', $conf, $format), "{$file} has no anonymised log format.");

            // What a line holds: the shortened address and the path, not the address, the query string,
            // the referrer or a cookie. (`map` is where the real values are turned into these.)
            $this->assertStringContainsString('$anonymised_addr', $format[1], $file);
            $this->assertStringContainsString('$logged_path', $format[1], $file);
            $this->assertDoesNotMatchRegularExpression(
                '/\$(?:remote_addr|request|request_uri|args|query_string|http_referer|http_cookie|remote_user)(?![A-Za-z0-9_])/',
                $format[1],
                "The log format of {$file} holds personal data.",
            );
            $this->assertStringContainsString('map $remote_addr $anonymised_addr', $conf, $file);
            $this->assertStringContainsString('map $request_uri $logged_path', $conf, $file);
        }
    }

    public function test_every_web_server_block_writes_its_access_log_in_that_format(): void
    {
        foreach ($this->nginxFiles() as $file => $conf) {
            $servers = preg_match_all('/^server\s*\{/m', $conf);
            $accessLogs = preg_match_all('/^\s*access_log\s+(.*);/m', $conf, $logs);

            // One per server block, because a block without its own line gets the image's default format.
            $this->assertGreaterThan(0, $servers, $file);
            $this->assertSame($servers, $accessLogs, "{$file}: every server block needs its own access_log.");

            foreach ($logs[1] as $directive) {
                $this->assertStringEndsWith(' anonymised', $directive, "{$file}: an access log in another format.");
            }
        }
    }

    // ---------------------------------------------------------------- mail

    public function test_no_example_or_compose_file_sends_mail_to_the_log(): void
    {
        // The log mailer writes the whole mail, with the name and the address of the person, into the log.
        foreach (['.env.example', 'docker-compose.yml', 'docker-compose.prod.yml'] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/MAIL_MAILER\s*[=:]\s*[\'"]?log\b/i',
                (string) file_get_contents(base_path($file)),
                "{$file} sends mail to the log.",
            );
        }
    }
}
