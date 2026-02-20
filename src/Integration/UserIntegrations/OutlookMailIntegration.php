<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\OutlookMailService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class OutlookMailIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private OutlookMailService $outlookMailService,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'outlook_mail';
    }

    public function getName(): string
    {
        return 'Outlook Mail';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'outlook_mail_search',
                'Search emails in the user\'s mailbox. Uses Microsoft Graph $search (KQL) for full-text search across subject, body, sender. Returns subject, sender, date, preview, and attachments flag.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query string. Searches across subject, body, sender, and recipients. Examples:
• Simple: "project update"
• From sender: "from:john@example.com"
• With attachment: "hasAttachments:true budget"
• Subject only: "subject:quarterly report"
• Combined: "from:sarah subject:meeting"'
                    ],
                    [
                        'name' => 'folder',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Restrict search to a specific mail folder ID (from outlook_mail_list_folders). If omitted, searches all folders.'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 25, max: 50)'
                    ]
                ]
            ),
            new ToolDefinition(
                'outlook_mail_get_message',
                'Read the full content of a specific email by its ID. Returns full body text, sender, all recipients (to, cc, bcc), timestamps, attachments list, and conversation thread ID.',
                [
                    [
                        'name' => 'messageId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The email message ID (from search results or folder listing)'
                    ]
                ]
            ),
            new ToolDefinition(
                'outlook_mail_list_folders',
                'List all mail folders in the user\'s mailbox. Returns folder name, total item count, and unread item count. Includes standard folders (Inbox, Sent Items, Drafts) and custom folders.',
                []
            ),
            new ToolDefinition(
                'outlook_mail_list_messages',
                'List messages in a specific mail folder with pagination. Returns messages sorted by received date (newest first). Use for browsing a folder or getting recent emails.',
                [
                    [
                        'name' => 'folderId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The mail folder ID (from outlook_mail_list_folders)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of messages to return (default: 25, max: 50)'
                    ],
                    [
                        'name' => 'skip',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of messages to skip for pagination (default: 0)'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Outlook Mail integration requires credentials');
        }

        if (isset($credentials['expires_at']) && time() >= $credentials['expires_at']) {
            $credentials = $this->refreshTokenIfNeeded($credentials);
            if (isset($parameters['configId'])) {
                $this->persistRefreshedCredentials((int) $parameters['configId'], $credentials);
            }
        }

        return match ($toolName) {
            'outlook_mail_search' => $this->outlookMailService->searchMessages(
                $credentials,
                $parameters['query'],
                $parameters['folder'] ?? null,
                min($parameters['limit'] ?? 25, 50)
            ),
            'outlook_mail_get_message' => $this->outlookMailService->getMessage(
                $credentials,
                $parameters['messageId']
            ),
            'outlook_mail_list_folders' => $this->outlookMailService->listFolders($credentials),
            'outlook_mail_list_messages' => $this->outlookMailService->listMessages(
                $credentials,
                $parameters['folderId'],
                min($parameters['limit'] ?? 25, 50),
                $parameters['skip'] ?? 0
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
        return isset($credentials['access_token']);
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'oauth',
                'oauth',
                'Connect with Microsoft',
                null,
                true,
                'Authenticate with your Microsoft account to access Outlook Mail'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/outlook_mail_full.xml.twig', [
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
        return '/images/logos/outlook-mail-icon.svg';
    }

    private function persistRefreshedCredentials(int $configId, array $credentials): void
    {
        try {
            $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
            if ($config) {
                $config->setEncryptedCredentials(
                    $this->encryptionService->encrypt(json_encode($credentials))
                );
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            error_log('Failed to persist refreshed Outlook Mail credentials for config ' . $configId . ': ' . $e->getMessage());
        }
    }

    private function refreshTokenIfNeeded(array $credentials): array
    {
        if (!isset($credentials['refresh_token'])) {
            throw new \Exception('Outlook Mail token expired and no refresh token available');
        }

        $newTokens = $this->outlookMailService->refreshToken(
            $credentials['refresh_token'],
            $_ENV['AZURE_CLIENT_ID'] ?? $credentials['client_id'],
            $_ENV['AZURE_CLIENT_SECRET'] ?? $credentials['client_secret'],
            $credentials['tenant_id']
        );

        return array_merge($credentials, [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'],
            'expires_at' => $newTokens['expires_at']
        ]);
    }
}
