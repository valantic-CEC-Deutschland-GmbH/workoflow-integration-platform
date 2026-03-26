<?php

namespace App\Tests\Mock;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TestHttpClientFactory
{
    private static ?\Closure $override = null;

    /**
     * URL pattern to fixture file mapping.
     * Order matters: first match wins.
     */
    private const URL_FIXTURE_MAP = [
        '/rest/api/3/myself' => 'jira/myself.json',
        '/rest/api/3/search/jql' => 'jira/search.json',
        '/rest/api/3/issue/' => 'jira/issue.json',
        '/transitions' => 'jira/transitions.json',
        '/wiki/rest/api/content/search' => 'confluence/search.json',
        '/wiki/rest/api/content/' => 'confluence/page.json',
        '/api/v2/search' => 'confluence/search.json',
        '/api/v2/pages/' => 'confluence/page.json',
        // Azure token endpoint for HealthController
        'login.microsoftonline.com' => null,
    ];

    public function __construct(
        private string $fixturesDir,
    ) {
    }

    /**
     * Set a per-test override callback.
     * The callback receives (string $method, string $url, array $options) and should
     * return a MockResponse or null to fall through to default behavior.
     */
    public static function setOverride(?\Closure $callback): void
    {
        self::$override = $callback;
    }

    /**
     * Reset any per-test override.
     */
    public static function reset(): void
    {
        self::$override = null;
    }

    public function create(): HttpClientInterface
    {
        $fixturesDir = $this->fixturesDir;

        return new MockHttpClient(function (string $method, string $url, array $options) use ($fixturesDir): MockResponse {
            // Check per-test override first
            if (self::$override !== null) {
                $overrideResponse = (self::$override)($method, $url, $options);
                if ($overrideResponse instanceof MockResponse) {
                    return $overrideResponse;
                }
            }

            // Match URL against fixture map
            foreach (self::URL_FIXTURE_MAP as $pattern => $fixtureFile) {
                if (str_contains($url, $pattern)) {
                    if ($fixtureFile === null) {
                        // Return empty 200 for patterns with no fixture (e.g., Azure)
                        return new MockResponse('{}', ['http_code' => 200]);
                    }

                    $fixturePath = $fixturesDir . '/' . $fixtureFile;
                    if (file_exists($fixturePath)) {
                        return new MockResponse(
                            file_get_contents($fixturePath),
                            [
                                'http_code' => 200,
                                'response_headers' => ['Content-Type' => 'application/json'],
                            ]
                        );
                    }
                }
            }

            // Default: return empty 200 JSON response for unmatched URLs
            return new MockResponse('{}', [
                'http_code' => 200,
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
    }
}
