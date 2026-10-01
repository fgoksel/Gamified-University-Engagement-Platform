<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checks the Nginx security setup (Task #35, Technical Specification 5.2.1 and 8.2).
 *
 * Nginx does not run inside the tests, so these tests read the configuration files.
 */
class NginxSecurityTest extends TestCase
{
    public function test_security_headers_are_defined_once_for_every_response(): void
    {
        $headers = $this->nginx('security-headers.conf');

        $this->assertStringContainsString('add_header X-Frame-Options "SAMEORIGIN" always;', $headers);
        $this->assertStringContainsString('add_header X-Content-Type-Options "nosniff" always;', $headers);
    }

    #[DataProvider('serverConfigs')]
    public function test_both_servers_send_the_security_headers(string $file): void
    {
        $this->assertStringContainsString('include /etc/nginx/security-headers.conf;', $this->nginx($file));
    }

    public static function serverConfigs(): array
    {
        return ['local' => ['default.conf'], 'production' => ['production.conf']];
    }

    public function test_production_redirects_http_to_https(): void
    {
        $config = $this->nginx('production.conf');

        $this->assertMatchesRegularExpression('/listen 80;.*?return 301 https:\/\/\$host\$request_uri;/s', $config);
        $this->assertStringContainsString('listen 443 ssl;', $config);
    }

    public function test_production_sends_hsts_for_a_year_and_allows_only_modern_tls(): void
    {
        $config = $this->nginx('production.conf');

        $this->assertStringContainsString('add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;', $config);
        $this->assertStringContainsString('ssl_protocols       TLSv1.2 TLSv1.3;', $config);
        $this->assertStringContainsString('ssl_certificate     /etc/nginx/certs/fullchain.pem;', $config);
        $this->assertStringContainsString('ssl_certificate_key /etc/nginx/certs/privkey.pem;', $config);
        $this->assertStringContainsString('server_tokens off;', $config);
    }

    public function test_local_development_stays_on_plain_http_without_hsts(): void
    {
        $config = $this->nginx('default.conf');

        $this->assertStringContainsString('listen 80;', $config);
        $this->assertStringNotContainsString('Strict-Transport-Security', $config);
        $this->assertStringNotContainsString('return 301', $config);
    }

    public function test_the_production_compose_file_publishes_443_and_mounts_the_production_config(): void
    {
        $compose = file_get_contents(base_path('docker-compose.prod.yml'));

        $this->assertStringContainsString('"443:443"', $compose);
        $this->assertStringContainsString('./docker/nginx/production.conf:/etc/nginx/conf.d/default.conf', $compose);
        $this->assertStringContainsString('./docker/nginx/certs:/etc/nginx/certs', $compose);
    }

    public function test_the_certificate_folder_is_ignored_by_git(): void
    {
        $this->assertStringContainsString('/docker/nginx/certs/', file_get_contents(base_path('.gitignore')));
    }

    public function test_session_cookies_are_secure_and_http_only_in_production(): void
    {
        $original = getenv('APP_ENV');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';

        try {
            $session = require config_path('session.php');
        } finally {
            $original === false ? putenv('APP_ENV') : putenv("APP_ENV={$original}");
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $original === false ? 'testing' : $original;
        }

        $this->assertTrue($session['secure']);
        $this->assertTrue($session['http_only']);
        $this->assertSame('lax', $session['same_site']);
    }

    public function test_session_cookies_stay_usable_on_plain_http_when_developing(): void
    {
        $this->assertNotTrue(config('session.secure'));
    }

    private function nginx(string $file): string
    {
        return file_get_contents(base_path('docker/nginx/'.$file));
    }
}
