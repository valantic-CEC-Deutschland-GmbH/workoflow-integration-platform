<?php

namespace App\Service;

use App\Entity\Organisation;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the list of available agents from the orchestrator's capabilities endpoint.
 *
 * For "common" (ADK) tenants the platform doesn't know statically which agents
 * the orchestrator provides — it asks the orchestrator at runtime.  The result
 * is used to populate the platform-skills edit page so that admins can
 * enable/disable individual orchestrator agents.
 *
 * Results are cached for 5 minutes per organisation to reduce API calls.
 */
class OrchestratorCapabilitiesService
{
    private const CACHE_TTL = 300;
    private const CACHE_KEY_PREFIX = 'orchestrator_caps_';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Fetch orchestrator agents from GET /api/capabilities.
     *
     * Returns cached results when available (5-minute TTL).
     * On error the empty result is NOT cached so the next call retries.
     *
     * @return array<int, array{type: string, name: string, description: string, tools: array}>
     */
    public function fetchCapabilities(Organisation $organisation): array
    {
        $baseUrl = $organisation->getOrchestratorApiUrl();
        if (!$baseUrl) {
            $this->logger->warning('Cannot fetch orchestrator capabilities: no orchestratorApiUrl configured', [
                'organisation' => $organisation->getUuid(),
            ]);

            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $organisation->getUuid();

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($organisation, $baseUrl): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetchFromOrchestrator($organisation, $baseUrl);
            });
        } catch (\Throwable) {
            // Error was already logged in fetchFromOrchestrator().
            // Return empty array to keep the contract with callers;
            // because the callback threw, the cache did NOT store anything,
            // so the next call will retry the HTTP request.
            return [];
        }
    }

    /**
     * Clear the cached capabilities for the given organisation.
     *
     * Useful when an admin changes the orchestrator URL so stale data
     * is not served until the TTL expires.
     */
    public function clearCache(Organisation $organisation): void
    {
        $this->cache->delete(self::CACHE_KEY_PREFIX . $organisation->getUuid());
    }

    /**
     * Perform the actual HTTP request to the orchestrator and validate the response.
     *
     * @return array<int, array{type: string, name: string, description: string, tools: array}>
     */
    private function fetchFromOrchestrator(Organisation $organisation, string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/') . '/api/capabilities';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5,
            ]);

            $data = $response->toArray();

            return $this->validateCapabilitiesResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch orchestrator capabilities', [
                'url' => $url,
                'error' => $e->getMessage(),
                'organisation' => $organisation->getUuid(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate the raw capabilities payload and filter out malformed agent entries.
     *
     * @return array<int, array{type: string, name: string, description: string, tools: array}>
     *
     * @throws \RuntimeException when the response structure is unexpected
     */
    private function validateCapabilitiesResponse(array $data): array
    {
        if (!isset($data['agents']) || !is_array($data['agents'])) {
            throw new \RuntimeException('Invalid capabilities response: missing "agents" array');
        }

        return array_filter($data['agents'], function (array $agent): bool {
            return !empty($agent['type']) && is_string($agent['type'])
                && !empty($agent['name']) && is_string($agent['name']);
        });
    }
}
