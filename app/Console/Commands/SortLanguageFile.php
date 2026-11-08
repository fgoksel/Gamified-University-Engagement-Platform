<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Keeps lang/en.json in order after a text was added or changed (Task #95).
 */
class SortLanguageFile extends Command
{
    protected $signature = 'lang:sort';

    protected $description = 'Sort the keys of lang/en.json';

    public function handle(): int
    {
        $english = self::read(lang_path('en.json'));
        ksort($english, SORT_STRING);

        self::write(lang_path('en.json'), $english);

        $this->info(count($english).' texts, sorted.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        $dictionary = json_decode((string) file_get_contents($path), true);

        if (! is_array($dictionary)) {
            throw new RuntimeException("{$path} is not a JSON object.");
        }

        return $dictionary;
    }

    /**
     * The one way a language file is written, so every file has the same shape.
     *
     * @param  array<string, string>  $dictionary
     */
    public static function write(string $path, array $dictionary): void
    {
        // An empty dictionary is still a JSON object, not a list.
        $json = json_encode($dictionary === [] ? (object) [] : $dictionary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($path, $json."\n");
    }
}
