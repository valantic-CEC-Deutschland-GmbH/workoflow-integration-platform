<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\CredentialField;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Service\Integration\CandisService;
use Twig\Environment;

class CandisIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private CandisService $candisService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'candis';
    }

    public function getName(): string
    {
        return 'Candis Invoice Management';
    }

    public function getTools(): array
    {
        return [
            // ========================================
            // INVOICE TOOLS (Tier 1)
            // ========================================
            new ToolDefinition(
                'candis_list_invoices',
                'Search and list invoices in Candis. Filter by status (APPROVED, EXPORTED), date range, with pagination. Returns supplier name, amount, currency, invoice date, due date, and payment status for each invoice.',
                [
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by invoice status: APPROVED, EXPORTED, or other Candis status values'
                    ],
                    [
                        'name' => 'dateFrom',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter invoices from this date (YYYY-MM-DD format)'
                    ],
                    [
                        'name' => 'dateTo',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter invoices up to this date (YYYY-MM-DD format)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 20, max: 100)'
                    ],
                    [
                        'name' => 'offset',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Pagination offset (default: 0)'
                    ]
                ]
            ),
            new ToolDefinition(
                'candis_get_invoice',
                'Get full details of a specific invoice by ID. Returns complete invoice data including bookings, cost centers, GL accounts, supplier info, amounts, payment status, and approval history.',
                [
                    [
                        'name' => 'invoiceId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Candis invoice ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'candis_update_payment_status',
                'Mark an invoice as paid or unpaid. Requires the invoice ID and optionally a payment date. Use candis_get_invoice first to verify the invoice before updating.',
                [
                    [
                        'name' => 'invoiceId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Candis invoice ID to update'
                    ],
                    [
                        'name' => 'isPaid',
                        'type' => 'boolean',
                        'required' => true,
                        'description' => 'Set to true to mark as paid, false to mark as unpaid'
                    ],
                    [
                        'name' => 'paymentDate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Payment date in YYYY-MM-DD format (defaults to today if marking as paid)'
                    ]
                ]
            ),

            // ========================================
            // REIMBURSEMENT TOOLS (Tier 1)
            // ========================================
            new ToolDefinition(
                'candis_list_reimbursements',
                'List employee reimbursement items. Filter by status, expense type (GENERAL_EXPENSE, HOSPITALITY_EXPENSE, PER_DIEM, MILEAGE), and date range. Returns expense details including amounts, dates, and employee info.',
                [
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by reimbursement status'
                    ],
                    [
                        'name' => 'type',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by type: GENERAL_EXPENSE, HOSPITALITY_EXPENSE, PER_DIEM, or MILEAGE'
                    ],
                    [
                        'name' => 'dateFrom',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter from this date (YYYY-MM-DD)'
                    ],
                    [
                        'name' => 'dateTo',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter up to this date (YYYY-MM-DD)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 20, max: 100)'
                    ],
                    [
                        'name' => 'offset',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Pagination offset (default: 0)'
                    ]
                ]
            ),

            // ========================================
            // CORE DATA IMPORT TOOLS (Tier 2)
            // ========================================
            new ToolDefinition(
                'candis_import_cost_centers',
                'Create or update cost centers in Candis. Provide an array of cost center objects with code and name. This is an async operation.',
                [
                    [
                        'name' => 'costCenters',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'Array of cost center objects, each with "code" (string) and "name" (string)'
                    ]
                ]
            ),
            new ToolDefinition(
                'candis_import_contacts',
                'Create or update supplier contacts in Candis. Provide contact details including name, VAT ID, IBAN, and payment conditions. This is an async operation.',
                [
                    [
                        'name' => 'contacts',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'Array of contact objects with fields like "name" (required), "vatId", "iban", "paymentConditions"'
                    ]
                ]
            ),
            new ToolDefinition(
                'candis_import_gl_accounts',
                'Create or update general ledger (GL) accounts in Candis. Provide account number and name. This is an async operation.',
                [
                    [
                        'name' => 'glAccounts',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'Array of GL account objects, each with "number" (string) and "name" (string)'
                    ]
                ]
            ),

            // ========================================
            // EXPORT TOOLS (Tier 2)
            // ========================================
            new ToolDefinition(
                'candis_create_export',
                'Initiate an export of approved invoices/transactions for ERP integration. Returns an export ID that can be used to track the export status.',
                [
                    [
                        'name' => 'type',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Export type (optional, depends on Candis configuration)'
                    ]
                ]
            ),
            new ToolDefinition(
                'candis_get_export_status',
                'Check the status of an export operation. Status progresses from EXPORTING to EXPORTED. When complete, returns the exported postings data.',
                [
                    [
                        'name' => 'exportId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Export ID returned from candis_create_export'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Candis integration requires credentials');
        }

        return match ($toolName) {
            // Invoice Management
            'candis_list_invoices' => $this->candisService->listInvoices(
                $credentials,
                $parameters['status'] ?? null,
                $parameters['dateFrom'] ?? null,
                $parameters['dateTo'] ?? null,
                (int) ($parameters['limit'] ?? 20),
                (int) ($parameters['offset'] ?? 0)
            ),
            'candis_get_invoice' => $this->candisService->getInvoice(
                $credentials,
                $parameters['invoiceId']
            ),
            'candis_update_payment_status' => $this->candisService->updatePaymentStatus(
                $credentials,
                $parameters['invoiceId'],
                (bool) $parameters['isPaid'],
                $parameters['paymentDate'] ?? null
            ),

            // Reimbursements
            'candis_list_reimbursements' => $this->candisService->listReimbursements(
                $credentials,
                $parameters['status'] ?? null,
                $parameters['type'] ?? null,
                $parameters['dateFrom'] ?? null,
                $parameters['dateTo'] ?? null,
                (int) ($parameters['limit'] ?? 20),
                (int) ($parameters['offset'] ?? 0)
            ),

            // Core Data Imports
            'candis_import_cost_centers' => $this->candisService->importCostCenters(
                $credentials,
                $parameters['costCenters']
            ),
            'candis_import_contacts' => $this->candisService->importContacts(
                $credentials,
                $parameters['contacts']
            ),
            'candis_import_gl_accounts' => $this->candisService->importGlAccounts(
                $credentials,
                $parameters['glAccounts']
            ),

            // Exports
            'candis_create_export' => $this->candisService->createExport(
                $credentials,
                $parameters['type'] ?? null
            ),
            'candis_get_export_status' => $this->candisService->getExportStatus(
                $credentials,
                $parameters['exportId']
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
        if (empty($credentials['access_token'])) {
            return false;
        }

        try {
            $result = $this->candisService->testConnectionDetailed($credentials);
            return $result['success'];
        } catch (\Exception) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'oauth',
                'oauth',
                'Candis OAuth',
                null,
                true,
                'Connect to Candis using OAuth2. Click the button to authorize access to your Candis organization. You will select your company during the Candis consent screen.'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/candis_full.xml.twig', [
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
        return '/images/logos/candis-icon.svg';
    }
}
