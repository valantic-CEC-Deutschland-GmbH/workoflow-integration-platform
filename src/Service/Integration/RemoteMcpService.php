<?php

namespace App\Service\Integration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RemoteMcpService
{
    private const CACHE_TTL = 600; // 10 minutes
    private const CACHE_PREFIX = 'rmcp_tools_';
    private const MCP_PROTOCOL_VERSION = '2025-03-26';
    private const CLIENT_NAME = 'workoflow-platform';
    private const CLIENT_VERSION = '1.0.0';
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Discover tools from a remote MCP server.
     * Results are cached in Redis for 10 minutes.
     *
     * @param array<string, mixed> $credentials
     * @return array<int, array<string, mixed>>
     */
    public function discoverTools(array $credentials): array
    {
        $cacheKey = self::CACHE_PREFIX . md5(json_encode([
            $credentials['server_url'] ?? '',
            $credentials['auth_type'] ?? '',
        ]));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($credentials): array {
            $item->expiresAfter(self::CACHE_TTL);

            $sessionId = $this->initialize($credentials);
            $tools = $this->listTools($credentials, $sessionId);

            return $tools;
        });
    }

    /**
     * Execute a tool on the remote MCP server.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function executeTool(array $credentials, string $toolName, array $parameters): array
    {
        $sessionId = $this->initialize($credentials);

        return $this->callTool($credentials, $sessionId, $toolName, $parameters);
    }

    /**
     * Test basic connection to the remote MCP server.
     *
     * @param array<string, mixed> $credentials
     */
    public function testConnection(array $credentials): bool
    {
        try {
            $this->initialize($credentials);

            return true;
        } catch (\Exception $e) {
            $this->logger->debug('Remote MCP connection test failed', [
                'url' => $credentials['server_url'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Test connection with detailed diagnostics.
     *
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];
        $details = [];
        $url = $credentials['server_url'] ?? '';

        // Step 1: Validate URL
        if (empty($url)) {
            return [
                'success' => false,
                'message' => 'Server URL is required',
                'details' => ['URL validation failed'],
                'suggestion' => 'Please provide an HTTPS URL for the MCP server',
                'tested_endpoints' => [],
            ];
        }

        if (!str_starts_with($url, 'https://')) {
            return [
                'success' => false,
                'message' => 'Only HTTPS URLs are allowed',
                'details' => ['URL must start with https://'],
                'suggestion' => 'Change the URL to use HTTPS',
                'tested_endpoints' => [],
            ];
        }

        // Step 2: MCP Initialize handshake
        try {
            $sessionId = $this->initialize($credentials);
            $testedEndpoints[] = ['endpoint' => $url, 'method' => 'initialize', 'status' => 'OK'];
            $details[] = 'MCP handshake successful';

            if ($sessionId) {
                $details[] = 'Session ID received';
            }
        } catch (\Exception $e) {
            $testedEndpoints[] = ['endpoint' => $url, 'method' => 'initialize', 'status' => 'FAILED'];

            return [
                'success' => false,
                'message' => 'MCP handshake failed: ' . $e->getMessage(),
                'details' => $details,
                'suggestion' => $this->getSuggestionForError($e),
                'tested_endpoints' => $testedEndpoints,
            ];
        }

        // Step 3: List tools
        $toolCount = 0;
        try {
            $tools = $this->listTools($credentials, $sessionId);
            $toolCount = count($tools);
            $testedEndpoints[] = ['endpoint' => $url, 'method' => 'tools/list', 'status' => 'OK'];
            $details[] = "Discovered {$toolCount} tool(s)";

            if ($toolCount > 0) {
                $toolNames = array_map(fn(array $t) => $t['name'] ?? 'unknown', array_slice($tools, 0, 5));
                $details[] = 'Tools: ' . implode(', ', $toolNames) . ($toolCount > 5 ? '...' : '');
            }
        } catch (\Exception $e) {
            $testedEndpoints[] = ['endpoint' => $url, 'method' => 'tools/list', 'status' => 'FAILED'];
            $details[] = 'Tool discovery failed: ' . $e->getMessage();
        }

        return [
            'success' => true,
            'message' => "Connection successful - {$toolCount} tool(s) available",
            'details' => $details,
            'tested_endpoints' => $testedEndpoints,
        ];
    }

    /**
     * Invalidate cached tools for a set of credentials.
     *
     * @param array<string, mixed> $credentials
     */
    public function invalidateCache(array $credentials): void
    {
        $cacheKey = self::CACHE_PREFIX . md5(json_encode([
            $credentials['server_url'] ?? '',
            $credentials['auth_type'] ?? '',
        ]));

        $this->cache->delete($cacheKey);
    }

    /**
     * Perform MCP initialize handshake.
     * Returns session ID if the server provides one (via Mcp-Session-Id header).
     *
     * @param array<string, mixed> $credentials
     */
    private function initialize(array $credentials): ?string
    {
        $url = $this->validateUrl($credentials['server_url'] ?? '');
        $headers = $this->buildHeaders($credentials);

        // Step 1: Send initialize request
        $initPayload = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => [
                    'name' => self::CLIENT_NAME,
                    'version' => self::CLIENT_VERSION,
                ],
            ],
            'id' => 1,
        ];

        $response = $this->httpClient->request('POST', $url, [
            'headers' => array_merge($headers, [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
            ]),
            'json' => $initPayload,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException("MCP initialize failed with HTTP {$statusCode}");
        }

        $result = $this->parseResponse($response);
        if (isset($result['error'])) {
            throw new \RuntimeException('MCP initialize error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }

        // Extract session ID from response headers
        $responseHeaders = $response->getHeaders(false);
        $sessionId = $responseHeaders['mcp-session-id'][0] ?? null;

        // Step 2: Send initialized notification
        $notificationPayload = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ];

        $notificationHeaders = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]);

        if ($sessionId) {
            $notificationHeaders['Mcp-Session-Id'] = $sessionId;
        }

        $this->httpClient->request('POST', $url, [
            'headers' => $notificationHeaders,
            'json' => $notificationPayload,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        return $sessionId;
    }

    /**
     * Call tools/list on the remote MCP server.
     *
     * @param array<string, mixed> $credentials
     * @return array<int, array<string, mixed>>
     */
    private function listTools(array $credentials, ?string $sessionId): array
    {
        $url = $this->validateUrl($credentials['server_url'] ?? '');
        $headers = $this->buildHeaders($credentials);

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => new \stdClass(),
            'id' => 2,
        ];

        $requestHeaders = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]);

        if ($sessionId) {
            $requestHeaders['Mcp-Session-Id'] = $sessionId;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $requestHeaders,
            'json' => $payload,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        $result = $this->parseResponse($response);
        if (isset($result['error'])) {
            throw new \RuntimeException('MCP tools/list error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }

        return $result['result']['tools'] ?? [];
    }

    /**
     * Call tools/call on the remote MCP server.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function callTool(array $credentials, ?string $sessionId, string $toolName, array $parameters): array
    {
        $url = $this->validateUrl($credentials['server_url'] ?? '');
        $headers = $this->buildHeaders($credentials);

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => empty($parameters) ? new \stdClass() : $parameters,
            ],
            'id' => 3,
        ];

        $requestHeaders = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]);

        if ($sessionId) {
            $requestHeaders['Mcp-Session-Id'] = $sessionId;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $requestHeaders,
            'json' => $payload,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        $result = $this->parseResponse($response);
        if (isset($result['error'])) {
            throw new \RuntimeException('MCP tools/call error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }

        return $result['result'] ?? [];
    }

    /**
     * Parse the HTTP response, handling both JSON and SSE formats.
     *
     * @return array<string, mixed>
     */
    private function parseResponse(\Symfony\Contracts\HttpClient\ResponseInterface $response): array
    {
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        $body = $response->getContent(false);

        // Handle SSE response (text/event-stream)
        if (str_contains($contentType, 'text/event-stream')) {
            return $this->parseSseResponse($body);
        }

        // Handle JSON response
        $decoded = json_decode($body, true);
        if ($decoded === null && $body !== '' && $body !== 'null') {
            throw new \RuntimeException('Invalid JSON response from MCP server');
        }

        return $decoded ?? [];
    }

    /**
     * Parse an SSE response to extract the JSON-RPC result.
     *
     * @return array<string, mixed>
     */
    private function parseSseResponse(string $body): array
    {
        $lines = explode("\n", $body);
        $lastData = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $lastData = substr($line, 6);
            }
        }

        if ($lastData === null) {
            throw new \RuntimeException('No data in SSE response');
        }

        $decoded = json_decode($lastData, true);
        if ($decoded === null) {
            throw new \RuntimeException('Invalid JSON in SSE data');
        }

        return $decoded;
    }

    /**
     * Build auth headers based on credential auth_type.
     *
     * @param array<string, mixed> $credentials
     * @return array<string, string>
     */
    private function buildHeaders(array $credentials): array
    {
        $headers = [];
        $authType = $credentials['auth_type'] ?? 'none';

        switch ($authType) {
            case 'bearer':
                $token = $credentials['auth_token'] ?? '';
                if ($token !== '') {
                    $headers['Authorization'] = 'Bearer ' . $token;
                }
                break;

            case 'api_key':
                $headerName = $credentials['api_key_header'] ?? 'X-API-Key';
                $keyValue = $credentials['api_key_value'] ?? '';
                if ($keyValue !== '') {
                    $headers[$headerName] = $keyValue;
                }
                break;

            case 'basic':
                $username = $credentials['basic_username'] ?? '';
                $password = $credentials['basic_password'] ?? '';
                if ($username !== '') {
                    $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
                }
                break;
        }

        // Parse custom headers
        $customHeaders = $credentials['custom_headers'] ?? '';
        if (is_string($customHeaders) && $customHeaders !== '') {
            foreach (explode("\n", $customHeaders) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($key !== '' && $value !== '') {
                    $headers[$key] = $value;
                }
            }
        }

        return $headers;
    }

    /**
     * Validate and return the server URL. Requires HTTPS.
     */
    private function validateUrl(string $url): string
    {
        if (empty($url)) {
            throw new \InvalidArgumentException('MCP server URL is required');
        }

        if (!str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('MCP server URL must use HTTPS');
        }

        return rtrim($url, '/');
    }

    /**
     * Get a user-friendly suggestion based on the error.
     */
    private function getSuggestionForError(\Exception $e): string
    {
        $msg = strtolower($e->getMessage());

        return match (true) {
            str_contains($msg, '401') || str_contains($msg, 'unauthorized')
                => 'Check your authentication credentials (token, API key, or username/password)',
            str_contains($msg, '403') || str_contains($msg, 'forbidden')
                => 'The server rejected the request - verify your credentials have the correct permissions',
            str_contains($msg, '404') || str_contains($msg, 'not found')
                => 'The MCP endpoint was not found - verify the server URL is correct',
            str_contains($msg, 'could not resolve') || str_contains($msg, 'dns')
                => 'DNS resolution failed - check the server hostname',
            str_contains($msg, 'connection refused')
                => 'Connection refused - verify the server is running and accessible',
            str_contains($msg, 'ssl') || str_contains($msg, 'tls') || str_contains($msg, 'certificate')
                => 'SSL/TLS error - the server may have an invalid certificate',
            str_contains($msg, 'timeout')
                => 'Connection timed out - the server may be slow or unreachable',
            default => 'Verify the server URL and credentials are correct',
        };
    }
}
