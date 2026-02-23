<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\CredentialField;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolCategory;
use App\Integration\ToolDefinition;
use App\Service\Integration\HubSpotService;
use Twig\Environment;

class HubSpotIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private HubSpotService $hubSpotService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'hubspot';
    }

    public function getName(): string
    {
        return 'HubSpot CRM';
    }

    public function getTools(): array
    {
        return [
            // ========================================
            // CONTACTS TOOLS (4)
            // ========================================
            new ToolDefinition(
                'hubspot_search_contacts',
                'Search for contacts in HubSpot CRM. Returns matching contacts with properties like name, email, phone, company, job title, and lifecycle stage. Use this to find contacts by name, email, or any searchable property.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query (searches across name, email, phone, company)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 20, max: 100)'
                    ]
                ]
            ),
            new ToolDefinition(
                'hubspot_get_contact',
                'Get detailed information about a specific HubSpot contact by ID. Returns all contact properties including name, email, phone, company, job title, lifecycle stage, lead status, and timestamps.',
                [
                    [
                        'name' => 'contactId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'HubSpot contact ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'hubspot_create_contact',
                'Create a new contact in HubSpot CRM. At minimum, provide an email address. Returns the created contact with its ID.',
                [
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Contact email address (required)'
                    ],
                    [
                        'name' => 'firstname',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Contact first name'
                    ],
                    [
                        'name' => 'lastname',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Contact last name'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Contact phone number'
                    ],
                    [
                        'name' => 'company',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Company name'
                    ],
                    [
                        'name' => 'jobtitle',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Job title'
                    ],
                    [
                        'name' => 'lifecyclestage',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Lifecycle stage (subscriber, lead, marketingqualifiedlead, salesqualifiedlead, opportunity, customer, evangelist, other)'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'hubspot_update_contact',
                'Update an existing HubSpot contact. Only provide the properties you want to update.',
                [
                    [
                        'name' => 'contactId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'HubSpot contact ID to update'
                    ],
                    [
                        'name' => 'email',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New email address'
                    ],
                    [
                        'name' => 'firstname',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New first name'
                    ],
                    [
                        'name' => 'lastname',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New last name'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New phone number'
                    ],
                    [
                        'name' => 'company',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New company name'
                    ],
                    [
                        'name' => 'jobtitle',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New job title'
                    ],
                    [
                        'name' => 'lifecyclestage',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New lifecycle stage'
                    ],
                    [
                        'name' => 'hs_lead_status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New lead status (new, open, in_progress, open_deal, unqualified, attempted_to_contact, connected, bad_timing)'
                    ]
                ],
                ToolCategory::WRITE
            ),

            // ========================================
            // COMPANIES TOOLS (4)
            // ========================================
            new ToolDefinition(
                'hubspot_search_companies',
                'Search for companies in HubSpot CRM. Returns matching companies with properties like name, domain, industry, phone, location, and employee count.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query (searches across name, domain, phone)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 20, max: 100)'
                    ]
                ]
            ),
            new ToolDefinition(
                'hubspot_get_company',
                'Get detailed information about a specific HubSpot company by ID. Returns all company properties including name, domain, industry, phone, location, employee count, revenue, and description.',
                [
                    [
                        'name' => 'companyId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'HubSpot company ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'hubspot_create_company',
                'Create a new company in HubSpot CRM. At minimum, provide a company name. Returns the created company with its ID.',
                [
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Company name (required)'
                    ],
                    [
                        'name' => 'domain',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Company website domain (e.g., example.com)'
                    ],
                    [
                        'name' => 'industry',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Industry type'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Company phone number'
                    ],
                    [
                        'name' => 'city',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'City'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'State/Region'
                    ],
                    [
                        'name' => 'country',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Country'
                    ],
                    [
                        'name' => 'numberofemployees',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Number of employees'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Company description'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'hubspot_update_company',
                'Update an existing HubSpot company. Only provide the properties you want to update.',
                [
                    [
                        'name' => 'companyId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'HubSpot company ID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New company name'
                    ],
                    [
                        'name' => 'domain',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New company domain'
                    ],
                    [
                        'name' => 'industry',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New industry'
                    ],
                    [
                        'name' => 'phone',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New phone number'
                    ],
                    [
                        'name' => 'city',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New city'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New state/region'
                    ],
                    [
                        'name' => 'country',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New country'
                    ],
                    [
                        'name' => 'numberofemployees',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New number of employees'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New description'
                    ]
                ],
                ToolCategory::WRITE
            ),

            // ========================================
            // DEALS TOOLS (4)
            // ========================================
            new ToolDefinition(
                'hubspot_search_deals',
                'Search for deals in HubSpot CRM. Returns matching deals with properties like deal name, amount, stage, pipeline, close date, and priority.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query (searches across deal name)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 20, max: 100)'
                    ]
                ]
            ),
            new ToolDefinition(
                'hubspot_get_deal',
                'Get detailed information about a specific HubSpot deal by ID. Returns all deal properties including name, amount, stage, pipeline, close date, priority, owner, and timestamps.',
                [
                    [
                        'name' => 'dealId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'HubSpot deal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'hubspot_create_deal',
                'Create a new deal in HubSpot CRM. At minimum, provide a deal name. Returns the created deal with its ID.',
                [
                    [
                        'name' => 'dealname',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Deal name (required)'
                    ],
                    [
                        'name' => 'amount',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Deal amount/value'
                    ],
                    [
                        'name' => 'dealstage',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Deal stage ID (use hubspot_get_deal_pipelines to see available stages)'
                    ],
                    [
                        'name' => 'pipeline',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Pipeline ID (default pipeline used if not specified)'
                    ],
                    [
                        'name' => 'closedate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Expected close date (timestamp in milliseconds or ISO date)'
                    ],
                    [
                        'name' => 'hs_priority',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Priority (low, medium, high)'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Deal description'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'hubspot_update_deal',
                'Update an existing HubSpot deal. Only provide the properties you want to update. Use this to move deals through pipeline stages.',
                [
                    [
                        'name' => 'dealId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'HubSpot deal ID to update'
                    ],
                    [
                        'name' => 'dealname',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New deal name'
                    ],
                    [
                        'name' => 'amount',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New deal amount'
                    ],
                    [
                        'name' => 'dealstage',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New deal stage ID (to move deal through pipeline)'
                    ],
                    [
                        'name' => 'pipeline',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New pipeline ID'
                    ],
                    [
                        'name' => 'closedate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New close date'
                    ],
                    [
                        'name' => 'hs_priority',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New priority'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New description'
                    ]
                ],
                ToolCategory::WRITE
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('HubSpot integration requires credentials');
        }

        return match ($toolName) {
            // Contacts
            'hubspot_search_contacts' => $this->hubSpotService->searchContacts(
                $credentials,
                $parameters['query'],
                (int)($parameters['limit'] ?? 20)
            ),
            'hubspot_get_contact' => $this->hubSpotService->getContact(
                $credentials,
                $parameters['contactId']
            ),
            'hubspot_create_contact' => $this->hubSpotService->createContact(
                $credentials,
                $this->buildContactProperties($parameters)
            ),
            'hubspot_update_contact' => $this->hubSpotService->updateContact(
                $credentials,
                $parameters['contactId'],
                $this->buildContactProperties($parameters, true)
            ),

            // Companies
            'hubspot_search_companies' => $this->hubSpotService->searchCompanies(
                $credentials,
                $parameters['query'],
                (int)($parameters['limit'] ?? 20)
            ),
            'hubspot_get_company' => $this->hubSpotService->getCompany(
                $credentials,
                $parameters['companyId']
            ),
            'hubspot_create_company' => $this->hubSpotService->createCompany(
                $credentials,
                $this->buildCompanyProperties($parameters)
            ),
            'hubspot_update_company' => $this->hubSpotService->updateCompany(
                $credentials,
                $parameters['companyId'],
                $this->buildCompanyProperties($parameters, true)
            ),

            // Deals
            'hubspot_search_deals' => $this->hubSpotService->searchDeals(
                $credentials,
                $parameters['query'],
                (int)($parameters['limit'] ?? 20)
            ),
            'hubspot_get_deal' => $this->hubSpotService->getDeal(
                $credentials,
                $parameters['dealId']
            ),
            'hubspot_create_deal' => $this->hubSpotService->createDeal(
                $credentials,
                $this->buildDealProperties($parameters)
            ),
            'hubspot_update_deal' => $this->hubSpotService->updateDeal(
                $credentials,
                $parameters['dealId'],
                $this->buildDealProperties($parameters, true)
            ),

            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    /**
     * Build contact properties array from parameters
     *
     * @param array $parameters Input parameters
     * @param bool $isUpdate Whether this is an update (reserved for future extensibility)
     * @return array Properties array for HubSpot API
     */
    private function buildContactProperties(array $parameters, bool $isUpdate = false): array
    {
        $properties = [];
        $contactFields = [
            'email', 'firstname', 'lastname', 'phone', 'company',
            'jobtitle', 'lifecyclestage', 'hs_lead_status'
        ];

        foreach ($contactFields as $field) {
            if (isset($parameters[$field]) && $parameters[$field] !== '') {
                $properties[$field] = $parameters[$field];
            }
        }

        return $properties;
    }

    /**
     * Build company properties array from parameters
     *
     * @param array $parameters Input parameters
     * @param bool $isUpdate Whether this is an update (reserved for future extensibility)
     * @return array Properties array for HubSpot API
     */
    private function buildCompanyProperties(array $parameters, bool $isUpdate = false): array
    {
        $properties = [];
        $companyFields = [
            'name', 'domain', 'industry', 'phone', 'city',
            'state', 'country', 'numberofemployees', 'description'
        ];

        foreach ($companyFields as $field) {
            if (isset($parameters[$field]) && $parameters[$field] !== '') {
                $properties[$field] = $parameters[$field];
            }
        }

        return $properties;
    }

    /**
     * Build deal properties array from parameters
     *
     * @param array $parameters Input parameters
     * @param bool $isUpdate Whether this is an update (reserved for future extensibility)
     * @return array Properties array for HubSpot API
     */
    private function buildDealProperties(array $parameters, bool $isUpdate = false): array
    {
        $properties = [];
        $dealFields = [
            'dealname', 'amount', 'dealstage', 'pipeline',
            'closedate', 'hs_priority', 'description'
        ];

        foreach ($dealFields as $field) {
            if (isset($parameters[$field]) && $parameters[$field] !== '') {
                $properties[$field] = $parameters[$field];
            }
        }

        return $properties;
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        // Check if OAuth tokens are present
        if (empty($credentials['access_token'])) {
            return false;
        }

        // Test connection with HubSpot API
        try {
            $result = $this->hubSpotService->testConnectionDetailed($credentials);
            return $result['success'];
        } catch (\Exception) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        // HubSpot uses OAuth2, so we return an oauth field type
        return [
            new CredentialField(
                'oauth',
                'oauth',
                'HubSpot OAuth',
                null,
                true,
                'Connect to HubSpot using OAuth2. Click the button to authorize access to your HubSpot account.'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/hubspot_full.xml.twig', [
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
        return null;
    }

    public function getLogoPath(): string
    {
        return '/images/logos/hubspot-icon.svg';
    }
}
