<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Checks which PHP the Docker image runs (Task #105).
 *
 * Docker does not run inside the tests, so these tests read the Dockerfile and the compose files.
 *
 * A tag such as php:8.3-fpm is not pulled again when an older image of that name is already on
 * the machine, so a machine that built before a security release keeps running the old PHP.
 * An exact release is a new name, so Docker pulls it. That is why the Dockerfile names one.
 */
class PhpImageTest extends TestCase
{
    /**
     * The oldest release the image may name. When the tag in docker/php/Dockerfile moves to a
     * newer release, set this to the same number, so that the image can never go back.
     *
     * 8.3.36 (21 Oct 2026) fixed CVE-2026-109618 and CVE-2026-109619.
     * 8.3.37 (19 Nov 2026) fixed 13 more, three of them in PHP-FPM.
     */
    private const OLDEST_RELEASE = '8.3.37';

    private function release(): ?string
    {
        $dockerfile = (string) file_get_contents(base_path('docker/php/Dockerfile'));

        return preg_match('/^FROM php:(\d+\.\d+\.\d+)-fpm\s*$/m', $dockerfile, $match) ? $match[1] : null;
    }

    public function test_the_dockerfile_names_an_exact_php_release(): void
    {
        $this->assertNotNull(
            $this->release(),
            'docker/php/Dockerfile must start from an exact release, for example FROM php:8.3.37-fpm. '
            .'A tag such as php:8.3-fpm is not pulled again when an older image of that name is on the machine.'
        );
    }

    public function test_the_release_is_not_older_than_the_one_with_the_security_fixes(): void
    {
        $release = $this->release();

        $this->assertNotNull($release, 'The Dockerfile does not name an exact PHP release.');
        $this->assertTrue(
            version_compare($release, self::OLDEST_RELEASE, '>='),
            "The Dockerfile names PHP {$release}. The oldest release allowed is ".self::OLDEST_RELEASE.', which has the security fixes.'
        );
    }

    public function test_every_service_that_builds_an_image_uses_that_one_dockerfile_and_none_pulls_php(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        preg_match_all('/^  ([a-z-]+):\n((?:    .*\n|\n)*)/m', $compose, $services, PREG_SET_ORDER);

        $built = 0;

        foreach ($services as [, $name, $block]) {
            if (str_contains($block, 'build:')) {
                $built++;
                $this->assertStringContainsString('context: ./docker/php', $block, "The {$name} service builds an image from another folder, so it can run another PHP.");
            }
        }

        $this->assertGreaterThan(0, $built, 'No service builds the PHP image.');

        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s+image:\s*php[:@\s]/mi',
                (string) file_get_contents(base_path($file)),
                "{$file} pulls a PHP image of its own, so a service can run another PHP than the Dockerfile names."
            );
        }
    }
}
