<?php

namespace App\Service\Integration;

use App\Entity\IntegrationConfig;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CandisService
{
    private const TOKEN_REFRESH_BUFFER = 300; // 5 minutes buffer before expiry

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private EncryptionService $encryptionService
    ) {
    }

    /**
     * Get API base URL from credentials (organization-specific)
     */
    private function getApiBaseUrl(array $credentials): string
    {
        $organizationId = $credentials['organization_id'] ?? '';
        return "https://api.candis.io/v1/organizations/{$organizationId}";
    }

    /**
     * Test Candis connection with detailed error reporting
     *
     * @param array $credentials Candis credentials (OAuth tokens)
     * @return array Detailed test result with success, message, details, and suggestions
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            $credentials = $this->ensureValidToken($credentials);
            $baseUrl = $this->getApiBaseUrl($credentials);

            try {
                $response = $this->httpClient->request(
                    'GET',
                    $baseUrl . '/invoices',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $credentials['access_token'],
                            'Content-Type' => 'application/json',
                        ],
                        'query' => ['limit' => 1],
                        'timeout' => 10,
                    ]
                );

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/invoices?limit=1',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    $orgName = $credentials['organization_name'] ?? 'Unknown';
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to Candis. Organization: ' . $orgName,
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from Candis API",
                        'suggestion' => 'Check the error details and verify your Candis access.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/invoices?limit=1',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid or expired access token. Please reconnect to Candis.',
                        'suggestion' => 'Click "Reconnect" to refresh your Candis authorization.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your Candis app does not have sufficient permissions.',
                        'suggestion' => 'Ensure the app has the exports and core_data scopes.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your Candis access.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach Candis API',
                    'details' => 'Network error: ' . $e->getMessage(),
                    'suggestion' => 'Check your network connection.',
                    'tested_endpoints' => $testedEndpoints
                ];
            }
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
     * Ensure the access token is valid, refreshing if necessary
     *
     * @param array $credentials Current credentials
     * @param IntegrationConfig|null $config Optional config for persisting updated tokens
     * @return array Updated credentials with valid token
     */
    public function ensureValidToken(array $credentials, ?IntegrationConfig $config = null): array
    {
        $expiresAt = $credentials['expires_at'] ?? 0;

        if (time() >= ($expiresAt - self::TOKEN_REFRESH_BUFFER)) {
            $this->logger->info('Candis access token expiring soon, refreshing...');
            $credentials = $this->refreshAccessToken($credentials, $config);
        }

        return $credentials;
    }

    /**
     * Refresh the access token using the refresh token
     *
     * @param array $credentials Current credentials with refresh_token
     * @param IntegrationConfig|null $config Optional config for persisting updated tokens
     * @return array Updated credentials with new access token
     */
    private function refreshAccessToken(array $credentials, ?IntegrationConfig $config = null): array
    {
        if (empty($credentials['refresh_token'])) {
            throw new \RuntimeException('No refresh token available. Please reconnect to Candis.');
        }

        $clientId = $_ENV['CANDIS_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['CANDIS_CLIENT_SECRET'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('Candis OAuth credentials not configured.');
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://id.my.candis.io/auth/realms/candis/protocol/openid-connect/token',
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body' => [
                        'grant_type' => 'refresh_token',
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $credentials['refresh_token'],
                    ],
                ]
            );

            $data = $response->toArray();

            $credentials['access_token'] = $data['access_token'];
            $credentials['refresh_token'] = $data['refresh_token'] ?? $credentials['refresh_token'];
            $credentials['expires_at'] = time() + ($data['expires_in'] ?? 1209600); // ~14 days default

            if ($config !== null) {
                $config->setEncryptedCredentials(
                    $this->encryptionService->encrypt(json_encode($credentials))
                );
                $this->entityManager->flush();
                $this->logger->info('Candis tokens refreshed and persisted.');
            }

            return $credentials;
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $this->logger->error('Failed to refresh Candis token: ' . $e->getMessage());
            throw new \RuntimeException(
                'Failed to refresh Candis access token. Please reconnect to Candis.',
                0,
                $e
            );
        }
    }

    /**
     * Get organization info (used during OAuth callback)
     *
     * @param string $accessToken Access token
     * @return array Organization info with id and name
     */
    public function getOrganizationInfo(string $accessToken): array
    {
        $response = $this->httpClient->request(
            'GET',
            'https://api.candis.io/v1/organizations/info',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 10,
            ]
        );

        return $response->toArray();
    }

    // ========================================
    // INVOICES API (Tier 1)
    // ========================================

    /**
     * List invoices with optional filters
     *
     * @param array $credentials Candis credentials
     * @param string|null $status Filter by status (APPROVED, EXPORTED, etc.)
     * @param string|null $dateFrom Filter by date from (YYYY-MM-DD)
     * @param string|null $dateTo Filter by date to (YYYY-MM-DD)
     * @param int $limit Maximum results (default: 20)
     * @param int $offset Pagination offset (default: 0)
     * @return array Invoice list
     */
    public function listInvoices(
        array $credentials,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $credentials = $this->ensureValidToken($credentials);

        $queryParams = [
            'limit' => min($limit, 100),
            'offset' => $offset,
        ];

        if ($status) {
            $queryParams['status'] = $status;
        }
        if ($dateFrom) {
            $queryParams['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $queryParams['dateTo'] = $dateTo;
        }

        return $this->makeApiRequest($credentials, 'GET', '/invoices', null, $queryParams);
    }

    /**
     * Get a single invoice by ID
     *
     * @param array $credentials Candis credentials
     * @param string $invoiceId Invoice ID
     * @return array Invoice data
     */
    public function getInvoice(array $credentials, string $invoiceId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest($credentials, 'GET', "/invoices/{$invoiceId}");
    }

    /**
     * Update payment status of an invoice
     *
     * @param array $credentials Candis credentials
     * @param string $invoiceId Invoice ID
     * @param bool $isPaid Whether the invoice is paid
     * @param string|null $paymentDate Payment date (YYYY-MM-DD)
     * @return array Updated invoice data
     */
    public function updatePaymentStatus(
        array $credentials,
        string $invoiceId,
        bool $isPaid,
        ?string $paymentDate = null
    ): array {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [
            'isPaid' => $isPaid,
        ];

        if ($paymentDate) {
            $payload['paymentDate'] = $paymentDate;
        }

        return $this->makeApiRequest($credentials, 'PUT', "/invoices/{$invoiceId}", $payload);
    }

    // ========================================
    // REIMBURSEMENTS API (Tier 1)
    // ========================================

    /**
     * List reimbursement items with optional filters
     *
     * @param array $credentials Candis credentials
     * @param string|null $status Filter by status
     * @param string|null $type Filter by type (GENERAL_EXPENSE, HOSPITALITY_EXPENSE, PER_DIEM, MILEAGE)
     * @param string|null $dateFrom Filter by date from (YYYY-MM-DD)
     * @param string|null $dateTo Filter by date to (YYYY-MM-DD)
     * @param int $limit Maximum results (default: 20)
     * @param int $offset Pagination offset (default: 0)
     * @return array Reimbursement items
     */
    public function listReimbursements(
        array $credentials,
        ?string $status = null,
        ?string $type = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $credentials = $this->ensureValidToken($credentials);

        $queryParams = [
            'limit' => min($limit, 100),
            'offset' => $offset,
        ];

        if ($status) {
            $queryParams['status'] = $status;
        }
        if ($type) {
            $queryParams['type'] = $type;
        }
        if ($dateFrom) {
            $queryParams['dateFrom'] = $dateFrom;
        }
        if ($dateTo) {
            $queryParams['dateTo'] = $dateTo;
        }

        return $this->makeApiRequest($credentials, 'GET', '/reimbursement-items', null, $queryParams);
    }

    // ========================================
    // CORE DATA IMPORTS API (Tier 2)
    // ========================================

    /**
     * Import cost centers (create or update)
     *
     * @param array $credentials Candis credentials
     * @param array $costCenters Array of cost center objects with code and name
     * @return array Import result
     */
    public function importCostCenters(array $credentials, array $costCenters): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'PUT',
            '/imports/cost-centers',
            ['costCenters' => $costCenters]
        );
    }

    /**
     * Import contacts (create or update supplier contacts)
     *
     * @param array $credentials Candis credentials
     * @param array $contacts Array of contact objects
     * @return array Import result
     */
    public function importContacts(array $credentials, array $contacts): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'PUT',
            '/imports/contacts',
            ['contacts' => $contacts]
        );
    }

    /**
     * Import general ledger accounts (create or update)
     *
     * @param array $credentials Candis credentials
     * @param array $glAccounts Array of GL account objects with number and name
     * @return array Import result
     */
    public function importGlAccounts(array $credentials, array $glAccounts): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest(
            $credentials,
            'PUT',
            '/imports/gl-accounts',
            ['glAccounts' => $glAccounts]
        );
    }

    // ========================================
    // EXPORTS API (Tier 2)
    // ========================================

    /**
     * Create a new export
     *
     * @param array $credentials Candis credentials
     * @param string|null $type Export type
     * @return array Created export data with export ID
     */
    public function createExport(array $credentials, ?string $type = null): array
    {
        $credentials = $this->ensureValidToken($credentials);

        $payload = [];
        if ($type) {
            $payload['type'] = $type;
        }

        return $this->makeApiRequest($credentials, 'POST', '/exports', $payload);
    }

    /**
     * Get export status
     *
     * @param array $credentials Candis credentials
     * @param string $exportId Export ID
     * @return array Export status data
     */
    public function getExportStatus(array $credentials, string $exportId): array
    {
        $credentials = $this->ensureValidToken($credentials);

        return $this->makeApiRequest($credentials, 'GET', "/exports/{$exportId}");
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Make an API request to Candis
     *
     * @param array $credentials Candis credentials
     * @param string $method HTTP method
     * @param string $endpoint API endpoint (relative to base URL)
     * @param array|null $payload Request payload for POST/PUT
     * @param array $queryParams Query parameters for GET requests
     * @return array Response data
     */
    private function makeApiRequest(
        array $credentials,
        string $method,
        string $endpoint,
        ?array $payload = null,
        array $queryParams = []
    ): array {
        $baseUrl = $this->getApiBaseUrl($credentials);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['access_token'],
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($queryParams)) {
            $options['query'] = $queryParams;
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request(
                $method,
                $baseUrl . $endpoint,
                $options
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                if ($statusCode === 204) {
                    return ['success' => true, 'message' => 'Operation completed successfully'];
                }
                return $response->toArray();
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $error = $errorData['error'] ?? $errorData['message'] ?? 'Unknown error';
            $errorDescription = $errorData['error_description'] ?? $errorData['detail'] ?? '';

            $suggestion = $this->getSuggestionForError($statusCode);

            throw new \RuntimeException(
                "Candis API Error (HTTP {$statusCode}): {$error} - {$errorDescription}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get a helpful suggestion based on the error
     *
     * @param int $statusCode HTTP status code
     * @return string Suggestion message
     */
    private function getSuggestionForError(int $statusCode): string
    {
        if ($statusCode === 401) {
            return ' - Access token expired or invalid. Please reconnect to Candis.';
        } elseif ($statusCode === 403) {
            return ' - Insufficient permissions. Check your Candis app scopes.';
        } elseif ($statusCode === 404) {
            return ' - The requested resource was not found. Verify the ID is correct.';
        } elseif ($statusCode === 429) {
            return ' - Rate limit exceeded. Please wait a moment and try again.';
        }

        return '';
    }
}
