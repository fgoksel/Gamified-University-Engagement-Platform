<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phones only give the camera to https pages (Task #88). The demo tunnel ends TLS in front of
 * the local web server, so the local server has to treat a forwarded https request as https.
 *
 * Nginx and Docker do not run inside the tests, so these read the configuration files. The
 * behaviour itself (links, redirects and assets built with https for a forwarded request, http for
 * a plain one) was checked against a real Nginx and PHP-FPM running this configuration.
 */
class DemoOverHttpsTest extends TestCase
{
    public function test_the_local_server_passes_a_forwarded_https_request_on_to_php(): void
    {
        $config = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertMatchesRegularExpression('/map \$http_x_forwarded_proto \$forwarded_https \{\s*default\s+"";\s*https\s+on;\s*\}/', $config);
        $this->assertStringContainsString('fastcgi_param HTTPS $forwarded_https if_not_empty;', $config);
        // After the include, so it takes the place of the plain-http default.
        $this->assertGreaterThan(strpos($config, 'include fastcgi_params;'), strpos($config, 'fastcgi_param HTTPS'));
    }

    public function test_the_tunnel_is_an_optional_service_in_front_of_the_local_server(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        preg_match('/^  tunnel:\n((?:    .*\n|\n)+)/m', $compose, $service);
        $this->assertNotEmpty($service, 'the compose file has a tunnel service');

        $this->assertStringContainsString('profiles: ["demo"]', $service[1], 'it starts only on request, never with a plain "up"');
        $this->assertStringContainsString('--url http://webserver:80', $service[1]);
        $this->assertStringContainsString('- webserver', $service[1]);
    }

    public function test_the_demo_guide_exists_and_is_linked_from_the_readme(): void
    {
        $this->assertFileExists(base_path('docs/demo-over-https.md'));
        $this->assertStringContainsString('docs/demo-over-https.md', file_get_contents(base_path('README.md')));
    }
}
