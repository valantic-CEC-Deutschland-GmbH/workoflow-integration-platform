<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\SapSacService;
use App\Service\Integration\AzureOboTokenService;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * SAP Analytics Cloud (SAC) Integration
 *
 * Enables AI agents to query analytics data from SAP SAC models.
 * Supports two authentication modes:
 * - Client Credentials: Two-legged OAuth 2.0 (service-to-service)
 * - User Delegation: Azure AD SAML2 bearer flow (actions attributed to users)
 *
 * Features:
 * - Model discovery (list models, get metadata)
 * - Data querying (query with filters, get dimension members)
 * - Story access (list and get dashboards/reports)
 */
class SapSacIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private SapSacService $sapSacService,
        private Environment $twig,
        private ?AzureOboTokenService $azureOboTokenService = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Get credentials with OAuth2 token exchange if in user_delegation mode.
     *
     * For user delegation, performs the token exchange:
     * Azure refresh_token → Azure access_token → SAML2 assertion → SAC access_token
     *
     * @param array $credentials Original credentials from config
     * @return array Credentials with valid access token for API calls
     */
    private function getUserDelegationCredentials(array $credentials): array
    {
        $authMode = $credentials['auth_mode'] ?? 'client_credentials';

        // For client credentials mode, use standard token flow
        if ($authMode === 'client_credentials') {
            return $this->sapSacService->ensureValidToken($credentials);
        }

        // For user delegation mode, perform Azure AD → SAML2 → SAC token exchange
        if ($authMode === 'user_delegation') {
            if (!$this->azureOboTokenService) {
                throw new \RuntimeException(
                    'Azure OAuth service not configured for user delegation mode.'
                );
            }

            // Check for required Azure refresh token
            if (empty($credentials['azure_refresh_token'])) {
                throw new \RuntimeException(
                    'Azure AD authorization required. Please click "Connect with Azure AD" to authorize.'
                );
            }

            // Check for required SAP SAC OAuth credentials
            if (empty($credentials['sac_oauth_client_id']) || empty($credentials['sac_oauth_client_secret'])) {
                throw new \RuntimeException(
                    'SAP SAC OAuth credentials required. Please configure OAuth Client ID and Secret.'
                );
            }

            try {
                $this->logger?->info('Starting OAuth2 token exchange for SAP SAC');

                // Get Azure AD app credentials from environment
                $azureClientId = $_ENV['AZURE_CLIENT_ID'] ?? '';
                $azureClientSecret = $_ENV['AZURE_CLIENT_SECRET'] ?? '';

                if (empty($azureClientId) || empty($azureClientSecret)) {
                    throw new \RuntimeException(
                        'Azure AD app credentials not configured. Please set AZURE_CLIENT_ID and AZURE_CLIENT_SECRET.'
                    );
                }

                // Use tenant ID from stored token or 'common' for multi-tenant
                $azureTenantId = $credentials['azure_tenant_id'] ?? 'common';

                // Derive SAC host from tenant URL for OBO scope
                $sacTenantHost = parse_url($credentials['tenant_url'], PHP_URL_HOST) ?? '';

                // Step 1: Refresh Azure access token
                $azureResult = $this->azureOboTokenService->refreshAccessToken(
                    $credentials['azure_refresh_token'],
                    $azureTenantId,
                    $azureClientId,
                    $azureClientSecret,
                    'openid profile email offline_access'
                );

                if (!$azureResult['success']) {
                    throw new \RuntimeException(
                        'Failed to refresh Azure AD token: ' . ($azureResult['error'] ?? 'Unknown error') .
                        '. Please reconnect via Azure AD.'
                    );
                }

                $azureAccessToken = $azureResult['access_token'];
                $this->logger?->debug('Azure access token refreshed successfully');

                // Step 2: Exchange Azure token for SAML2 assertion via OBO
                // The scope is derived from SAC tenant URL
                $samlAssertion = $this->azureOboTokenService->exchangeJwtForSaml2(
                    $azureAccessToken,
                    $azureTenantId,
                    $azureClientId,
                    $azureClientSecret,
                    $sacTenantHost
                );

                $this->logger?->debug('SAML2 assertion obtained from Azure AD');

                // Step 3: Exchange SAML2 assertion for SAP SAC token
                $sacResult = $this->sapSacService->exchangeSaml2ForSacToken(
                    $samlAssertion,
                    $credentials['tenant_url'],
                    $credentials['sac_oauth_client_id'],
                    $credentials['sac_oauth_client_secret']
                );

                if (!$sacResult['success']) {
                    throw new \RuntimeException(
                        'Failed to get SAP SAC token: ' . ($sacResult['error'] ?? 'Unknown error')
                    );
                }

                $this->logger?->info('SAP SAC OAuth2 token exchange completed successfully');

                // Return credentials with Bearer token for API calls
                return array_merge($credentials, [
                    'access_token' => $sacResult['access_token'],
                    'expires_at' => time() + ($sacResult['expires_in'] ?? 3600),
                ]);
            } catch (\Exception $e) {
                $this->logger?->error('OAuth2 token exchange failed: ' . $e->getMessage());
                throw $e;
            }
        }

        // Fallback - use client credentials
        return $this->sapSacService->ensureValidToken($credentials);
    }

    public function getType(): string
    {
        return 'sap_sac';
    }

    public function getName(): string
    {
        return 'SAP Analytics Cloud';
    }

    public function getTools(): array
    {
        return [
            // ================================================================
            // MODEL DISCOVERY TOOLS
            // ================================================================
            new ToolDefinition(
                'sac_list_models',
                'List all available analytics models/data providers in SAP SAC. Returns: Array of models with id, name, description. Use this first to discover what data is available.',
                []
            ),
            new ToolDefinition(
                'sac_get_model_metadata',
                'Get detailed metadata for a specific analytics model, including available dimensions (e.g., Customer, Product, Time) and measures (e.g., Revenue, Quantity). Use this to understand the model structure before querying data.',
                [
                    [
                        'name' => 'model_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Model/Provider ID from sac_list_models (e.g., "t.sac.model:SAC_MODEL_ID")'
                    ]
                ]
            ),

            // ================================================================
            // DATA QUERY TOOLS
            // ================================================================
            new ToolDefinition(
                'sac_query_model_data',
                'Query fact/master data from a SAC model with optional OData filters. Use this to retrieve actual analytics data like revenue, sales, etc. Returns: Array of data records matching the query.',
                [
                    [
                        'name' => 'model_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Model/Provider ID to query'
                    ],
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter expression. Examples: "Customer eq \'ACME\'", "Year eq \'2024\' and Month eq \'01\'", "Revenue gt 10000". Use dimension names from metadata.'
                    ],
                    [
                        'name' => 'select',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated list of properties to return (dimensions and measures). Example: "Customer,Product,Revenue,Quantity". If omitted, returns all properties.'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results to return (default: 100, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sac_get_dimension_members',
                'Get all valid values/members for a specific dimension. Use this to discover valid filter values (e.g., list all customer names, product IDs). Essential before filtering to know what values exist.',
                [
                    [
                        'name' => 'model_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Model/Provider ID'
                    ],
                    [
                        'name' => 'dimension',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Dimension name from model metadata (e.g., "Customer", "Product", "Time", "Region")'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of members to return (default: 100)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of members to skip for pagination (default: 0)'
                    ]
                ]
            ),

            // ================================================================
            // STORY (DASHBOARD) TOOLS
            // ================================================================
            new ToolDefinition(
                'sac_list_stories',
                'List available stories (dashboards/reports) in SAP SAC. Returns: Array of stories with id, name, description, created/modified times.',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of stories to return (default: 50)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of stories to skip for pagination (default: 0)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sac_get_story',
                'Get detailed information about a specific story, including associated data models. Use this to understand what data a dashboard visualizes.',
                [
                    [
                        'name' => 'story_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Story ID from sac_list_stories'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('SAP SAC integration requires credentials');
        }

        // Get credentials with valid access token (handles both client credentials and user delegation)
        $credentials = $this->getUserDelegationCredentials($credentials);

        return match ($toolName) {
            // Model discovery tools
            'sac_list_models' => $this->sapSacService->listModels($credentials),
            'sac_get_model_metadata' => $this->sapSacService->getModelMetadata(
                $credentials,
                $parameters['model_id']
            ),

            // Data query tools
            'sac_query_model_data' => $this->sapSacService->queryModelData(
                $credentials,
                $parameters['model_id'],
                $parameters['filter'] ?? null,
                $parameters['select'] ?? null,
                $parameters['top'] ?? 100,
                $parameters['skip'] ?? 0
            ),
            'sac_get_dimension_members' => $this->sapSacService->getDimensionMembers(
                $credentials,
                $parameters['model_id'],
                $parameters['dimension'],
                $parameters['top'] ?? 100,
                $parameters['skip'] ?? 0
            ),

            // Story tools
            'sac_list_stories' => $this->sapSacService->listStories(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),
            'sac_get_story' => $this->sapSacService->getStory(
                $credentials,
                $parameters['story_id']
            ),

            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        $authMode = $credentials['auth_mode'] ?? 'client_credentials';

        // Check tenant URL is required for all modes
        if (empty($credentials['tenant_url'])) {
            return false;
        }

        // For client credentials mode, validate client ID and secret
        if ($authMode === 'client_credentials') {
            if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
                return false;
            }

            // Use SapSacService to validate credentials by testing connection
            try {
                $result = $this->sapSacService->testConnectionDetailed($credentials);
                return $result['success'] ?? false;
            } catch (\Exception) {
                return false;
            }
        }

        // For user delegation mode, require SAC OAuth credentials
        if ($authMode === 'user_delegation') {
            // Require SAC OAuth credentials
            if (empty($credentials['sac_oauth_client_id']) || empty($credentials['sac_oauth_client_secret'])) {
                return false;
            }
            // If Azure tokens exist, consider it valid (connected)
            if (!empty($credentials['azure_refresh_token'])) {
                return true;
            }
            // No tokens yet - still valid config, just needs OAuth flow
            return true;
        }

        return false;
    }

    public function getCredentialFields(): array
    {
        return [
            // Base URL (always required)
            new CredentialField(
                'tenant_url',
                'url',
                'SAP SAC Tenant URL',
                'https://mycompany.eu10.hcs.cloud.sap',
                true,
                'Your SAP Analytics Cloud tenant URL (e.g., https://mycompany.eu10.hcs.cloud.sap)'
            ),

            // Authentication mode selector (default: user_delegation for better security)
            new CredentialField(
                'auth_mode',
                'select',
                'Authentication Mode',
                'user_delegation',
                true,
                'Choose how to authenticate with SAP SAC',
                [
                    'client_credentials' => 'Client Credentials (Service Account)',
                    'user_delegation' => 'User Delegation (via Azure AD)',
                ]
            ),

            // Client Credentials fields (shown when auth_mode=client_credentials)
            new CredentialField(
                'client_id',
                'text',
                'OAuth Client ID',
                null,
                false, // Not globally required, only for client_credentials
                'Client ID from SAC Admin > System Administration > App Integration',
                null,
                'auth_mode',
                'client_credentials'
            ),
            new CredentialField(
                'client_secret',
                'password',
                'OAuth Client Secret',
                null,
                false,
                'Client Secret from SAC App Integration. Stored encrypted.',
                null,
                'auth_mode',
                'client_credentials'
            ),

            // User Delegation fields (shown when auth_mode=user_delegation)
            // SAC OAuth credentials for SAML2 Bearer exchange
            new CredentialField(
                'sac_oauth_client_id',
                'text',
                'SAP SAC OAuth Client ID',
                null,
                false,
                'OAuth Client ID from SAC Admin > App Integration (with SAML2.0 Bearer grant)',
                null,
                'auth_mode',
                'user_delegation'
            ),
            new CredentialField(
                'sac_oauth_client_secret',
                'password',
                'SAP SAC OAuth Client Secret',
                null,
                false,
                'OAuth Client Secret from SAC App Integration. Stored encrypted.',
                null,
                'auth_mode',
                'user_delegation'
            ),
            // OAuth button field (shown when auth_mode=user_delegation, after config saved)
            new CredentialField(
                'oauth_sap_sac',
                'oauth',
                'Connect with Azure AD',
                null,
                false,
                'Authorize via Azure AD to enable user delegation. Actions will be attributed to individual users.',
                null,
                'auth_mode',
                'user_delegation'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/sap_sac.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }

    public function isExperimental(): bool
    {
        return true;
    }

    public function getSetupInstructions(): ?string
    {
        return <<<'HTML'
<div class="setup-instructions" data-auth-instructions="true">
    <h4>Setup Instructions</h4>
    <div class="auth-option" data-auth-mode="client_credentials">
        <strong>Client Credentials Setup (Service Account)</strong>
        <p>Service-to-service authentication. All actions attributed to the technical service account.</p>
        <ol>
            <li>In SAP SAC, go to Admin > System Administration > App Integration</li>
            <li>Create a new OAuth client with required scopes (Data Export API)</li>
            <li>Copy the Client ID and Secret to the fields below</li>
        </ol>
        <p class="note"><strong>Best for:</strong> Automated reporting, batch analytics, or when user attribution is not required.</p>
    </div>
    <div class="auth-option" data-auth-mode="user_delegation">
        <strong>User Delegation Setup (via Azure AD)</strong>
        <p>Actions attributed to individual users via Azure AD SSO.</p>
        <ol>
            <li><strong>SAP SAC Admin:</strong> Create OAuth client with SAML2.0 Bearer grant (Admin > App Integration)</li>
            <li><strong>Enter:</strong> OAuth Client ID and Secret below</li>
            <li><strong>Connect:</strong> Click "Connect with Azure AD" to authorize</li>
        </ol>
        <p class="note"><strong>Note:</strong> Users must exist in both Azure AD and SAP SAC with matching email addresses</p>
    </div>
    <p class="docs-link"><a href="https://help.sap.com/docs/SAP_ANALYTICS_CLOUD/14cac91febef464dbb1efce20e3f1613/a8e2f4f5a47f401588fbcff8b75e27cd.html" target="_blank" rel="noopener">SAP SAC API Documentation</a></p>
</div>
HTML;
    }

    public function getLogoPath(): string
    {
        return '/images/logos/SAP-Logo.svg';
    }
}
