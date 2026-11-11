<?php

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Task #95: no interface text is typed into the code. A text a person reads comes from
 * lang/en.json through __(), trans_choice() or the Vue t() / <Trans>.
 *
 * This is the PHP and Blade half of "checked by a lint rule or search" (the Vue half is the
 * ESLint rules in eslint.config.js). It reads every PHP file the application runs on and every
 * Blade view, and fails when a typed text sits where a person reads it:
 *
 *   1. in the places where PHP hands a text to a person (a message after saving, a refusal, a
 *      line of an email, a label in the admin panel),
 *   2. as any string that reads like English text, wherever it is, unless it is one of the few
 *      things that look like text but are not (listed below, each with the reason),
 *   3. as text or a text attribute in a Blade view.
 *
 * A text that is only meant for developers (the message of a LogicException) is not interface
 * text. Seeders, factories, migrations and config hold data, not screens, and are not read.
 */
class HardCodedTextTest extends TestCase
{
    /** The PHP code the application runs on. */
    private const PHP_FOLDERS = ['app', 'bootstrap', 'routes'];

    /** Blade views, the ones for emails included. */
    private const VIEW_FOLDER = 'resources/views';

    /**
     * Not read: command-line tools are for developers and operators, not for the people who use the
     * screens; the cache is generated; the upgrade of the old faculty tree writes names and
     * descriptions of roles into the database, rows that an administrator can edit afterwards.
     */
    private const NOT_READ = [
        'app/Console',
        'routes/console.php',
        'bootstrap/cache',
        'app/Support/LegacyTreeUpgrade.php',
    ];

    /**
     * Exceptions that tell a developer what went wrong. A person never reads them, so their
     * message may stay in English. Any other exception (InvalidArgumentException, an
     * AuthorizationException, ...) can reach a screen and must use the dictionary.
     */
    private const FOR_DEVELOPERS = [
        'LogicException', 'RuntimeException', 'DomainException', 'BadMethodCallException',
        'BadFunctionCallException', 'UnexpectedValueException', 'OutOfBoundsException',
        'OutOfRangeException', 'LengthException', 'OverflowException', 'UnderflowException',
    ];

    /**
     * The headings of the CSV files the importers read. They are the file format that was agreed,
     * not text on a screen, and an importer matches them by name.
     */
    private const CSV_HEADINGS = ['Full Name', 'Email', 'Faculty', 'Neptun Code', 'Major', 'Year of Study', 'Course Code', 'Course Name'];

    /**
     * Strings that read like text but are not interface text, by file, with the reason.
     * A line that no longer matches anything fails the test, so the list cannot go stale.
     */
    private const NOT_INTERFACE_TEXT = [
        'app/Http/Controllers/Admin/ImportController.php' => [
            // The headings of the sample CSV files, and the made-up rows under them.
            ...self::CSV_HEADINGS,
            'Dr. Kovács István', 'Faculty of Engineering and Information Technology', 'Nagy Katalin',
            'Faculty of Sciences', 'Computer Science BSc', 'Kiss Anna', 'Electrical Engineering BSc',
            'Database Systems',
        ],
        'app/Jobs/ImportCourseEnrollmentsJob.php' => ['Neptun Code', 'Course Code', 'Course Name'],
        'app/Jobs/ImportOrganizersJob.php' => ['Full Name', 'Email'],
        'app/Jobs/ImportStudentsJob.php' => ['Full Name', 'Email', 'Neptun Code'],
        'app/Jobs/ImportTopicStudentsJob.php' => ['Neptun Code'],
        // The font family of the admin panel.
        'app/Providers/Filament/AdminPanelProvider.php' => ['Instrument Sans'],
        // SQL, and a line for the log: they are for developers.
        'app/Services/PointCalculationService.php' => [
            'The leaderboard cache is not available, so the totals are worked out on each request.',
        ],
        // The name of a page component, not a text.
        'app/Http/Controllers/LeaderboardController.php' => ['Home'],
    ];

    /**
     * Where PHP hands a text to a person: description => pattern, run over the code without comments.
     */
    private const PLACES = [
        'a message kept for the next page: ->with(\'error\', \'text\')' => '/->with\(\s*[\'"](?:success|error|warning|info|status|message)[\'"]\s*,\s*[\'"]/',
        'the text of an error page: abort(403, \'text\')' => '/\babort(?:_if|_unless)?\(\s*(?:[^;()]*?,\s*)?\d{3}\s*,\s*[\'"]/',
        'a line of an email: ->subject(\'text\'), ->line(\'text\'), ->action(\'text\', $url)' => '/->(?:subject|greeting|salutation|line|action)\(\s*[\'"]/',
        'a message of a form: withMessages([\'field\' => \'text\']), withErrors(...)' => '/(?:withMessages|withErrors)\(\s*\[[^\]]*?=>\s*[\'"]/s',
        'a refusal: new EventActionRefused(\'code\', \'text\')' => '/new EventActionRefused\(\s*[\'"]\w+[\'"]\s*,\s*[\'"]/',
        'a text sent to the screen: [\'message\' => \'text\']' => '/[\'"](?:message|title|description|label|hint|heading|body)[\'"]\s*=>\s*[\'"][A-Za-z]/',
        'a text used when nothing is set: ?? \'text\'' => '/\?\?\s*[\'"][A-Z][a-z]+[^\'"]*[\'"]/',
        'a text of the admin panel: ->label(\'text\'), ->title(\'text\'), ->body(\'text\')' => '/->(?:label|title|body|heading|subheading|description|placeholder|helperText|hint|tooltip|modalHeading|modalDescription|modalSubmitActionLabel|successNotificationTitle|failureNotificationTitle|emptyStateHeading|emptyStateDescription|navigationLabel|brandName)\(\s*[\'"][A-Za-z]/',
        'a date written with the English names of days and months: ->format(\'D j M\'), use translatedFormat()' => '/->format\(\s*[\'"][^\'"]*(?<!\\\\)[DlFM][^\'"]*[\'"]/',
        'a text made out of a value: ucfirst($status), Str::headline($status)' => '/\b(?:ucfirst|ucwords)\(|Str::(?:headline|title|plural|singular)\(|->(?:headline|plural|singular)\(/',
    ];

    /**
     * Attributes of an HTML element or a Blade component whose value a person reads.
     */
    private const TEXT_ATTRIBUTES = 'title|alt|placeholder|aria-label|aria-description|aria-roledescription|label|heading|subheading|description|content|summary|tooltip|caption|legend|text|message';

    public function test_no_interface_text_is_typed_where_php_hands_a_text_to_a_person(): void
    {
        $found = [];

        foreach ($this->phpFiles() as $path => $source) {
            foreach ($this->typedPlaces($source) as [$line, $place]) {
                $found[] = "{$path}:{$line} {$place}";
            }
        }

        $this->assertSame([], $found, "Typed interface text. Add it to lang/en.json and use __('area.key'):\n".implode("\n", $found));
    }

    public function test_no_string_that_reads_like_interface_text_is_left_in_the_php_code(): void
    {
        $found = [];

        foreach ($this->phpFiles() as $path => $source) {
            foreach ($this->textInCode($source) as [$line, $text]) {
                if (! in_array($text, self::NOT_INTERFACE_TEXT[$path] ?? [], true)) {
                    $found[] = "{$path}:{$line} \"".trim(preg_replace('/\s+/', ' ', $text)).'"';
                }
            }
        }

        $this->assertSame([], $found, "A string that reads like text for a person. Add it to lang/en.json and use __('area.key'). If it is not interface text, add it to NOT_INTERFACE_TEXT with the reason:\n".implode("\n", $found));
    }

    public function test_every_entry_of_the_list_of_exceptions_is_still_needed(): void
    {
        $used = [];

        foreach ($this->phpFiles() as $path => $source) {
            foreach ($this->textInCode($source) as [, $text]) {
                $used[$path][$text] = true;
            }
        }

        $stale = [];

        foreach (self::NOT_INTERFACE_TEXT as $path => $texts) {
            $this->assertFileExists(base_path($path), "{$path} is on the list of exceptions but does not exist.");

            foreach ($texts as $text) {
                if (! isset($used[$path][$text])) {
                    $stale[] = "{$path}: \"{$text}\"";
                }
            }
        }

        $this->assertSame([], $stale, "These entries of NOT_INTERFACE_TEXT match nothing any more. Take them off the list:\n".implode("\n", $stale));
    }

    public function test_no_interface_text_is_typed_into_a_blade_view(): void
    {
        $found = [];

        foreach ($this->viewFiles() as $path => $source) {
            foreach ($this->textInView($source) as [$line, $text]) {
                $found[] = "{$path}:{$line} \"{$text}\"";
            }
        }

        $this->assertSame([], $found, "Typed interface text in a view. Add it to lang/en.json and use {{ __('area.key') }}:\n".implode("\n", $found));
    }

    public function test_the_search_finds_typed_text_and_leaves_the_dictionary_alone(): void
    {
        $typed = [
            "redirect()->back()->with('error', 'Something went wrong.');",
            "abort(403, 'Not allowed.');",
            "abort_if(\$closed, 409, 'Closed.');",
            "(new MailMessage)->subject('Hello')->line('Welcome.')->action('Open', \$url);",
            "throw ValidationException::withMessages(['email' => 'Wrong.']);",
            "back()->withErrors(['email' => 'Wrong.']);",
            "new EventActionRefused('closed', 'The event is closed.', 409);",
            "return response()->json(['message' => 'Done.']);",
            "TextInput::make('name')->label('Full name');",
            "Notification::make()->title('Saved')->success();",
            "\$name = \$faculty?->name ?? 'University-wide';",
            '$label = ucfirst($status);',
            "\$days = \$left.' '.str('day')->plural(\$left);",
            "\$when = \$event->starts_at->format('D j M Y, H:i');",
        ];

        foreach ($typed as $code) {
            $this->assertNotEmpty($this->typedPlaces("<?php\n{$code}"), "The search for places missed: {$code}");
        }

        $fine = [
            "redirect()->back()->with('error', __('events.closed'));",
            "abort(403, __('auth.account.deactivated'));",
            'abort(404);',
            "(new MailMessage)->subject(__('mail.password_reset.subject'))->line(trans_choice('mail.x.valid', 2));",
            "throw ValidationException::withMessages(['email' => __('auth.login.failed')]);",
            "new EventActionRefused('closed', __('events.closed'), 409);",
            "return response()->json(['message' => \$refused->getMessage()]);",
            "TextInput::make('name')->label(__('admin.name'));",
            "\$name = \$faculty?->name ?? __('subject_areas.university_wide');",
            "\$code = \$student->neptun_code ?? 'N/A';",
            "throw new LogicException('Ledger rows cannot be changed.');",
            "// ->with('error', 'A comment is not interface text.')",
            "/* abort(403, 'Nor is this one.'); */",
        ];

        foreach ($fine as $code) {
            $this->assertSame([], $this->typedPlaces("<?php\n{$code}"), "The search for places is wrong about: {$code}");
        }
    }

    public function test_the_search_for_text_like_strings_finds_typed_text_and_leaves_the_rest_alone(): void
    {
        $typed = [
            "\$label = \$active ? 'Active' : 'Inactive';",
            "\$title = \$name.' failed';",
            "\$line = 'Row skipped: '.\$reason;",
            '$text = "Imported {$count} students.";',
            "throw new InvalidArgumentException('Choose a file.');",
            "throw new AuthorizationException('Only an admin may do this.');",
            "\$options = ['pending' => 'Pending', 'approved' => 'Approved'];",
            "return ['label' => 'Sign in'];",
        ];

        foreach ($typed as $code) {
            $this->assertNotEmpty($this->textInCode("<?php\n{$code}"), "The search for text-like strings missed: {$code}");
        }

        $fine = [
            "throw new LogicException('Ledger rows cannot be changed. Add a reversal instead.');",
            'throw new \\RuntimeException("The tree has a cycle at unit {$id}. Repair it.");',
            "report(new RuntimeException('Could not be saved.'));",
            "\$label = __('events.status.active');",
            "\$label = trans_choice('events.students', \$count);",
            "\$rules = ['topic' => 'required|exists:topics,id', 'type' => 'in:passport,manual', 'file' => 'max:'.\$kb];",
            "\$query->with('owningTopic:id,title')->withCount('applications as accepted_count');",
            "\$query->join('topic_closure as c', 'c.id', '=', 'a.id')->selectRaw('status, count(*) as total');",
            "\$headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store'];",
            "\$when = \$event->starts_at->translatedFormat('D j M Y, H:i');",
            "\$link = '<a href=\"/sample.csv\" class=\"underline font-medium\" download>'.e(\$text).'</a>';",
            "\$route = 'filament.admin.pages.dashboard';",
            "\$state = \$user->status === 'active';",
            "\$when = \$event->starts_at->format('D j M Y, H:i');",
            "\$email = 'student@example.test';",
            "\$class = 'App\\Models\\Event';",
        ];

        foreach ($fine as $code) {
            $this->assertSame([], $this->textInCode("<?php\n{$code}"), "The search for text-like strings is wrong about: {$code}");
        }
    }

    public function test_the_search_in_views_finds_typed_text_and_leaves_the_dictionary_alone(): void
    {
        $typed = [
            '<p>Welcome back</p>',
            '<x-slot name="heading">Coming soon</x-slot>',
            '<button title="Close the window">x</button>',
            '<img src="a.png" alt="A student">',
            '<x-filament::section heading="Your events">',
            '<p>Hello {{ $name }}, welcome</p>',
        ];

        foreach ($typed as $html) {
            $this->assertNotEmpty($this->textInView($html), "The search in views missed: {$html}");
        }

        $fine = [
            "<p>{{ __('welcome.back') }}</p>",
            "<x-slot name=\"heading\">{{ __('admin.coming_soon.heading') }}</x-slot>",
            "<button title=\"{{ __('close') }}\">x</button>",
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<div class="flex items-center gap-2 text-sm"></div>',
            '{{-- A comment is not interface text --}}',
            '<!-- Nor is this one -->',
            "@php \$x = 'Not shown'; @endphp",
            '<style>.a { color: red; }</style>',
            "@if (\$open) {{ __('a') }} @endif",
        ];

        foreach ($fine as $html) {
            $this->assertSame([], $this->textInView($html), "The search in views is wrong about: {$html}");
        }
    }

    /**
     * @return array<string, string> source of each PHP file, by path from the project root
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::PHP_FOLDERS as $folder) {
            foreach ($this->filesIn($folder, '.php') as $path) {
                if (! $this->isNotRead($path)) {
                    $files[$path] = file_get_contents(base_path($path));
                }
            }
        }

        return $files;
    }

    /**
     * @return array<string, string> source of each Blade view, by path from the project root
     */
    private function viewFiles(): array
    {
        $files = [];

        foreach ($this->filesIn(self::VIEW_FOLDER, '.blade.php') as $path) {
            $files[$path] = file_get_contents(base_path($path));
        }

        return $files;
    }

    /**
     * @return list<string> paths from the project root, in a fixed order
     */
    private function filesIn(string $folder, string $ending): array
    {
        $paths = [];
        $root = new RecursiveDirectoryIterator(base_path($folder), FilesystemIterator::SKIP_DOTS);

        foreach (new RecursiveIteratorIterator($root) as $file) {
            if (str_ends_with($file->getFilename(), $ending)) {
                $paths[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        sort($paths);

        return $paths;
    }

    private function isNotRead(string $path): bool
    {
        foreach (self::NOT_READ as $skipped) {
            if ($path === $skipped || str_starts_with($path, $skipped.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The code without its comments, with the line numbers kept.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
            } else {
                $code .= is_array($token) ? $token[1] : $token;
            }
        }

        return $code;
    }

    /**
     * @return list<array{int, string}> the line and the place of each typed text
     */
    private function typedPlaces(string $source): array
    {
        $code = $this->withoutComments($source);
        $found = [];

        foreach (self::PLACES as $place => $pattern) {
            preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                $found[] = [substr_count(substr($code, 0, $offset), "\n") + 1, $place];
            }
        }

        return $found;
    }

    /**
     * Every string of the code that reads like text for a person, apart from the messages of
     * exceptions that only developers read.
     *
     * @return list<array{int, string}> the line and the text
     */
    private function textInCode(string $source): array
    {
        $tokens = token_get_all($source);
        $found = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $text = $token[0] === T_CONSTANT_ENCAPSED_STRING ? $this->unquoted($token[1]) : $token[1];

            if ($this->readsAsText($text) && ! $this->isMessageForDevelopers($tokens, $index)) {
                $found[] = [$token[2], $text];
            }
        }

        return $found;
    }

    /**
     * The text of a string in the code, without its quotes and escapes.
     */
    private function unquoted(string $literal): string
    {
        $inside = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inside) : stripcslashes($inside);
    }

    /**
     * Whether a string reads like text for a person, as opposed to a key, a path, a class name, a
     * validation rule, a piece of SQL or a made-up address.
     */
    private function readsAsText(string $text): bool
    {
        if (preg_match('/^[\w\\\\\/.:\-@,|=*]*$/', $text)) {
            // No spaces: a key, a route, a path, a class, a header, a rule, an address. Only one
            // capitalised word on its own, such as 'Active' or 'Done.', is text.
            return preg_match('/^[A-Z][a-z]{2,}[.!?:]*$/', $text) === 1;
        }

        $words = '/[A-Za-z]{2,}[ ,.\';][A-Za-z]{2,}/';
        $oneCapitalisedWord = '/^\s*[A-Z][a-z]{2,}[A-Za-z\' -]*[.!?:…]*\s*$/';
        $pieceOfASentence = '/^\s+[A-Za-z]{2,}|[A-Za-z]{2,}[\s,:;]+$/';

        if (! preg_match($words, $text) && ! preg_match($oneCapitalisedWord, $text) && ! preg_match($pieceOfASentence, $text)) {
            return false;
        }

        // Not text: a validation rule such as "max:", a made-up address, SQL, a download header.
        $notText = [
            '/^[a-z_]+:$/',
            '/^[\w.+\-]+@[\w.\-]+$/',
            '/^[\w.]+ as \w+$/',
            '/\b(?:count|sum|min|max|avg)\(/i',
            '/\blike \?/i',
            '/^attachment; filename=/',
            '/^text\/[a-z]+; charset=/',
            // Markup around the words: a tag, or the rest of one with its classes.
            '/^<\/?[a-z][^>]*>$/',
            '/\bclass="/',
            '/^data:[a-z]+\/[a-z+]+;base64,$/',
        ];

        foreach ($notText as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the string is the message of an exception only developers read: new LogicException('...').
     *
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function isMessageForDevelopers(array $tokens, int $index): bool
    {
        // A piece of "a text with {$values} in it": look from the quote that opens the whole text.
        if (is_array($tokens[$index]) && $tokens[$index][0] === T_ENCAPSED_AND_WHITESPACE) {
            while ($index > 0 && $tokens[$index] !== '"' && ! (is_array($tokens[$index]) && $tokens[$index][0] === T_START_HEREDOC)) {
                $index--;
            }
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{' || $token === '}') {
                return false;
            }

            if (is_array($token) && $token[0] === T_NEW) {
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                        return in_array(basename(str_replace('\\', '/', $tokens[$j][1])), self::FOR_DEVELOPERS, true);
                    }
                }

                return false;
            }
        }

        return false;
    }

    /**
     * @return list<array{int, string}> the line and the text of each typed text in a Blade view
     */
    private function textInView(string $source): array
    {
        // Where nothing a person reads can be: comments, PHP, styles and scripts. The line numbers are kept.
        $blank = fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n"));
        $source = preg_replace_callback('/\{\{--.*?--\}\}|@php.*?@endphp|<\?php.*?\?>|<style.*?<\/style>|<script.*?<\/script>|<!--.*?-->/s', $blank, $source);
        // What is printed from a variable or from the dictionary is not typed text.
        $source = preg_replace_callback('/\{\{.*?\}\}|\{!!.*?!!\}/s', $blank, $source);
        $found = [];

        preg_match_all('/\s('.self::TEXT_ATTRIBUTES.')="([^"]*[A-Za-z]{2,}[^"]*)"/', $source, $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($attributes as $attribute) {
            // A meta tag such as the viewport holds settings, not words.
            if ($attribute[1][0] === 'content' && str_contains($attribute[2][0], '=')) {
                continue;
            }

            $found[] = [substr_count(substr($source, 0, $attribute[0][1]), "\n") + 1, $attribute[2][0]];
        }

        $text = preg_replace_callback('/<[^>]*>/s', $blank, $source);
        // Blade directives such as @if (...) or @foreach (...) hold code, not text.
        $text = preg_replace_callback('/@(?:if|elseif|foreach|forelse|for|while|isset|empty|unless|can|cannot|auth|guest|include|includeIf|includeWhen|extends|section|yield|push|stack|props|component|slot|vite|json|class|style|checked|selected|disabled|required|error|lang|choice|use|inject|method)\b(?:\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/', $blank, $text);
        $text = preg_replace('/@\w+/', '', $text);

        foreach (explode("\n", $text) as $number => $line) {
            if (preg_match('/[A-Za-z]{2,}/', $line)) {
                $found[] = [$number + 1, trim($line)];
            }
        }

        return $found;
    }
}
