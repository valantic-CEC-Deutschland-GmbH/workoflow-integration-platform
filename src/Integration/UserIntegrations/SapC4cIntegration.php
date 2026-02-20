<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\SapC4cService;
use App\Service\Integration\AzureOboTokenService;
use App\Service\Integration\SapC4cOAuthService;
use Psr\Log\LoggerInterface;
use Twig\Environment;

// https://github.com/SAP-archive/C4CODATAAPIDEVGUIDE
class SapC4cIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private SapC4cService $sapC4cService,
        private Environment $twig,
        private ?AzureOboTokenService $azureOboTokenService = null,
        private ?SapC4cOAuthService $sapC4cOAuthService = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Get credentials with OAuth2 token exchange if in user_delegation mode.
     *
     * For user delegation, performs the token exchange:
     * Azure refresh_token → Azure access_token → SAML2 assertion → SAP C4C access_token
     *
     * @param array $credentials Original credentials from config
     * @return array Credentials with valid access token for API calls
     */
    private function getOAuth2EnrichedCredentials(array $credentials): array
    {
        $authMode = $credentials['auth_mode'] ?? 'basic';

        // For basic auth, return credentials as-is
        if ($authMode === 'basic') {
            return $credentials;
        }

        // For user delegation, perform token exchange
        if ($authMode === 'user_delegation') {
            if (!$this->azureOboTokenService || !$this->sapC4cOAuthService) {
                throw new \RuntimeException(
                    'OAuth2 services not configured. Please ensure AzureOboTokenService and SapC4cOAuthService are available.'
                );
            }

            // Check for required Azure refresh token
            if (empty($credentials['azure_refresh_token'])) {
                throw new \RuntimeException(
                    'Azure AD authorization required. Please click "Connect with Azure AD" to authorize.'
                );
            }

            // Check for required SAP C4C OAuth credentials
            if (empty($credentials['c4c_oauth_client_id']) || empty($credentials['c4c_oauth_client_secret'])) {
                throw new \RuntimeException(
                    'SAP C4C OAuth credentials required. Please configure OAuth Client ID and Secret.'
                );
            }

            try {
                $this->logger?->info('Starting OAuth2 token exchange for SAP C4C');

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

                // Derive C4C host from base URL for OBO scope
                $c4cTenantHost = parse_url($credentials['base_url'], PHP_URL_HOST) ?? '';

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
                // The scope is derived from C4C base URL
                $samlAssertion = $this->azureOboTokenService->exchangeJwtForSaml2(
                    $azureAccessToken,
                    $azureTenantId,
                    $azureClientId,
                    $azureClientSecret,
                    $c4cTenantHost
                );

                $this->logger?->debug('SAML2 assertion obtained from Azure AD');

                // Step 3: Exchange SAML2 assertion for SAP C4C token
                $c4cResult = $this->sapC4cOAuthService->exchangeSaml2ForC4cToken(
                    $samlAssertion,
                    $credentials['base_url'],
                    $credentials['c4c_oauth_client_id'],
                    $credentials['c4c_oauth_client_secret']
                );

                if (!$c4cResult['success']) {
                    throw new \RuntimeException(
                        'Failed to get SAP C4C token: ' . ($c4cResult['error'] ?? 'Unknown error')
                    );
                }

                $this->logger?->info('SAP C4C OAuth2 token exchange completed successfully');

                // Return credentials with Bearer token for API calls
                return array_merge($credentials, [
                    'c4c_access_token' => $c4cResult['access_token'],
                    'auth_type' => 'bearer',
                ]);
            } catch (\Exception $e) {
                $this->logger?->error('OAuth2 token exchange failed: ' . $e->getMessage());
                throw $e;
            }
        }

        return $credentials;
    }

    public function getType(): string
    {
        return 'sap_c4c';
    }

    public function getName(): string
    {
        return 'SAP Cloud for Customer';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'c4c_create_lead',
                'Create a new lead in SAP C4C. Returns: Object with ObjectID (auto-generated ID), Name, StatusCode, QualificationLevelCode, AccountPartyID, ContactFirstName, ContactLastName, Company, OriginTypeCode, and all other lead fields',
                [
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Lead name/title (required)'
                    ],
                    [
                        'name' => 'contact_last_name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact person last name (required)'
                    ],
                    [
                        'name' => 'contact_first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Contact person first name'
                    ],
                    [
                        'name' => 'company',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Company/organization name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C account party ID (if linking to existing account)'
                    ],
                    [
                        'name' => 'qualification_level_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Lead qualification level (e.g., "01", "02", "03", "04"). When set, StatusCode automatically becomes "Qualified"'
                    ],
                    [
                        'name' => 'origin_type_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Lead source/origin code (e.g., "1" for direct contact)'
                    ],
                    [
                        'name' => 'group_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Lead category/group code'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C lead fields (for custom fields or other standard fields not listed above)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_lead',
                'Retrieve detailed information about a specific lead by its ObjectID. Returns: Object with ObjectID, Name, StatusCode, QualificationLevelCode, AccountPartyID, ContactFirstName, ContactLastName, Company, OriginTypeCode, GroupCode, OwnerPartyID, CreatedOn, ChangedOn, and all other lead fields',
                [
                    [
                        'name' => 'lead_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Lead ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_leads',
                'Search leads using OData filter syntax. Returns: Object with leads array, count, pagination metadata (has_more, next_skip). Example filters: "StatusCode eq \'2\'" (status equals 2), "Company eq \'ACME Corp\'" (company equals ACME Corp), "QualificationLevelCode eq \'03\'" (qualification level 03)',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query. Supports: eq, ne, gt, lt, ge, le, and, or. String functions: startswith(Field, \'value\'), endswith(Field, \'value\'), substringof(\'value\', Field) for partial matching. CRITICAL: substringof has REVERSED parameter order! NEVER use contains() - it is NOT supported in OData V2. Example: substringof(\'Acme\', Company) finds companies containing "Acme".'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results to return (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0)'
                    ],
                    [
                        'name' => 'orderby',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $orderby clause for sorting (e.g., "Name asc", "CreatedOn desc")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_lead',
                'Update an existing lead\'s fields. Returns: Updated lead object with ObjectID and all modified fields. Note: You can only update fields that have sap:updatable="true" in the SAP C4C metadata.',
                [
                    [
                        'name' => 'lead_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Lead ObjectID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New lead name/title'
                    ],
                    [
                        'name' => 'status_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New status code (e.g., "2" for qualified, "3" for converted)'
                    ],
                    [
                        'name' => 'qualification_level_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New qualification level (e.g., "01", "02", "03", "04")'
                    ],
                    [
                        'name' => 'contact_first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New contact first name'
                    ],
                    [
                        'name' => 'contact_last_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New contact last name'
                    ],
                    [
                        'name' => 'company',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New company name'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C lead fields to update (for custom fields or other standard fields not listed above)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_leads',
                'Get a paginated list of all leads without filters. Simpler than search for browsing leads. Returns: Object with leads array, count, pagination metadata (has_more, next_skip)',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0). Use next_skip from previous response.'
                    ]
                ]
            ),

            // ================================================================
            // OPPORTUNITY TOOLS
            // ================================================================
            new ToolDefinition(
                'c4c_create_opportunity',
                'Create a new sales opportunity in SAP C4C. IMPORTANT: For tenant-specific fields (sales phase, closing date, status), first call c4c_get_entity_metadata("Opportunity") to discover correct field names, then pass them via additional_fields. Returns: Object with ObjectID and all opportunity fields.',
                [
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Opportunity name/subject (required)'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C AccountID (short numeric like "10025480" from the AccountID field in search results, NOT the 32-char ObjectID) to link this opportunity to'
                    ],
                    [
                        'name' => 'main_contact_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C ContactID (short numeric like "10025484" from the ContactID field in search results, NOT the 32-char ObjectID) for primary contact'
                    ],
                    [
                        'name' => 'expected_revenue_amount',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Expected revenue/deal value (numeric string)'
                    ],
                    [
                        'name' => 'currency_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Currency code (e.g., "EUR", "USD", "GBP")'
                    ],
                    [
                        'name' => 'probability_percent',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Win probability percentage (0-100)'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with SAP C4C field names from metadata. Use c4c_get_entity_metadata to discover correct field names. IMPORTANT: Date fields MUST use ISO 8601 format "YYYY-MM-DDTHH:mm:ss" (e.g., "2026-03-03T00:00:00"), NOT "YYYY-MM-DD". Examples: SalesCyclePhaseCode for sales phase, ExpectedProcessingEndDate for closing date.'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_opportunity',
                'Retrieve detailed information about a specific opportunity by its ObjectID. Returns: Object with ObjectID, Name, AccountPartyID, MainContactPartyID, ExpectedRevenueAmount, SalesPhaseCode, StatusCode, ProbabilityPercent, ExpectedClosingDate, and all other opportunity fields. Use expand to include related collections.',
                [
                    [
                        'name' => 'opportunity_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Opportunity ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ],
                    [
                        'name' => 'expand',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated navigation properties to expand (e.g., "OpportunityParty,OpportunityItem"). Common: OpportunityParty (all parties), OpportunityProspectPartyContactParty (prospect contacts), OpportunitySalesTeamParty (sales team), OpportunityItem (line items), OpportunityTextCollection (notes)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_opportunities',
                'Search opportunities using OData filter syntax. Returns: Object with opportunities array, count, pagination metadata. Example filters: "StatusCode eq \'2\'", "ProspectPartyID eq \'12345\'" (use ProspectPartyID, NOT AccountPartyID!), "substringof(\'Deal\', Name)". Use expand to include related collections.',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query. Supports: eq, ne, gt, lt, and, or. String functions: startswith(Field, \'value\'), substringof(\'value\', Field). CRITICAL: Use ProspectPartyID (NOT AccountPartyID) for account filters! substringof has REVERSED params. NEVER use contains(). Example: "substringof(\'Sales\', Name) and ProspectPartyID eq \'12345\'".'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0)'
                    ],
                    [
                        'name' => 'orderby',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $orderby clause (e.g., "Name asc", "ExpectedClosingDate desc")'
                    ],
                    [
                        'name' => 'expand',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated navigation properties to expand (e.g., "OpportunityParty,OpportunityItem"). Common: OpportunityParty, OpportunityProspectPartyContactParty, OpportunitySalesTeamParty, OpportunityItem, OpportunityTextCollection'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_opportunity',
                'Update an existing opportunity\'s fields. IMPORTANT: For tenant-specific fields (sales phase, status), first call c4c_get_entity_metadata("Opportunity") to discover correct field names, then pass them via additional_fields. Returns: Updated opportunity object.',
                [
                    [
                        'name' => 'opportunity_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Opportunity ObjectID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New opportunity name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New AccountID to link (short numeric like "10025480" from AccountID field, NOT 32-char ObjectID)'
                    ],
                    [
                        'name' => 'main_contact_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New ContactID (short numeric like "10025484" from ContactID field, NOT 32-char ObjectID)'
                    ],
                    [
                        'name' => 'expected_revenue_amount',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New expected revenue amount'
                    ],
                    [
                        'name' => 'probability_percent',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New probability percentage'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with SAP C4C field names from metadata. Use c4c_get_entity_metadata to discover correct field names. IMPORTANT: Date fields MUST use ISO 8601 format "YYYY-MM-DDTHH:mm:ss" (e.g., "2026-03-03T00:00:00"), NOT "YYYY-MM-DD". Examples: SalesCyclePhaseCode for sales phase, LifeCycleStatusCode for status.'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_opportunities',
                'Get a paginated list of all opportunities without filters. Returns: Object with opportunities array, count, pagination metadata. Use expand to include related collections.',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip (default: 0)'
                    ],
                    [
                        'name' => 'expand',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated navigation properties to expand (e.g., "OpportunityParty,OpportunityItem"). Common: OpportunityParty, OpportunityProspectPartyContactParty, OpportunitySalesTeamParty, OpportunityItem, OpportunityTextCollection'
                    ]
                ]
            ),

            // ================================================================
            // ACCOUNT TOOLS
            // ================================================================
            new ToolDefinition(
                'c4c_create_account',
                'Create a new corporate account in SAP C4C. Returns: Object with ObjectID (auto-generated), AccountID, Name, StatusCode, CountryCode, City, and all other account fields',
                [
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Company/account name (required)'
                    ],
                    [
                        'name' => 'name2',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Additional name line'
                    ],
                    [
                        'name' => 'role_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Account role code'
                    ],
                    [
                        'name' => 'country_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'ISO country code (e.g., "DE", "US", "GB")'
                    ],
                    [
                        'name' => 'city',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'City name'
                    ],
                    [
                        'name' => 'street',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Street address'
                    ],
                    [
                        'name' => 'postal_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Postal/ZIP code'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Phone number'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Email address'
                    ],
                    [
                        'name' => 'fax',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Fax number'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C account fields'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_account',
                'Retrieve detailed information about a specific account by its ObjectID. Returns: Object with ObjectID, AccountID, Name, StatusCode, CountryCode, City, Street, and all other account fields',
                [
                    [
                        'name' => 'account_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Account ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ],
                    [
                        'name' => 'expand_contacts',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Include linked contacts in response (default: false)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_accounts',
                'Search accounts using OData filter syntax. Returns: Object with accounts array, count, pagination metadata. Example filters: "Name eq \'ACME Corp\'", "CountryCode eq \'DE\'"',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query. Supports: eq, ne, gt, lt, and, or. String functions: startswith(Name, \'value\'), substringof(\'value\', Name) for partial matching. CRITICAL: substringof has REVERSED parameter order (value first, then field)! NEVER use contains() - it returns "Invalid function" error. Example: substringof(\'Star\', Name) finds all accounts containing "Star" in name.'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0)'
                    ],
                    [
                        'name' => 'orderby',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $orderby clause (e.g., "Name asc", "CreatedOn desc")'
                    ],
                    [
                        'name' => 'expand_contacts',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Include linked contacts in response (default: false)'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_account',
                'Update an existing account\'s fields. Returns: Updated account object.',
                [
                    [
                        'name' => 'account_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Account ObjectID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New account name'
                    ],
                    [
                        'name' => 'name2',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New additional name'
                    ],
                    [
                        'name' => 'country_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New country code'
                    ],
                    [
                        'name' => 'city',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New city'
                    ],
                    [
                        'name' => 'street',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New street address'
                    ],
                    [
                        'name' => 'postal_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New postal code'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New phone number'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New email address'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional fields to update'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_accounts',
                'Get a paginated list of all accounts without filters. Returns: Object with accounts array, count, pagination metadata',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip (default: 0)'
                    ]
                ]
            ),

            // ================================================================
            // CONTACT TOOLS
            // ================================================================
            new ToolDefinition(
                'c4c_create_contact',
                'Create a new contact in SAP C4C. Returns: Object with ObjectID (auto-generated), ContactID, FirstName, LastName, AccountPartyID, Email, Phone, and all other contact fields',
                [
                    [
                        'name' => 'last_name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact last name (required)'
                    ],
                    [
                        'name' => 'first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Contact first name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'SAP C4C account ObjectID to link this contact to'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Email address'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Phone number'
                    ],
                    [
                        'name' => 'mobile',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Mobile phone number'
                    ],
                    [
                        'name' => 'job_title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Job title/position'
                    ],
                    [
                        'name' => 'gender_code',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Gender code (e.g., "1" for male, "2" for female)'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional SAP C4C contact fields'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_get_contact',
                'Retrieve detailed information about a specific contact by its ObjectID. Returns: Object with ObjectID, ContactID, FirstName, LastName, AccountPartyID, Email, Phone, JobTitle, and all other contact fields',
                [
                    [
                        'name' => 'contact_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact ObjectID (e.g., "00163E7A8C271EE9A0B0C04C37FB5B5E")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_search_contacts',
                'Search contacts using OData filter syntax. Returns: Object with contacts array, count, pagination metadata. Example filters: "LastName eq \'Smith\'", "LastName eq \'Smith\' and FirstName eq \'John\'", "substringof(\'son\', LastName)"',
                [
                    [
                        'name' => 'filter',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $filter query. Supports: eq, ne, gt, lt, and, or. String functions: startswith(LastName, \'value\'), substringof(\'value\', LastName). CRITICAL: substringof has REVERSED params! NEVER use contains(). For full name search: "LastName eq \'Smith\' and FirstName eq \'John\'". For partial match: substringof(\'son\', LastName) finds names containing "son".'
                    ],
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip for pagination (default: 0)'
                    ],
                    [
                        'name' => 'orderby',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'OData $orderby clause (e.g., "LastName asc", "CreatedOn desc")'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_update_contact',
                'Update an existing contact\'s fields. Returns: Updated contact object.',
                [
                    [
                        'name' => 'contact_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact ObjectID to update'
                    ],
                    [
                        'name' => 'first_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New first name'
                    ],
                    [
                        'name' => 'last_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New last name'
                    ],
                    [
                        'name' => 'account_party_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New account ObjectID to link'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New email address'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New phone number'
                    ],
                    [
                        'name' => 'mobile',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New mobile number'
                    ],
                    [
                        'name' => 'job_title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New job title'
                    ],
                    [
                        'name' => 'additional_fields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'JSON object with additional fields to update'
                    ]
                ]
            ),
            new ToolDefinition(
                'c4c_list_contacts',
                'Get a paginated list of all contacts without filters. Returns: Object with contacts array, count, pagination metadata',
                [
                    [
                        'name' => 'top',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per page (default: 50, max: 1000)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results to skip (default: 0)'
                    ]
                ]
            ),

            // ================================================================
            // METADATA TOOLS
            // ================================================================
            new ToolDefinition(
                'c4c_get_entity_metadata',
                'IMPORTANT: Call this BEFORE using search/filter tools if you are unsure about property names. Fetches OData metadata for any SAP C4C entity to discover available properties, their types, and which ones can be used in $filter queries. Returns: filterable_properties (array of property names safe to use in filters), creatable_properties, updatable_properties, and all_properties with full details including type and nullability.',
                [
                    [
                        'name' => 'entity_type',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Entity type name: "Opportunity", "Lead", "CorporateAccount", "Contact". Use exact names without "Collection" suffix.'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('SAP C4C integration requires credentials');
        }

        // For OAuth2 mode, perform token exchange to get valid C4C access token
        $credentials = $this->getOAuth2EnrichedCredentials($credentials);

        return match ($toolName) {
            // Lead tools
            'c4c_create_lead' => $this->createLead($credentials, $parameters),
            'c4c_get_lead' => $this->sapC4cService->getLead(
                $credentials,
                $parameters['lead_id']
            ),
            'c4c_search_leads' => $this->sapC4cService->searchLeads(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null
            ),
            'c4c_update_lead' => $this->updateLead($credentials, $parameters),
            'c4c_list_leads' => $this->sapC4cService->listLeads(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),

            // Opportunity tools
            'c4c_create_opportunity' => $this->createOpportunity($credentials, $parameters),
            'c4c_get_opportunity' => $this->sapC4cService->getOpportunity(
                $credentials,
                $parameters['opportunity_id'],
                $parameters['expand'] ?? null
            ),
            'c4c_search_opportunities' => $this->sapC4cService->searchOpportunities(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null,
                $parameters['expand'] ?? null
            ),
            'c4c_update_opportunity' => $this->updateOpportunity($credentials, $parameters),
            'c4c_list_opportunities' => $this->sapC4cService->listOpportunities(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['expand'] ?? null
            ),

            // Account tools
            'c4c_create_account' => $this->createAccount($credentials, $parameters),
            'c4c_get_account' => $this->sapC4cService->getAccount(
                $credentials,
                $parameters['account_id'],
                $parameters['expand_contacts'] ?? false
            ),
            'c4c_search_accounts' => $this->sapC4cService->searchAccounts(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null,
                $parameters['expand_contacts'] ?? false
            ),
            'c4c_update_account' => $this->updateAccount($credentials, $parameters),
            'c4c_list_accounts' => $this->sapC4cService->listAccounts(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),

            // Contact tools
            'c4c_create_contact' => $this->createContact($credentials, $parameters),
            'c4c_get_contact' => $this->sapC4cService->getContact(
                $credentials,
                $parameters['contact_id']
            ),
            'c4c_search_contacts' => $this->sapC4cService->searchContacts(
                $credentials,
                $parameters['filter'] ?? null,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0,
                $parameters['orderby'] ?? null
            ),
            'c4c_update_contact' => $this->updateContact($credentials, $parameters),
            'c4c_list_contacts' => $this->sapC4cService->listContacts(
                $credentials,
                $parameters['top'] ?? 50,
                $parameters['skip'] ?? 0
            ),

            // Metadata tools
            'c4c_get_entity_metadata' => $this->sapC4cService->getEntityMetadata(
                $credentials,
                $parameters['entity_type']
            ),

            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    /**
     * Helper method to build lead data for creation
     */
    private function createLead(array $credentials, array $parameters): array
    {
        $leadData = [
            'Name' => $parameters['name'],
            'ContactLastName' => $parameters['contact_last_name'],
        ];

        // Add optional standard fields
        if (isset($parameters['contact_first_name'])) {
            $leadData['ContactFirstName'] = $parameters['contact_first_name'];
        }

        if (isset($parameters['company'])) {
            $leadData['Company'] = $parameters['company'];
        }

        if (isset($parameters['account_party_id'])) {
            $leadData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['qualification_level_code'])) {
            $leadData['QualificationLevelCode'] = $parameters['qualification_level_code'];
        }

        if (isset($parameters['origin_type_code'])) {
            $leadData['OriginTypeCode'] = $parameters['origin_type_code'];
        }

        if (isset($parameters['group_code'])) {
            $leadData['GroupCode'] = $parameters['group_code'];
        }

        // Merge additional fields
        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $leadData = array_merge($leadData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createLead($credentials, $leadData);
    }

    /**
     * Helper method to build lead data for update
     */
    private function updateLead(array $credentials, array $parameters): array
    {
        $leadData = [];

        // Add optional fields if provided
        if (isset($parameters['name'])) {
            $leadData['Name'] = $parameters['name'];
        }

        if (isset($parameters['status_code'])) {
            $leadData['StatusCode'] = $parameters['status_code'];
        }

        if (isset($parameters['qualification_level_code'])) {
            $leadData['QualificationLevelCode'] = $parameters['qualification_level_code'];
        }

        if (isset($parameters['contact_first_name'])) {
            $leadData['ContactFirstName'] = $parameters['contact_first_name'];
        }

        if (isset($parameters['contact_last_name'])) {
            $leadData['ContactLastName'] = $parameters['contact_last_name'];
        }

        if (isset($parameters['company'])) {
            $leadData['Company'] = $parameters['company'];
        }

        // Merge additional fields
        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $leadData = array_merge($leadData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateLead(
            $credentials,
            $parameters['lead_id'],
            $leadData
        );
    }

    // ========================================================================
    // OPPORTUNITY HELPER METHODS
    // ========================================================================

    /**
     * Helper method to build opportunity data for creation
     */
    private function createOpportunity(array $credentials, array $parameters): array
    {
        $opportunityData = [
            'Name' => $parameters['name'],
        ];

        if (isset($parameters['account_party_id'])) {
            $opportunityData['ProspectPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['main_contact_party_id'])) {
            $opportunityData['PrimaryContactPartyID'] = $parameters['main_contact_party_id'];
        }

        if (isset($parameters['expected_revenue_amount'])) {
            $opportunityData['ExpectedRevenueAmount'] = $parameters['expected_revenue_amount'];
        }

        if (isset($parameters['currency_code'])) {
            $opportunityData['ExpectedRevenueAmountCurrencyCode'] = $parameters['currency_code'];
        }

        if (isset($parameters['probability_percent'])) {
            $opportunityData['ProbabilityPercent'] = $parameters['probability_percent'];
        }

        // Tenant-specific fields (sales phase, closing date, status) must be passed via additional_fields
        // with correct SAP field names discovered from c4c_get_entity_metadata
        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $opportunityData = array_merge($opportunityData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createOpportunity($credentials, $opportunityData);
    }

    /**
     * Helper method to build opportunity data for update
     */
    private function updateOpportunity(array $credentials, array $parameters): array
    {
        $opportunityData = [];

        if (isset($parameters['name'])) {
            $opportunityData['Name'] = $parameters['name'];
        }

        if (isset($parameters['account_party_id'])) {
            $opportunityData['ProspectPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['main_contact_party_id'])) {
            $opportunityData['PrimaryContactPartyID'] = $parameters['main_contact_party_id'];
        }

        if (isset($parameters['expected_revenue_amount'])) {
            $opportunityData['ExpectedRevenueAmount'] = $parameters['expected_revenue_amount'];
        }

        if (isset($parameters['probability_percent'])) {
            $opportunityData['ProbabilityPercent'] = $parameters['probability_percent'];
        }

        // Tenant-specific fields (sales phase, status) must be passed via additional_fields
        // with correct SAP field names discovered from c4c_get_entity_metadata
        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $opportunityData = array_merge($opportunityData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateOpportunity(
            $credentials,
            $parameters['opportunity_id'],
            $opportunityData
        );
    }

    // ========================================================================
    // ACCOUNT HELPER METHODS
    // ========================================================================

    /**
     * Helper method to build account data for creation
     */
    private function createAccount(array $credentials, array $parameters): array
    {
        $accountData = [
            'Name' => $parameters['name'],
        ];

        if (isset($parameters['name2'])) {
            $accountData['Name2'] = $parameters['name2'];
        }

        if (isset($parameters['role_code'])) {
            $accountData['RoleCode'] = $parameters['role_code'];
        }

        if (isset($parameters['country_code'])) {
            $accountData['CountryCode'] = $parameters['country_code'];
        }

        if (isset($parameters['city'])) {
            $accountData['City'] = $parameters['city'];
        }

        if (isset($parameters['street'])) {
            $accountData['Street'] = $parameters['street'];
        }

        if (isset($parameters['postal_code'])) {
            $accountData['PostalCode'] = $parameters['postal_code'];
        }

        if (isset($parameters['phone'])) {
            $accountData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['email'])) {
            $accountData['Email'] = $parameters['email'];
        }

        if (isset($parameters['fax'])) {
            $accountData['Fax'] = $parameters['fax'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $accountData = array_merge($accountData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createAccount($credentials, $accountData);
    }

    /**
     * Helper method to build account data for update
     */
    private function updateAccount(array $credentials, array $parameters): array
    {
        $accountData = [];

        if (isset($parameters['name'])) {
            $accountData['Name'] = $parameters['name'];
        }

        if (isset($parameters['name2'])) {
            $accountData['Name2'] = $parameters['name2'];
        }

        if (isset($parameters['country_code'])) {
            $accountData['CountryCode'] = $parameters['country_code'];
        }

        if (isset($parameters['city'])) {
            $accountData['City'] = $parameters['city'];
        }

        if (isset($parameters['street'])) {
            $accountData['Street'] = $parameters['street'];
        }

        if (isset($parameters['postal_code'])) {
            $accountData['PostalCode'] = $parameters['postal_code'];
        }

        if (isset($parameters['phone'])) {
            $accountData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['email'])) {
            $accountData['Email'] = $parameters['email'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $accountData = array_merge($accountData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateAccount(
            $credentials,
            $parameters['account_id'],
            $accountData
        );
    }

    // ========================================================================
    // CONTACT HELPER METHODS
    // ========================================================================

    /**
     * Helper method to build contact data for creation
     */
    private function createContact(array $credentials, array $parameters): array
    {
        $contactData = [
            'LastName' => $parameters['last_name'],
        ];

        if (isset($parameters['first_name'])) {
            $contactData['FirstName'] = $parameters['first_name'];
        }

        if (isset($parameters['account_party_id'])) {
            $contactData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['email'])) {
            $contactData['Email'] = $parameters['email'];
        }

        if (isset($parameters['phone'])) {
            $contactData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['mobile'])) {
            $contactData['Mobile'] = $parameters['mobile'];
        }

        if (isset($parameters['job_title'])) {
            $contactData['JobTitle'] = $parameters['job_title'];
        }

        if (isset($parameters['gender_code'])) {
            $contactData['GenderCode'] = $parameters['gender_code'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $contactData = array_merge($contactData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->createContact($credentials, $contactData);
    }

    /**
     * Helper method to build contact data for update
     */
    private function updateContact(array $credentials, array $parameters): array
    {
        $contactData = [];

        if (isset($parameters['first_name'])) {
            $contactData['FirstName'] = $parameters['first_name'];
        }

        if (isset($parameters['last_name'])) {
            $contactData['LastName'] = $parameters['last_name'];
        }

        if (isset($parameters['account_party_id'])) {
            $contactData['AccountPartyID'] = $parameters['account_party_id'];
        }

        if (isset($parameters['email'])) {
            $contactData['Email'] = $parameters['email'];
        }

        if (isset($parameters['phone'])) {
            $contactData['Phone'] = $parameters['phone'];
        }

        if (isset($parameters['mobile'])) {
            $contactData['Mobile'] = $parameters['mobile'];
        }

        if (isset($parameters['job_title'])) {
            $contactData['JobTitle'] = $parameters['job_title'];
        }

        if (isset($parameters['additional_fields']) && is_array($parameters['additional_fields'])) {
            $contactData = array_merge($contactData, $parameters['additional_fields']);
        }

        return $this->sapC4cService->updateContact(
            $credentials,
            $parameters['contact_id'],
            $contactData
        );
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        // Check if base_url is present
        if (empty($credentials['base_url'])) {
            return false;
        }

        $authMode = $credentials['auth_mode'] ?? 'basic';

        if ($authMode === 'basic') {
            // Basic Auth: require username and password
            if (empty($credentials['username']) || empty($credentials['password'])) {
                return false;
            }
        } elseif ($authMode === 'user_delegation') {
            // User Delegation: require SAP C4C OAuth credentials
            if (
                empty($credentials['c4c_oauth_client_id']) ||
                empty($credentials['c4c_oauth_client_secret'])
            ) {
                return false;
            }
            // If Azure tokens exist, consider it connected and valid
            if (!empty($credentials['azure_refresh_token'])) {
                return true;
            }
            // No tokens yet - still valid config, just needs OAuth flow
            return true;
        }

        // Use SapC4cService to validate credentials
        try {
            return $this->sapC4cService->testConnection($credentials);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            // Base URL (always required)
            new CredentialField(
                'base_url',
                'url',
                'SAP C4C Base URL',
                'https://myXXXXXX.crm.ondemand.com',
                true,
                'Your SAP Cloud for Customer instance URL without trailing slash'
            ),

            // Authentication mode selector (default: user_delegation for better security)
            new CredentialField(
                'auth_mode',
                'select',
                'Authentication Mode',
                'user_delegation',
                true,
                'Choose how to authenticate with SAP C4C',
                [
                    'basic' => 'Basic Auth (Technical User)',
                    'user_delegation' => 'User Delegation (via Azure AD)',
                ]
            ),

            // Basic Auth fields (shown when auth_mode=basic)
            new CredentialField(
                'username',
                'text',
                'Username',
                'user@company.com',
                false, // Not globally required, only for Basic Auth
                'Technical User ID or SAP C4C username',
                null,
                'auth_mode',
                'basic'
            ),
            new CredentialField(
                'password',
                'password',
                'Password',
                null,
                false,
                'Technical User password. Credentials will be encrypted and stored securely.',
                null,
                'auth_mode',
                'basic'
            ),

            // User Delegation fields (shown when auth_mode=user_delegation)
            new CredentialField(
                'c4c_oauth_client_id',
                'text',
                'SAP C4C OAuth Client ID',
                null,
                false,
                'OAuth Client ID from SAP C4C Administrator > OAuth 2.0 Client Registration',
                null,
                'auth_mode',
                'user_delegation'
            ),
            new CredentialField(
                'c4c_oauth_client_secret',
                'password',
                'SAP C4C OAuth Client Secret',
                null,
                false,
                'OAuth Client Secret from SAP C4C Admin. Encrypted and stored securely.',
                null,
                'auth_mode',
                'user_delegation'
            ),
            // OAuth button field (shown when auth_mode=user_delegation, after config saved)
            new CredentialField(
                'oauth_sap_c4c',
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

    public function getSetupInstructions(): ?string
    {
        return <<<'HTML'
<div class="setup-instructions" data-auth-instructions="true">
    <h4>Setup Instructions</h4>
    <div class="auth-option" data-auth-mode="basic">
        <strong>Basic Auth Setup (Test Systems)</strong>
        <p>Simple setup - all actions attributed to the technical user account.</p>
        <ol>
            <li>Create a Communication Arrangement in SAP C4C</li>
            <li>Use the generated technical user credentials below</li>
        </ol>
        <p class="note"><strong>Best for:</strong> Test/sandbox environments where user attribution is not required.</p>
    </div>
    <div class="auth-option" data-auth-mode="user_delegation">
        <strong>User Delegation Setup (via Azure AD)</strong>
        <p>Actions attributed to individual users via Azure AD SSO.</p>
        <ol>
            <li><strong>SAP C4C Admin:</strong> Register OAuth 2.0 Client (Administrator > OAuth 2.0 Client Registration)</li>
            <li><strong>Enter:</strong> OAuth Client ID and Secret below</li>
            <li><strong>Connect:</strong> Click "Connect with Azure AD" to authorize</li>
        </ol>
        <p class="note"><strong>Note:</strong> Users must exist in both Azure AD and SAP C4C with matching email addresses</p>
    </div>
    <p class="docs-link"><a href="https://help.sap.com/docs/sap-cloud-for-customer/odata-services/sap-cloud-for-customer-odata-api" target="_blank" rel="noopener">SAP C4C OData API Documentation</a></p>
</div>
HTML;
    }

    public function getLogoPath(): string
    {
        return '/images/logos/SAP-Logo.svg';
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/sap_c4c.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }
    public function isExperimental(): bool
    {
        return true;
    }
}
