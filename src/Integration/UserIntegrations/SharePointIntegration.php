<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\SharePointService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class SharePointIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private SharePointService $sharePointService,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'sharepoint';
    }

    public function getName(): string
    {
        return 'SharePoint';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'sharepoint_search',
                'Search ALL SharePoint content (Files, Sites, Pages, Lists, Drives) using KQL (Keyword Query Language). Returns results grouped by type.',
                [
                    [
                        'name' => 'kql',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'KQL query for SharePoint search. Syntax examples:
• OR search: vacation OR Urlaub OR leave OR Ferien
• Exact phrase: "project status report"
• Wildcard: budget* (matches budget, budgets, budgeting)
• Field filters: author:John, filename:report, filetype:pdf, filetype:docx, title:Q4
• Site filter: path:sitename (restricts results to a specific SharePoint site, e.g. path:valanticgroup)
• Combined site filter: path:valanticgroup AND news* (search within a specific site)
• Combined: (vacation OR Urlaub) AND filetype:docx
• Date filter: LastModifiedTime>2024-01-01
Tips: Use OR to include synonyms and translations (German+English) for bilingual workspaces.'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum results per type (default: 25, max: 50). Results are grouped by Files, Sites, Pages, Lists, Drives.'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_read_document',
                'Extract and read text content from SharePoint documents (Word, Excel, PowerPoint, PDF, text files). Returns: Object containing extracted text content, metadata including fileName, fileSize, mimeType, and documentInfo with title, author, pageCount, wordCount (when available).',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID where the document is stored (from search result\'s siteId field, or site name if ID not available)'
                    ],
                    [
                        'name' => 'itemId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Document item ID from search results (use the id field from search results)'
                    ],
                    [
                        'name' => 'driveId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Drive ID from search results (driveId field). When provided, enables direct access to the file. Improves reliability for files in document libraries.'
                    ],
                    [
                        'name' => 'maxLength',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum characters to extract (default: 5000, max: 50000)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_read_page',
                'Read content from a SharePoint page. Returns: Object containing page data with properties: id, name, title, webUrl, createdDateTime, lastModifiedDateTime, lastModifiedBy, webParts array (containing page sections and content blocks).',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID or site name'
                    ],
                    [
                        'name' => 'pageId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Page ID or page title'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_list_files',
                'List files in a SharePoint directory. Returns: Array of driveItem objects with properties: id, name, size, webUrl, createdDateTime, lastModifiedDateTime, createdBy, lastModifiedBy, file (with mimeType and hashes), folder (if item is a folder), parentReference.',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID'
                    ],
                    [
                        'name' => 'path',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Directory path (optional)'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_download_file',
                'Download a file from SharePoint. Returns: Binary file content stream or redirect URL to pre-authenticated download location. Response includes Content-Type header with file MIME type and Content-Disposition header with filename.',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID'
                    ],
                    [
                        'name' => 'itemId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Item ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'sharepoint_get_list_items',
                'Get items from a SharePoint list. Returns: Array of listItem objects with properties: id, createdDateTime, lastModifiedDateTime, createdBy, lastModifiedBy, fields (containing custom column values like Title, Employee, ID, etc.), contentType, eTag, webUrl.',
                [
                    [
                        'name' => 'siteId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Site ID'
                    ],
                    [
                        'name' => 'listId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'List ID'
                    ],
                    [
                        'name' => 'filters',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Optional filters'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('SharePoint integration requires credentials');
        }

        // Handle token refresh if needed
        if (isset($credentials['expires_at']) && time() >= $credentials['expires_at']) {
            $credentials = $this->refreshTokenIfNeeded($credentials);
        }

        return match ($toolName) {
            'sharepoint_search' => $this->sharePointService->search(
                $credentials,
                $parameters['kql'],
                min($parameters['limit'] ?? 25, 50)
            ),
            'sharepoint_read_document' => $this->sharePointService->readDocument(
                $credentials,
                $parameters['siteId'],
                $parameters['itemId'],
                min($parameters['maxLength'] ?? 5000, 50000),
                $parameters['driveId'] ?? null
            ),
            'sharepoint_read_page' => $this->sharePointService->readPage(
                $credentials,
                $parameters['siteId'],
                $parameters['pageId']
            ),
            'sharepoint_list_files' => $this->sharePointService->listFiles(
                $credentials,
                $parameters['siteId'],
                $parameters['path'] ?? ''
            ),
            'sharepoint_download_file' => $this->sharePointService->downloadFile(
                $credentials,
                $parameters['siteId'],
                $parameters['itemId']
            ),
            'sharepoint_get_list_items' => $this->sharePointService->getListItems(
                $credentials,
                $parameters['siteId'],
                $parameters['listId'],
                $parameters['filters'] ?? []
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
        return isset($credentials['access_token'])
            && isset($credentials['tenant_id']);
    }

    public function getCredentialFields(): array
    {
        // SharePoint uses OAuth2, so we return a special oauth type field
        return [
            new CredentialField(
                'oauth',
                'oauth',
                'Connect with Microsoft',
                null,
                true,
                'Authenticate with your Microsoft account to access SharePoint'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/sharepoint_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }

    private function refreshTokenIfNeeded(array $credentials): array
    {
        if (!isset($credentials['refresh_token'])) {
            throw new \Exception('SharePoint token expired and no refresh token available');
        }

        $newTokens = $this->sharePointService->refreshToken(
            $credentials['refresh_token'],
            $credentials['client_id'],
            $credentials['client_secret'],
            $credentials['tenant_id']
        );

        return array_merge($credentials, [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'],
            'expires_at' => $newTokens['expires_at']
        ]);
    }
    public function isExperimental(): bool
    {
        return false;
    }

    public function getSetupInstructions(): ?string
    {
        return null;
    }
}
