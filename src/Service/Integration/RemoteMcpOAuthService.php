<?php

namespace App\Service\Integration;

use App\Entity\IntegrationConfig;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RemoteMcpOAuthService
{
    private const TOKEN_REFRESH_BUFFER = 300; // 5 minutes before expiry
    private const REQUEST_TIMEOUT = 30;
    private const CLIENT_NAME = 'workoflow-platform';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly EncryptionService $encryptionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Discover OAuth2 authorization server metadata from the MCP server URL.
     * Fetches /.well-known/oauth-authorization-server from the server's base URL.
     *
     * @return array<string, mixed> OAuth metadata (authorization_endpoint, token_endpoint, etc.)
     */
    public function discoverOAuthMetadata(string $serverUrl): array
    {
        $baseUrl = $this->getBaseUrl($serverUrl);
        $metadataUrl = rtrim($baseUrl, '/') . '/.well-known/oauth-authorization-server';

        $this->logger->debug('Discovering OAuth metadata', ['url' => $metadataUrl]);

        try {
            $response = $this->httpClient->request('GET', $metadataUrl, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Accept' => 'application/json',
                    'MCP-Protocol-Version' => '2025-03-26',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException("OAuth metadata endpoint returned HTTP {$statusCode}");
            }

            $metadata = $response->toArray();

            // Validate required fields
            if (empty($metadata['authorization_endpoint'])) {
                throw new \RuntimeException('OAuth metadata missing required field: authorization_endpoint');
            }
            if (empty($metadata['token_endpoint'])) {
                throw new \RuntimeException('OAuth metadata missing required field: token_endpoint');
            }

            $this->logger->info('OAuth metadata discovered', [
                'issuer' => $metadata['issuer'] ?? 'unknown',
                'has_registration' => !empty($metadata['registration_endpoint']),
            ]);

            return $metadata;
        } catch (\Exception $e) {
            $this->logger->error('OAuth metadata discovery failed', [
                'url' => $metadataUrl,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Could not discover OAuth configuration from server: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Register a client dynamically with the authorization server (RFC 7591).
     * Public client flow — no client_secret needed.
     *
     * @param array<string, mixed> $metadata OAuth metadata containing registration_endpoint
     * @return array<string, mixed> Registration response (client_id, etc.)
     */
    public function registerClient(array $metadata, string $callbackUrl): array
    {
        if (empty($metadata['registration_endpoint'])) {
            throw new \RuntimeException('OAuth server does not support Dynamic Client Registration');
        }

        $registrationUrl = $metadata['registration_endpoint'];

        $this->logger->debug('Registering OAuth client', ['url' => $registrationUrl]);

        try {
            $response = $this->httpClient->request('POST', $registrationUrl, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'client_name' => self::CLIENT_NAME,
                    'redirect_uris' => [$callbackUrl],
                    'grant_types' => ['authorization_code', 'refresh_token'],
                    'response_types' => ['code'],
                    'token_endpoint_auth_method' => 'none',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 201 && $statusCode !== 200) {
                $body = $response->getContent(false);
                throw new \RuntimeException("Dynamic Client Registration failed with HTTP {$statusCode}: {$body}");
            }

            $result = $response->toArray();

            if (empty($result['client_id'])) {
                throw new \RuntimeException('Dynamic Client Registration response missing client_id');
            }

            $this->logger->info('OAuth client registered', [
                'client_id' => $result['client_id'],
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('OAuth client registration failed', [
                'url' => $registrationUrl,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Dynamic Client Registration failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate PKCE code_verifier and code_challenge (S256).
     *
     * @return array{code_verifier: string, code_challenge: string, code_challenge_method: string}
     */
    public function generatePkce(): array
    {
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(
            base64_encode(hash('sha256', $codeVerifier, true)),
            '+/',
            '-_'
        ), '=');

        return [
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
    }

    /**
     * Generate a random state parameter for CSRF protection.
     */
    public function generateState(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Build the authorization URL for the OAuth2 flow.
     *
     * @param array<string, mixed> $metadata OAuth metadata
     */
    public function buildAuthorizationUrl(
        array $metadata,
        string $clientId,
        string $redirectUri,
        string $state,
        string $codeChallenge,
        ?string $scope = null,
    ): string {
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if ($scope !== null && $scope !== '') {
            $params['scope'] = $scope;
        }

        return $metadata['authorization_endpoint'] . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for access and refresh tokens.
     * Public client flow — no client_secret sent.
     *
     * @param array<string, mixed> $metadata OAuth metadata
     * @return array<string, mixed> Token response (access_token, refresh_token, expires_in, etc.)
     */
    public function exchangeCodeForTokens(
        array $metadata,
        string $code,
        string $clientId,
        string $redirectUri,
        string $codeVerifier,
    ): array {
        $tokenUrl = $metadata['token_endpoint'];

        $this->logger->debug('Exchanging authorization code for tokens', ['url' => $tokenUrl]);

        try {
            $response = $this->httpClient->request('POST', $tokenUrl, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'code_verifier' => $codeVerifier,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $body = $response->getContent(false);
                throw new \RuntimeException("Token exchange failed with HTTP {$statusCode}: {$body}");
            }

            $tokens = $response->toArray();

            if (empty($tokens['access_token'])) {
                throw new \RuntimeException('Token response missing access_token');
            }

            // Compute absolute expiry timestamp
            if (isset($tokens['expires_in'])) {
                $tokens['expires_at'] = time() + (int) $tokens['expires_in'];
            }

            $this->logger->info('OAuth tokens obtained', [
                'has_refresh_token' => !empty($tokens['refresh_token']),
                'expires_in' => $tokens['expires_in'] ?? 'unknown',
            ]);

            return $tokens;
        } catch (\Exception $e) {
            $this->logger->error('OAuth token exchange failed', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to exchange authorization code for tokens: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Refresh an expired access token using the refresh token.
     * Persists updated tokens to the database if configId is provided.
     *
     * @param array<string, mixed> $credentials Current credentials with oauth_refresh_token
     * @return array<string, mixed> Updated credentials with new tokens
     */
    public function refreshAccessToken(array $credentials, ?int $configId = null): array
    {
        $metadata = $credentials['oauth_metadata'] ?? [];
        $tokenUrl = $metadata['token_endpoint'] ?? '';
        $refreshToken = $credentials['oauth_refresh_token'] ?? '';
        $clientId = $credentials['oauth_dcr_client_id'] ?? '';

        if (empty($tokenUrl) || empty($refreshToken)) {
            throw new \RuntimeException('Cannot refresh token: missing token endpoint or refresh token');
        }

        $this->logger->debug('Refreshing OAuth access token', ['config_id' => $configId]);

        try {
            $body = [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
            ];

            $response = $this->httpClient->request('POST', $tokenUrl, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $responseBody = $response->getContent(false);
                throw new \RuntimeException("Token refresh failed with HTTP {$statusCode}: {$responseBody}");
            }

            $tokens = $response->toArray();

            if (empty($tokens['access_token'])) {
                throw new \RuntimeException('Token refresh response missing access_token');
            }

            // Update credentials with new tokens
            $credentials['oauth_access_token'] = $tokens['access_token'];
            if (!empty($tokens['refresh_token'])) {
                $credentials['oauth_refresh_token'] = $tokens['refresh_token'];
            }
            if (isset($tokens['expires_in'])) {
                $credentials['oauth_expires_at'] = time() + (int) $tokens['expires_in'];
            }

            // Persist to database if we have a config ID
            if ($configId !== null) {
                $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                if ($config !== null) {
                    $config->setEncryptedCredentials(
                        $this->encryptionService->encrypt(json_encode($credentials))
                    );
                    $this->entityManager->flush();
                    $this->logger->info('OAuth tokens refreshed and persisted', ['config_id' => $configId]);
                }
            }

            return $credentials;
        } catch (\Exception $e) {
            $this->logger->error('OAuth token refresh failed', [
                'config_id' => $configId,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('OAuth token refresh failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Ensure the OAuth access token is valid. Refreshes if expired or near-expiry.
     *
     * @param array<string, mixed> $credentials Current credentials
     * @return array<string, mixed> Updated credentials (may have new tokens)
     */
    public function ensureValidToken(array $credentials, ?int $configId = null): array
    {
        $expiresAt = $credentials['oauth_expires_at'] ?? 0;

        if ($expiresAt > (time() + self::TOKEN_REFRESH_BUFFER)) {
            return $credentials; // Token still valid
        }

        $this->logger->debug('OAuth token expired or near-expiry, refreshing', [
            'expires_at' => $expiresAt,
            'config_id' => $configId,
        ]);

        return $this->refreshAccessToken($credentials, $configId);
    }

    /**
     * Probe a server URL to detect OAuth2 support.
     * Used by the "Detect Auth Support" button.
     *
     * @return array{supports_oauth: bool, registration_supported: bool, message: string, metadata?: array<string, mixed>}
     */
    public function probeServerAuthSupport(string $serverUrl): array
    {
        try {
            $metadata = $this->discoverOAuthMetadata($serverUrl);

            return [
                'supports_oauth' => true,
                'registration_supported' => !empty($metadata['registration_endpoint']),
                'message' => 'OAuth 2.0 supported'
                    . (!empty($metadata['registration_endpoint']) ? ' with automatic registration' : ''),
                'metadata' => $metadata,
            ];
        } catch (\Exception) {
            return [
                'supports_oauth' => false,
                'registration_supported' => false,
                'message' => 'OAuth 2.0 not detected on this server',
            ];
        }
    }

    /**
     * Extract the base URL (scheme + host) from a full MCP server URL.
     */
    private function getBaseUrl(string $serverUrl): string
    {
        $parsed = parse_url($serverUrl);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid server URL: ' . $serverUrl);
        }

        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
        if (!empty($parsed['port'])) {
            $baseUrl .= ':' . $parsed['port'];
        }

        return $baseUrl;
    }
}
