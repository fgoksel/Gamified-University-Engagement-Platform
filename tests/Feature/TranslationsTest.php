<?php

namespace Tests\Feature;

use App\Console\Commands\SortLanguageFile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Task #95: every interface text lives in one dictionary, lang/en.json. Laravel and Vue read
 * the same file, and a new language is added by translating it. These tests keep the file
 * honest: every key a screen asks for exists, nothing in it is left over, and the other
 * language files follow it.
 *
 * Fix a failure by editing lang/en.json, then run: php artisan lang:sort
 */
class TranslationsTest extends TestCase
{
    /** A key names the screen and the text: auth.login.title, common.save. */
    private const KEY = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

    /**
     * Texts that Laravel's own views ask for, with the English text as the key: the pages for an error
     * (Not Found ...) and the layout of an email (Regards, ...). Laravel looks them up by that key, so the
     * key cannot be renamed. A new language translates their values like any other text.
     */
    private const FROM_LARAVEL = [
        'Unauthorized', 'Payment Required', 'Forbidden', 'Not Found', 'Page Expired', 'Too Many Requests',
        'Server Error', 'Service Unavailable', 'Hello!', 'Whoops!', 'Regards,', 'All rights reserved.',
        "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:",
    ];

    /** The two files that hand a key on to the dictionary for everyone else. */
    private const PASS_KEYS_ON = ['resources/js/i18n.js', 'resources/js/Components/Trans.vue'];

    public function test_every_language_file_is_a_flat_list_of_texts(): void
    {
        foreach ($this->languages() as $language) {
            foreach ($this->dictionary($language) as $key => $text) {
                if (! in_array($key, self::FROM_LARAVEL, true)) {
                    $this->assertMatchesRegularExpression(self::KEY, (string) $key, "lang/{$language}.json: \"{$key}\" is not a key like area.name.");
                }

                $this->assertIsString($text, "lang/{$language}.json: \"{$key}\" must be a text.");
                $this->assertNotSame('', trim($text), "lang/{$language}.json: \"{$key}\" is empty.");
                $this->assertSame(trim($text), $text, "lang/{$language}.json: \"{$key}\" starts or ends with a space.");
            }
        }
    }

    public function test_the_english_file_is_sorted(): void
    {
        $keys = array_keys($this->dictionary('en'));
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $keys, 'lang/en.json is not sorted. Run: php artisan lang:sort');
    }

    public function test_every_key_a_screen_asks_for_exists(): void
    {
        $english = $this->dictionary('en');
        $missing = [];

        foreach ($this->usedKeys() as $key => $files) {
            if (! array_key_exists($key, $english)) {
                $missing[] = "{$key} (in ".implode(', ', array_unique($files)).')';
            }
        }

        $this->assertSame([], $missing, "These keys are used but missing from lang/en.json:\n".implode("\n", $missing));
    }

    public function test_no_text_is_left_over_in_the_dictionary(): void
    {
        $unused = array_values(array_diff(array_keys($this->dictionary('en')), array_keys($this->usedKeys()), self::FROM_LARAVEL));

        $this->assertSame([], $unused, "These texts of lang/en.json are not used anywhere, delete them:\n".implode("\n", $unused));
    }

    public function test_the_texts_laravel_asks_for_itself_are_in_the_dictionary(): void
    {
        $english = $this->dictionary('en');

        foreach (self::FROM_LARAVEL as $key) {
            $this->assertSame($key, $english[$key] ?? null, "lang/en.json must hold \"{$key}\", with the same English text as its value. Laravel's own pages ask for it.");
        }

        // A new version of Laravel may ask for more. The views that reach people are read here.
        $asked = [];

        foreach (['Foundation/Exceptions/views', 'Mail/resources/views', 'Notifications/resources/views'] as $directory) {
            $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path("vendor/laravel/framework/src/Illuminate/{$directory}"), FilesystemIterator::SKIP_DOTS));

            foreach ($views as $view) {
                preg_match_all('/(?:__|@lang)\(\s*\'([^\']+)\'/', file_get_contents($view->getPathname()), $found);
                $asked = array_merge($asked, $found[1]);
            }
        }

        $this->assertNotEmpty($asked, 'The views of Laravel were not found.');
        $this->assertSame([], array_values(array_diff($asked, self::FROM_LARAVEL)), 'Laravel asks for texts that are not in FROM_LARAVEL and lang/en.json. Add them.');
    }

    public function test_keys_are_written_out_in_full(): void
    {
        $built = [];

        foreach ($this->sourceFiles() as $path => $source) {
            if (in_array($path, self::PASS_KEYS_ON, true)) {
                continue;
            }

            foreach ($this->callsWithoutLiteralKey($path, $source) as $call) {
                $built[] = "{$path}: {$call}";
            }
        }

        $this->assertSame([], $built, "Write the key out as a text, so a search finds where it is used:\n".implode("\n", $built));
    }

    public function test_a_script_that_asks_for_a_text_imports_the_helper(): void
    {
        $missing = [];

        foreach ($this->sourceFiles() as $path => $source) {
            if (! preg_match('/\.(vue|js)$/', $path) || in_array($path, self::PASS_KEYS_ON, true)) {
                continue;
            }

            // The script of a component, or a whole .js file, without its comments.
            $script = str_ends_with($path, '.vue') ? implode("\n", $this->scriptsOf($source)) : $source;
            $script = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $script);

            foreach (['t', 'tc'] as $helper) {
                $asks = preg_match('/(?<![\w.$])'.$helper.'\(/', $script) === 1;
                $imports = preg_match('/import\s*\{[^}]*\b'.$helper.'\b[^}]*\}\s*from\s*[\'"][^\'"]*i18n\.js[\'"]/', $script) === 1;

                if ($asks && ! $imports) {
                    $missing[] = "{$path}: {$helper}() is called but not imported from i18n.js";
                }
            }
        }

        $this->assertSame([], $missing, "A call without its import fails only when that screen is opened:\n".implode("\n", $missing));
    }

    public function test_texts_with_several_forms_say_when_each_applies(): void
    {
        foreach ($this->dictionary('en') as $key => $text) {
            if (! str_contains($text, '|')) {
                continue;
            }

            foreach (explode('|', $text) as $form) {
                $this->assertMatchesRegularExpression(
                    '/^\s*[{\[][^\[\]{}]*[}\]]/',
                    $form,
                    "{$key}: every form of a plural text must start with the numbers it is for, like {1} or [2,*].",
                );
            }
        }
    }

    public function test_other_languages_follow_english(): void
    {
        $english = $this->dictionary('en');

        $this->assertContains('en', $this->languages(), 'lang/en.json is the dictionary every other language follows.');

        foreach ($this->languages() as $language) {
            if ($language === 'en') {
                continue;
            }

            $translated = $this->dictionary($language);

            $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($translated))), "lang/{$language}.json lacks texts of lang/en.json.");
            $this->assertSame([], array_values(array_diff(array_keys($translated), array_keys($english))), "lang/{$language}.json has texts that lang/en.json does not.");

            foreach ($english as $key => $text) {
                $this->assertSame($this->placeholders($text), $this->placeholders($translated[$key]), "{$language}: {$key} must keep the same :placeholders as English.");
                $this->assertSame($this->conditions($text), $this->conditions($translated[$key]), "{$language}: {$key} must keep the same plural forms as English.");
            }
        }
    }

    public function test_the_sort_command_puts_the_keys_in_order(): void
    {
        $real = lang_path();
        $directory = sys_get_temp_dir().'/lang-sort-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents("{$directory}/en.json", json_encode(['b.two' => 'Two :name', 'a.one' => '{1} One|[0,*] Many']));

        // Tried in a folder of its own, never on the real file.
        app()->useLangPath($directory);

        try {
            $this->artisan('lang:sort')->assertSuccessful();

            $this->assertSame(['a.one', 'b.two'], array_keys(SortLanguageFile::read("{$directory}/en.json")));
        } finally {
            app()->useLangPath($real);

            array_map('unlink', glob("{$directory}/*.json"));
            rmdir($directory);
        }
    }

    /**
     * @return list<string> e.g. ["en", "qps"]
     */
    private function languages(): array
    {
        return array_map(fn (string $path) => basename($path, '.json'), glob(lang_path('*.json')));
    }

    /**
     * @return array<string, string>
     */
    private function dictionary(string $language): array
    {
        return SortLanguageFile::read(lang_path("{$language}.json"));
    }

    /**
     * Every key written into the code, with the files that use it.
     *
     * @return array<string, list<string>>
     */
    private function usedKeys(): array
    {
        $patterns = [
            'php' => [
                // __('key'), trans('key'), trans_choice('key', 2), @lang('key')
                '/(?<![\w$>:])(?:__|trans_choice|trans)\(\s*([\'"])(?<key>[^\'"]+)\1/',
                '/@lang\(\s*([\'"])(?<key>[^\'"]+)\1/',
            ],
            'js' => [
                // t("key"), $t("key"), tc("key", 2), $tc("key", 2)
                '/(?<![\w.])\$?tc?\(\s*([\'"`])(?<key>[^\'"`]+)\1/',
                // <Trans k="key">
                '/<Trans\b[^>]*?\sk=([\'"])(?<key>[^\'"]+)\1/s',
            ],
        ];

        $used = [];

        foreach ($this->sourceFiles() as $path => $source) {
            foreach ($patterns[str_ends_with($path, '.php') ? 'php' : 'js'] as $pattern) {
                preg_match_all($pattern, $source, $found);

                foreach ($found['key'] as $key) {
                    $this->assertMatchesRegularExpression(self::KEY, $key, "{$path}: \"{$key}\" is not a key like area.name. English text is not a key.");
                    $used[$key][] = $path;
                }
            }
        }

        ksort($used);

        return $used;
    }

    /**
     * Calls that ask for a text with something other than a key written out in full.
     *
     * @return list<string>
     */
    private function callsWithoutLiteralKey(string $path, string $source): array
    {
        $pattern = str_ends_with($path, '.php')
            ? '/(?<![\w$>:])(?:__|trans_choice|trans)\((?!\s*[\'"])[^\n]{0,60}/'
            : '/(?<![\w.])\$?tc?\((?!\s*[\'"])[^\n]{0,60}/';

        preg_match_all($pattern, $source, $found);

        return array_map('trim', $found[0]);
    }

    /**
     * The files in which texts are asked for, by their path from the project root.
     *
     * @return array<string, string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (['app', 'bootstrap', 'config', 'database', 'resources/js', 'resources/views', 'routes'] as $directory) {
            if (! is_dir(base_path($directory))) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! preg_match('/\.(php|vue|js)$/', $file->getFilename())) {
                    continue;
                }

                $files[str_replace(base_path().'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * The code inside the <script> tags of a component.
     *
     * @return list<string>
     */
    private function scriptsOf(string $component): array
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $component, $found);

        return $found[1];
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $text, $found);
        $names = array_values(array_unique($found[0]));
        sort($names);

        return $names;
    }

    /**
     * The numbers each form of a plural text is for: ["{1}", "[0,*]"].
     *
     * @return list<string>
     */
    private function conditions(string $text): array
    {
        return array_map(
            fn (string $form) => preg_match('/^\s*([{\[][^\[\]{}]*[}\]])/', $form, $found) ? $found[1] : '',
            explode('|', $text),
        );
    }
}
