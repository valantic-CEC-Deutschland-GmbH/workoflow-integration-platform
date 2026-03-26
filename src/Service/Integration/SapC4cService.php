<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use InvalidArgumentException;
use App\Service\UrlNormalizer;

class SapC4cService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlNormalizer $urlNormalizer,
    ) {
    }

    /**
     * Validate and normalize SAP C4C base URL
     */
    private function validateAndNormalizeUrl(string $url): string
    {
        $normalized = $this->urlNormalizer->normalize($url);

        // Require HTTPS for SAP production domains
        $host = parse_url($normalized, PHP_URL_HOST) ?? '';
        if (str_contains($host, '.crm.ondemand.com') && !str_starts_with($normalized, 'https://')) {
            throw new InvalidArgumentException(
                sprintf('SAP C4C URL must use HTTPS for security. Got: "%s"', $url)
            );
        }

        return $normalized;
    }

    /**
     * Build authorization headers for SAP C4C API
     * Supports both Basic Auth and OAuth2 Bearer token authentication
     */
    private function getAuthHeaders(
        string $username,
        string $password,
        ?string $csrfToken = null,
        ?string $cookies = null
    ): array {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($csrfToken !== null) {
            $headers['X-CSRF-Token'] = $csrfToken;
        }

        if ($cookies !== null && $cookies !== '') {
            $headers['Cookie'] = $cookies;
        }

        return $headers;
    }

    /**
     * Get authorization headers based on credentials type
     * Supports both Basic Auth (username/password) and OAuth2 Bearer token
     *
     * @param array $credentials Credentials array with either username/password or access_token
     * @param string|null $csrfToken Optional CSRF token for write operations
     * @param string|null $cookies Optional cookies for session binding
     */
    public function getAuthHeadersForRequest(
        array $credentials,
        ?string $csrfToken = null,
        ?string $cookies = null
    ): array {
        // OAuth2 Bearer token (user delegation)
        if (isset($credentials['access_token'])) {
            $headers = [
                'Authorization' => 'Bearer ' . $credentials['access_token'],
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            if ($csrfToken !== null) {
                $headers['X-CSRF-Token'] = $csrfToken;
            }

            if ($cookies !== null && $cookies !== '') {
                $headers['Cookie'] = $cookies;
            }

            return $headers;
        }

        // Fallback to Basic Auth (technical user / test systems)
        return $this->getAuthHeaders(
            $credentials['username'] ?? '',
            $credentials['password'] ?? '',
            $csrfToken,
            $cookies
        );
    }

    /**
     * Check if credentials use OAuth2 authentication mode
     */
    public function isOAuth2Mode(array $credentials): bool
    {
        return ($credentials['auth_mode'] ?? 'basic') === 'oauth2';
    }

    /**
     * Build OData API endpoint URL
     */
    private function buildApiUrl(string $baseUrl, string $path): string
    {
        $baseUrl = $this->validateAndNormalizeUrl($baseUrl);
        // Defensive rtrim to ensure no double slashes even if normalization is bypassed
        $apiBase = rtrim($baseUrl, '/') . '/sap/c4c/odata/v1/c4codataapi';
        return $apiBase . $path;
    }

    /**
     * Fetch CSRF token for modifying operations using credentials array
     * Supports both Basic Auth and OAuth2 authentication modes
     *
     * @param array $credentials Credentials array
     * @return array Array with 'token' and 'cookies' keys
     */
    private function fetchCsrfTokenFromCredentials(array $credentials): array
    {
        try {
            // Use trailing slash to avoid 307 redirect that loses session cookies
            $url = $this->buildApiUrl($credentials['base_url'], '/');
            $response = $this->httpClient->request('GET', $url, [
                'headers' => array_merge(
                    $this->getAuthHeadersForRequest($credentials),
                    ['x-csrf-token' => 'fetch']
                ),
                'timeout' => 10,
            ]);

            // Extract CSRF token from response headers
            $headers = $response->getHeaders();
            $token = $headers['x-csrf-token'][0] ?? null;

            // Extract cookies from response headers (SAP CSRF tokens are session-bound)
            $cookies = [];
            if (isset($headers['set-cookie'])) {
                foreach ($headers['set-cookie'] as $cookie) {
                    // Extract cookie name=value part (before any attributes like ; Path=)
                    $cookieParts = explode(';', $cookie);
                    $cookies[] = trim($cookieParts[0]);
                }
            }

            return [
                'token' => $token,
                'cookies' => implode('; ', $cookies),
            ];
        } catch (\Exception) {
            // If CSRF token fetch fails, return empty (some technical users may not need it)
            return ['token' => null, 'cookies' => ''];
        }
    }

    /**
     * Legacy method for backward compatibility
     * @deprecated Use fetchCsrfTokenFromCredentials instead
     */
    private function fetchCsrfToken(string $baseUrl, string $username, string $password): array
    {
        return $this->fetchCsrfTokenFromCredentials([
            'base_url' => $baseUrl,
            'username' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Unwrap OData response from {"d": {...}} structure
     */
    private function unwrapODataResponse(array $response): array
    {
        if (isset($response['d'])) {
            $data = $response['d'];

            // Handle collection responses with {"d": {"results": [...]}}
            if (isset($data['results']) && is_array($data['results'])) {
                return $data['results'];
            }

            // Handle single entity responses with {"d": {...entity...}}
            return $data;
        }

        // Already unwrapped or unexpected format
        return $response;
    }

    /**
     * Parse OData error response
     */
    private function parseODataError(\Throwable $e): string
    {
        if ($e instanceof ClientExceptionInterface) {
            try {
                $response = $e->getResponse();
                $content = $response->toArray(false);

                if (isset($content['error']['message']['value'])) {
                    return $content['error']['message']['value'];
                }

                if (isset($content['error']['message'])) {
                    return is_string($content['error']['message'])
                        ? $content['error']['message']
                        : json_encode($content['error']['message']);
                }
            } catch (\Throwable) {
                // Fall through to default message
            }
        }

        return $e->getMessage();
    }

    /**
     * Test SAP C4C connection
     */
    public function testConnection(array $credentials): bool
    {
        $result = $this->testConnectionDetailed($credentials);
        return $result['success'];
    }

    /**
     * Test SAP C4C connection with detailed error reporting
     * Supports both Basic Auth and OAuth2 authentication modes
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];
        $authMode = $credentials['auth_mode'] ?? 'basic';

        // For OAuth2 mode, we can only verify the base URL is valid
        // since we don't have a user token at setup time
        if ($authMode === 'oauth2') {
            return $this->testOAuth2Configuration($credentials);
        }

        try {
            $url = $this->buildApiUrl($credentials['base_url'], '/LeadCollection');

            try {
                $response = $this->httpClient->request('GET', $url . '?$top=1&$format=json', [
                    'headers' => $this->getAuthHeadersForRequest($credentials),
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/LeadCollection?$top=1',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to SAP C4C. API authentication verified.',
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Connection failed',
                    'details' => "HTTP {$statusCode}: Unexpected response from SAP C4C API",
                    'suggestion' => 'Check the error details and verify your SAP C4C instance is accessible.',
                    'tested_endpoints' => $testedEndpoints
                ];
            } catch (ClientExceptionInterface $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/LeadCollection?$top=1',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid username or password. Please verify your credentials.',
                        'suggestion' => 'Check that your username and password are correct for your SAP C4C instance.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }

                if ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your user does not have sufficient permissions to access the Lead API.',
                        'suggestion' => 'Ensure your SAP C4C user has permissions to access OData APIs and Lead data.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }

                if ($statusCode === 404) {
                    return [
                        'success' => false,
                        'message' => 'API endpoint not found',
                        'details' => 'The URL may not be a valid SAP C4C instance or the OData API is not available.',
                        'suggestion' => 'Verify the base URL points to your SAP C4C tenant (e.g., https://myXXXXXX.crm.ondemand.com)',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Connection failed',
                    'details' => "HTTP {$statusCode}: " . $this->parseODataError($e),
                    'suggestion' => 'Check the error details and verify your SAP C4C instance is accessible.',
                    'tested_endpoints' => $testedEndpoints
                ];
            } catch (TransportExceptionInterface $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach SAP C4C server',
                    'details' => 'Network error: ' . $e->getMessage(),
                    'suggestion' => 'Check the base URL and your network connection. Verify the domain is correct and accessible.',
                    'tested_endpoints' => $testedEndpoints
                ];
            }
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => 'Invalid URL',
                'details' => $e->getMessage(),
                'suggestion' => 'Enter a valid SAP C4C base URL like https://myXXXXXX.crm.ondemand.com',
                'tested_endpoints' => $testedEndpoints
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Unexpected error',
                'details' => $e->getMessage(),
                'suggestion' => 'Please check your configuration and try again.',
                'tested_endpoints' => $testedEndpoints
            ];
        }
    }

    /**
     * Test OAuth2 configuration by validating credentials format and URL accessibility
     * Note: Full OAuth2 flow validation requires a user token, which isn't available at setup time
     */
    private function testOAuth2Configuration(array $credentials): array
    {
        $testedEndpoints = [];

        // Validate required OAuth2 credentials are present
        if (empty($credentials['azure_tenant_id'])) {
            return [
                'success' => false,
                'message' => 'Missing Azure AD Tenant ID',
                'details' => 'Azure AD Tenant ID is required for OAuth2 authentication.',
                'suggestion' => 'Enter your Azure AD tenant ID (GUID format).',
                'tested_endpoints' => $testedEndpoints,
            ];
        }

        if (empty($credentials['c4c_oauth_client_id']) || empty($credentials['c4c_oauth_client_secret'])) {
            return [
                'success' => false,
                'message' => 'Missing SAP C4C OAuth credentials',
                'details' => 'SAP C4C OAuth Client ID and Secret are required.',
                'suggestion' => 'Enter OAuth credentials from SAP C4C Administrator > OAuth 2.0 Client Registration.',
                'tested_endpoints' => $testedEndpoints,
            ];
        }

        // Validate Azure Tenant ID format (GUID)
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $credentials['azure_tenant_id'])) {
            return [
                'success' => false,
                'message' => 'Invalid Azure AD Tenant ID format',
                'details' => 'Azure AD Tenant ID must be a GUID (e.g., xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx).',
                'suggestion' => 'Check your Azure AD tenant ID in the Azure Portal.',
                'tested_endpoints' => $testedEndpoints,
            ];
        }

        // Validate the base URL is reachable
        try {
            $url = $this->validateAndNormalizeUrl($credentials['base_url']);

            // Try to access the OData metadata endpoint (no auth required for existence check)
            $metadataUrl = $url . '/sap/c4c/odata/v1/c4codataapi/$metadata';
            $response = $this->httpClient->request('GET', $metadataUrl, [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/xml',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $testedEndpoints[] = [
                'endpoint' => '/$metadata',
                'status' => in_array($statusCode, [200, 401, 403]) ? 'success' : 'failed',
                'http_code' => $statusCode,
            ];

            // 401/403 means the endpoint exists but requires auth (expected)
            if (in_array($statusCode, [200, 401, 403])) {
                return [
                    'success' => true,
                    'message' => 'OAuth2 configuration validated',
                    'details' => 'SAP C4C instance is accessible. OAuth2 credentials will be validated when users authenticate.',
                    'suggestion' => 'Ensure Azure AD is configured as Identity Provider in SAP C4C Admin.',
                    'tested_endpoints' => $testedEndpoints,
                    'auth_mode' => 'oauth2',
                ];
            }

            return [
                'success' => false,
                'message' => 'SAP C4C endpoint not found',
                'details' => "HTTP {$statusCode}: The OData API endpoint is not accessible.",
                'suggestion' => 'Verify the base URL points to a valid SAP C4C instance.',
                'tested_endpoints' => $testedEndpoints,
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => 'Invalid URL',
                'details' => $e->getMessage(),
                'suggestion' => 'Enter a valid SAP C4C base URL like https://myXXXXXX.crm.ondemand.com',
                'tested_endpoints' => $testedEndpoints,
            ];
        } catch (TransportExceptionInterface $e) {
            return [
                'success' => false,
                'message' => 'Cannot reach SAP C4C server',
                'details' => 'Network error: ' . $e->getMessage(),
                'suggestion' => 'Check the base URL and network connectivity.',
                'tested_endpoints' => $testedEndpoints,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Validation error',
                'details' => $e->getMessage(),
                'suggestion' => 'Please check your configuration and try again.',
                'tested_endpoints' => $testedEndpoints,
            ];
        }
    }

    /**
     * Search leads using OData filter
     */
    public function searchLeads(
        array $credentials,
        ?string $filter = null,
        int $top = 50,
        int $skip = 0,
        ?string $orderby = null
    ): array {
        $url = $this->buildApiUrl($credentials['base_url'], '/LeadCollection');

        $query = [
            '$format' => 'json',
            '$top' => $top,
            '$skip' => $skip,
        ];

        if ($filter !== null) {
            $query['$filter'] = $filter;
        }

        if ($orderby !== null) {
            $query['$orderby'] = $orderby;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials),
                'query' => $query,
            ]);

            $data = $response->toArray();
            $leads = $this->unwrapODataResponse($data);

            // Add pagination metadata
            return [
                'leads' => $leads,
                'count' => count($leads),
                'top' => $top,
                'skip' => $skip,
                'has_more' => count($leads) === $top,
                'next_skip' => $skip + $top,
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to search leads: ' . $this->parseODataError($e), 0, $e);
        }
    }

    /**
     * Get single lead by ObjectID
     */
    public function getLead(array $credentials, string $leadId): array
    {
        $url = $this->buildApiUrl($credentials['base_url'], "/LeadCollection('{$leadId}')");

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials),
                'query' => ['$format' => 'json'],
            ]);

            $data = $response->toArray();
            return $this->unwrapODataResponse($data);
        } catch (ClientExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                throw new \RuntimeException("Lead with ID '{$leadId}' not found", 404, $e);
            }
            throw new \RuntimeException('Failed to get lead: ' . $this->parseODataError($e), 0, $e);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to get lead: ' . $this->parseODataError($e), 0, $e);
        }
    }

    /**
     * Create new lead
     */
    public function createLead(array $credentials, array $leadData): array
    {
        $url = $this->buildApiUrl($credentials['base_url'], '/LeadCollection');

        // Fetch CSRF token with cookies
        $csrfData = $this->fetchCsrfTokenFromCredentials($credentials);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials, $csrfData['token'], $csrfData['cookies']),
                'json' => $leadData,
            ]);

            $data = $response->toArray();
            return $this->unwrapODataResponse($data);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to create lead: ' . $this->parseODataError($e), 0, $e);
        }
    }

    /**
     * Update existing lead
     */
    public function updateLead(array $credentials, string $leadId, array $leadData): array
    {
        $url = $this->buildApiUrl($credentials['base_url'], "/LeadCollection('{$leadId}')");

        // Fetch CSRF token with cookies
        $csrfData = $this->fetchCsrfTokenFromCredentials($credentials);

        try {
            $response = $this->httpClient->request('PATCH', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials, $csrfData['token'], $csrfData['cookies']),
                'json' => $leadData,
            ]);

            // PATCH may return 204 No Content on success
            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                // Fetch updated lead
                return $this->getLead($credentials, $leadId);
            }

            $data = $response->toArray();
            return $this->unwrapODataResponse($data);
        } catch (ClientExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                throw new \RuntimeException("Lead with ID '{$leadId}' not found", 404, $e);
            }
            throw new \RuntimeException('Failed to update lead: ' . $this->parseODataError($e), 0, $e);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update lead: ' . $this->parseODataError($e), 0, $e);
        }
    }

    /**
     * List leads with pagination (simpler than search, no filters)
     */
    public function listLeads(array $credentials, int $top = 50, int $skip = 0): array
    {
        return $this->searchLeads($credentials, null, $top, $skip, null);
    }

    // ========================================================================
    // GENERIC COLLECTION METHODS
    // ========================================================================

    /**
     * Generic method to search any OData collection
     */
    private function searchCollection(
        array $credentials,
        string $collectionName,
        string $resultKey,
        ?string $filter = null,
        int $top = 50,
        int $skip = 0,
        ?string $orderby = null,
        ?string $expand = null
    ): array {
        $url = $this->buildApiUrl($credentials['base_url'], '/' . $collectionName);

        $query = [
            '$format' => 'json',
            '$top' => $top,
            '$skip' => $skip,
        ];

        if ($filter !== null) {
            $query['$filter'] = $filter;
        }

        if ($orderby !== null) {
            $query['$orderby'] = $orderby;
        }

        if ($expand !== null) {
            $query['$expand'] = $expand;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials),
                'query' => $query,
            ]);

            $data = $response->toArray();
            $results = $this->unwrapODataResponse($data);

            return [
                $resultKey => $results,
                'count' => count($results),
                'top' => $top,
                'skip' => $skip,
                'has_more' => count($results) === $top,
                'next_skip' => $skip + $top,
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to search {$collectionName}: " . $this->parseODataError($e),
                0,
                $e
            );
        }
    }

    /**
     * Generic method to get a single entity by ObjectID
     */
    private function getEntity(
        array $credentials,
        string $collectionName,
        string $entityId,
        string $entityName,
        ?string $expand = null
    ): array {
        $url = $this->buildApiUrl($credentials['base_url'], "/{$collectionName}('{$entityId}')");

        $query = ['$format' => 'json'];
        if ($expand !== null) {
            $query['$expand'] = $expand;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials),
                'query' => $query,
            ]);

            $data = $response->toArray();
            return $this->unwrapODataResponse($data);
        } catch (ClientExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                throw new \RuntimeException("{$entityName} with ID '{$entityId}' not found", 404, $e);
            }
            throw new \RuntimeException(
                "Failed to get {$entityName}: " . $this->parseODataError($e),
                0,
                $e
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to get {$entityName}: " . $this->parseODataError($e),
                0,
                $e
            );
        }
    }

    /**
     * Generic method to create a new entity
     */
    private function createEntity(
        array $credentials,
        string $collectionName,
        array $entityData,
        string $entityName
    ): array {
        $url = $this->buildApiUrl($credentials['base_url'], '/' . $collectionName);

        $csrfData = $this->fetchCsrfTokenFromCredentials($credentials);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials, $csrfData['token'], $csrfData['cookies']),
                'json' => $entityData,
            ]);

            $data = $response->toArray();
            return $this->unwrapODataResponse($data);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to create {$entityName}: " . $this->parseODataError($e),
                0,
                $e
            );
        }
    }

    /**
     * Generic method to update an existing entity
     */
    private function updateEntity(
        array $credentials,
        string $collectionName,
        string $entityId,
        array $entityData,
        string $entityName
    ): array {
        $url = $this->buildApiUrl($credentials['base_url'], "/{$collectionName}('{$entityId}')");

        $csrfData = $this->fetchCsrfTokenFromCredentials($credentials);

        try {
            $response = $this->httpClient->request('PATCH', $url, [
                'headers' => $this->getAuthHeadersForRequest($credentials, $csrfData['token'], $csrfData['cookies']),
                'json' => $entityData,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                return $this->getEntity($credentials, $collectionName, $entityId, $entityName);
            }

            $data = $response->toArray();
            return $this->unwrapODataResponse($data);
        } catch (ClientExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === 404) {
                throw new \RuntimeException("{$entityName} with ID '{$entityId}' not found", 404, $e);
            }
            throw new \RuntimeException(
                "Failed to update {$entityName}: " . $this->parseODataError($e),
                0,
                $e
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to update {$entityName}: " . $this->parseODataError($e),
                0,
                $e
            );
        }
    }

    // ========================================================================
    // OPPORTUNITY METHODS
    // ========================================================================

    /**
     * Search opportunities using OData filter
     */
    public function searchOpportunities(
        array $credentials,
        ?string $filter = null,
        int $top = 50,
        int $skip = 0,
        ?string $orderby = null,
        ?string $expand = null
    ): array {
        return $this->searchCollection(
            $credentials,
            'OpportunityCollection',
            'opportunities',
            $filter,
            $top,
            $skip,
            $orderby,
            $expand
        );
    }

    /**
     * Get single opportunity by ObjectID
     */
    public function getOpportunity(
        array $credentials,
        string $opportunityId,
        ?string $expand = null
    ): array {
        return $this->getEntity(
            $credentials,
            'OpportunityCollection',
            $opportunityId,
            'Opportunity',
            $expand
        );
    }

    /**
     * Create new opportunity
     */
    public function createOpportunity(array $credentials, array $opportunityData): array
    {
        return $this->createEntity(
            $credentials,
            'OpportunityCollection',
            $opportunityData,
            'Opportunity'
        );
    }

    /**
     * Update existing opportunity
     */
    public function updateOpportunity(
        array $credentials,
        string $opportunityId,
        array $opportunityData
    ): array {
        return $this->updateEntity(
            $credentials,
            'OpportunityCollection',
            $opportunityId,
            $opportunityData,
            'Opportunity'
        );
    }

    /**
     * List opportunities with pagination
     */
    public function listOpportunities(
        array $credentials,
        int $top = 50,
        int $skip = 0,
        ?string $expand = null
    ): array {
        return $this->searchOpportunities($credentials, null, $top, $skip, null, $expand);
    }

    // ========================================================================
    // ACCOUNT METHODS
    // ========================================================================

    /**
     * Search accounts using OData filter
     */
    public function searchAccounts(
        array $credentials,
        ?string $filter = null,
        int $top = 50,
        int $skip = 0,
        ?string $orderby = null,
        bool $expandContacts = false
    ): array {
        $expand = $expandContacts ? 'CorporateAccountHasContactPerson' : null;
        return $this->searchCollection(
            $credentials,
            'CorporateAccountCollection',
            'accounts',
            $filter,
            $top,
            $skip,
            $orderby,
            $expand
        );
    }

    /**
     * Get single account by ObjectID
     */
    public function getAccount(
        array $credentials,
        string $accountId,
        bool $expandContacts = false
    ): array {
        $expand = $expandContacts ? 'CorporateAccountHasContactPerson' : null;
        return $this->getEntity(
            $credentials,
            'CorporateAccountCollection',
            $accountId,
            'Account',
            $expand
        );
    }

    /**
     * Create new account
     */
    public function createAccount(array $credentials, array $accountData): array
    {
        return $this->createEntity(
            $credentials,
            'CorporateAccountCollection',
            $accountData,
            'Account'
        );
    }

    /**
     * Update existing account
     */
    public function updateAccount(array $credentials, string $accountId, array $accountData): array
    {
        return $this->updateEntity(
            $credentials,
            'CorporateAccountCollection',
            $accountId,
            $accountData,
            'Account'
        );
    }

    /**
     * List accounts with pagination
     */
    public function listAccounts(array $credentials, int $top = 50, int $skip = 0): array
    {
        return $this->searchAccounts($credentials, null, $top, $skip, null, false);
    }

    // ========================================================================
    // CONTACT METHODS
    // ========================================================================

    /**
     * Search contacts using OData filter
     */
    public function searchContacts(
        array $credentials,
        ?string $filter = null,
        int $top = 50,
        int $skip = 0,
        ?string $orderby = null
    ): array {
        return $this->searchCollection(
            $credentials,
            'ContactCollection',
            'contacts',
            $filter,
            $top,
            $skip,
            $orderby
        );
    }

    /**
     * Get single contact by ObjectID
     */
    public function getContact(array $credentials, string $contactId): array
    {
        return $this->getEntity(
            $credentials,
            'ContactCollection',
            $contactId,
            'Contact'
        );
    }

    /**
     * Create new contact
     */
    public function createContact(array $credentials, array $contactData): array
    {
        return $this->createEntity(
            $credentials,
            'ContactCollection',
            $contactData,
            'Contact'
        );
    }

    /**
     * Update existing contact
     */
    public function updateContact(array $credentials, string $contactId, array $contactData): array
    {
        return $this->updateEntity(
            $credentials,
            'ContactCollection',
            $contactId,
            $contactData,
            'Contact'
        );
    }

    /**
     * List contacts with pagination
     */
    public function listContacts(array $credentials, int $top = 50, int $skip = 0): array
    {
        return $this->searchContacts($credentials, null, $top, $skip, null);
    }

    // ========================================================================
    // METADATA METHODS
    // ========================================================================

    /**
     * Get OData metadata for a specific entity type to discover available properties
     */
    public function getEntityMetadata(array $credentials, string $entityType): array
    {
        $url = $this->buildApiUrl($credentials['base_url'], '/$metadata');

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(
                        $credentials['username'] . ':' . $credentials['password']
                    ),
                    'Accept' => 'application/xml',
                ],
                'query' => ['$filter' => $entityType],
                'timeout' => 30,
            ]);

            $xml = $response->getContent();
            return $this->parseMetadataXml($xml, $entityType);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to get metadata for {$entityType}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Parse OData metadata XML and extract property information
     */
    private function parseMetadataXml(string $xml, string $entityType): array
    {
        $doc = new \DOMDocument();
        @$doc->loadXML($xml);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('edmx', 'http://schemas.microsoft.com/ado/2007/06/edmx');
        $xpath->registerNamespace('edm', 'http://schemas.microsoft.com/ado/2008/09/edm');

        // Find the EntityType - try with and without Collection suffix
        $entityTypes = $xpath->query("//edm:EntityType[@Name='{$entityType}']");
        if ($entityTypes->length === 0) {
            $baseName = str_replace('Collection', '', $entityType);
            $entityTypes = $xpath->query("//edm:EntityType[@Name='{$baseName}']");
        }

        $properties = [];
        $filterableProperties = [];
        $creatableProperties = [];
        $updatableProperties = [];
        $mandatoryProperties = [];

        foreach ($entityTypes as $entityTypeNode) {
            $propertyNodes = $xpath->query('.//edm:Property', $entityTypeNode);

            foreach ($propertyNodes as $prop) {
                /** @var \DOMElement $prop */
                $name = $prop->getAttribute('Name');
                $type = $prop->getAttribute('Type');
                $nullable = $prop->getAttribute('Nullable') !== 'false';

                // SAP-specific annotations use sap: namespace
                $filterable = $prop->getAttribute('sap:filterable') !== 'false';
                $creatable = $prop->getAttribute('sap:creatable') !== 'false';
                $updatable = $prop->getAttribute('sap:updatable') !== 'false';

                // Mandatory = NOT nullable AND creatable (per SAP OData documentation)
                $isMandatory = !$nullable && $creatable;

                $properties[] = [
                    'name' => $name,
                    'type' => $type,
                    'nullable' => $nullable,
                    'filterable' => $filterable,
                    'creatable' => $creatable,
                    'updatable' => $updatable,
                    'mandatory' => $isMandatory,
                ];

                if ($filterable) {
                    $filterableProperties[] = $name;
                }
                if ($creatable) {
                    $creatableProperties[] = $name;
                }
                if ($updatable) {
                    $updatableProperties[] = $name;
                }
                if ($isMandatory) {
                    $mandatoryProperties[] = $name;
                }
            }
        }

        return [
            'entity_type' => $entityType,
            'property_count' => count($properties),
            'filterable_count' => count($filterableProperties),
            'creatable_count' => count($creatableProperties),
            'updatable_count' => count($updatableProperties),
            'mandatory_count' => count($mandatoryProperties),
            'filterable_properties' => $filterableProperties,
            'creatable_properties' => $creatableProperties,
            'updatable_properties' => $updatableProperties,
            'mandatory_properties' => $mandatoryProperties,
            'all_properties' => $properties,
            'hint' => 'Use filterable_properties in $filter queries. mandatory_properties lists fields that MUST have values when creating (Nullable=false AND sap:creatable=true).',
        ];
    }
}
