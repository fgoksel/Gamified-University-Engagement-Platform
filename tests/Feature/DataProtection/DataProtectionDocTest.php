<?php

namespace Tests\Feature\DataProtection;

use Tests\TestCase;

/**
 * Task #62: docs/data-protection.md says what the platform holds and what keeps it out of the logs.
 * It is only worth reading while it names things that are still there.
 */
class DataProtectionDocTest extends TestCase
{
    private function page(): string
    {
        return (string) file_get_contents(base_path('docs/data-protection.md'));
    }

    public function test_the_page_exists_and_the_readme_points_to_it(): void
    {
        $this->assertFileExists(base_path('docs/data-protection.md'));
        $this->assertStringContainsString('docs/data-protection.md', (string) file_get_contents(base_path('README.md')));
    }

    public function test_every_test_the_page_names_exists(): void
    {
        preg_match_all('/`([A-Za-z]+Test)`/', $this->page(), $names);

        $this->assertNotEmpty($names[1]);

        foreach (array_unique($names[1]) as $name) {
            $this->assertFileExists(base_path("tests/Feature/DataProtection/{$name}.php"), "The page names {$name}, which does not exist.");
        }
    }

    public function test_every_file_the_page_lists_under_where_it_is_exists(): void
    {
        $section = substr($this->page(), (int) strpos($this->page(), '## Where it is'));

        preg_match_all('/`([a-z_]+\/[A-Za-z0-9_.\/*-]*)`/', $section, $paths);

        $this->assertNotEmpty($paths[1]);

        foreach ($paths[1] as $path) {
            // A folder, a file, or a pattern such as docker/nginx/*.conf.
            $this->assertNotEmpty(glob(base_path(rtrim($path, '/'))), "The page names {$path}, which does not exist.");
        }
    }

    public function test_the_page_names_the_settings_the_code_reads(): void
    {
        // The words of the page and the names in the code must be the same ones.
        $this->assertStringContainsString('mask_bindings_in_exception_messages', $this->page());
        $this->assertStringContainsString('mask_bindings_in_exception_messages', (string) file_get_contents(base_path('config/database.php')));
        $this->assertStringContainsString('zend.exception_ignore_args', $this->page());
        $this->assertStringContainsString('zend.exception_ignore_args', (string) file_get_contents(base_path('docker/php/data-protection.ini')));
        $this->assertMatchesRegularExpression('/queue:prune-failed --hours=72(?!\d)/', $this->page());
        $this->assertMatchesRegularExpression('/queue:prune-failed --hours=72(?!\d)/', (string) file_get_contents(base_path('routes/console.php')));
    }
}
