<?php

declare(strict_types=1);

namespace App\Service;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReleaseNotesService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const ERROR_CACHE_TTL = 60; // 1 minute for errors

    private const REPOSITORIES = [
        'bot' => [
            'name' => 'release_notes.projects.bot.name',
            'description' => 'release_notes.projects.bot.description',
            'url' => 'https://raw.githubusercontent.com/valantic-CEC-Deutschland-GmbH/workoflow-bot/main/CHANGELOG.md',
            'github' => 'https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-bot',
            'icon' => 'fa-robot',
        ],
        'mcp' => [
            'name' => 'release_notes.projects.mcp.name',
            'description' => 'release_notes.projects.mcp.description',
            'url' => 'https://raw.githubusercontent.com/valantic-CEC-Deutschland-GmbH/workoflow-mcp/main/CHANGELOG.md',
            'github' => 'https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-mcp',
            'icon' => 'fa-plug',
        ],
        'platform' => [
            'name' => 'release_notes.projects.platform.name',
            'description' => 'release_notes.projects.platform.description',
            'url' => 'https://raw.githubusercontent.com/valantic-CEC-Deutschland-GmbH/workoflow-integration-platform/main/CHANGELOG.md',
            'github' => 'https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-integration-platform',
            'icon' => 'fa-globe',
        ],
        'tests' => [
            'name' => 'release_notes.projects.tests.name',
            'description' => 'release_notes.projects.tests.description',
            'url' => 'https://raw.githubusercontent.com/valantic-CEC-Deutschland-GmbH/workoflow-tests/main/CHANGELOG.md',
            'github' => 'https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-tests',
            'icon' => 'fa-vial',
        ],
        'loadtests' => [
            'name' => 'release_notes.projects.loadtests.name',
            'description' => 'release_notes.projects.loadtests.description',
            'url' => 'https://raw.githubusercontent.com/valantic-CEC-Deutschland-GmbH/workoflow-load-tests/main/CHANGELOG.md',
            'github' => 'https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-load-tests',
            'icon' => 'fa-tachometer-alt',
        ],
        'infrastructure' => [
            'name' => 'release_notes.projects.infrastructure.name',
            'description' => 'release_notes.projects.infrastructure.description',
            'url' => 'https://raw.githubusercontent.com/valantic-CEC-Deutschland-GmbH/workoflow-hosting/main/CHANGELOG.md',
            'github' => 'https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-hosting',
            'icon' => 'fa-server',
        ],
        'metrics' => [
            'name' => 'release_notes.projects.metrics.name',
            'description' => 'release_notes.projects.metrics.description',
            'url' => 'https://raw.githubusercontent.com/valantic-CEC-Deutschland-GmbH/workoflow-metrics/main/CHANGELOG.md',
            'github' => 'https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-metrics',
            'icon' => 'fa-chart-line',
        ],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllReleaseNotes(): array
    {
        $results = [];
        foreach (self::REPOSITORIES as $key => $repo) {
            $results[$key] = $this->getReleaseNotes($key);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function getReleaseNotes(string $repoKey): array
    {
        $repo = self::REPOSITORIES[$repoKey] ?? null;
        if ($repo === null) {
            return ['error' => 'Unknown repository'];
        }

        $cacheKey = 'release_notes_' . $repoKey;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($repo, $repoKey): array {
            $item->expiresAfter(self::CACHE_TTL);

            try {
                $response = $this->httpClient->request('GET', $repo['url'], [
                    'timeout' => 10,
                ]);

                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException('HTTP ' . $response->getStatusCode());
                }

                $markdown = $response->getContent();
                $converter = new GithubFlavoredMarkdownConverter();
                $html = $converter->convert($markdown)->getContent();

                return [
                    'key' => $repoKey,
                    'name' => $repo['name'],
                    'description' => $repo['description'],
                    'icon' => $repo['icon'],
                    'github' => $repo['github'],
                    'content' => $html,
                    'fetchedAt' => new \DateTimeImmutable(),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $this->logger->error('Failed to fetch changelog for {repo}: {error}', [
                    'repo' => $repoKey,
                    'error' => $e->getMessage(),
                ]);

                // Don't cache errors for long - set short TTL
                $item->expiresAfter(self::ERROR_CACHE_TTL);

                return [
                    'key' => $repoKey,
                    'name' => $repo['name'],
                    'description' => $repo['description'],
                    'icon' => $repo['icon'],
                    'github' => $repo['github'],
                    'content' => null,
                    'fetchedAt' => null,
                    'error' => 'Failed to fetch release notes: ' . $e->getMessage(),
                ];
            }
        });
    }
}
