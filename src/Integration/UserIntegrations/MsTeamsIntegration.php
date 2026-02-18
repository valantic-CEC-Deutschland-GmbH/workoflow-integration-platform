<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\MsTeamsService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class MsTeamsIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private MsTeamsService $msTeamsService,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'msteams';
    }

    public function getName(): string
    {
        return 'MS Teams';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'teams_list_teams',
                'List all Microsoft Teams the user is a member of. Returns team name, description, and ID. Use this as the starting point to navigate teams and channels.',
                []
            ),
            new ToolDefinition(
                'teams_list_channels',
                'List all channels in a specific team. Returns channel name, description, and membership type (standard, private, shared).',
                [
                    [
                        'name' => 'teamId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The team ID (from teams_list_teams)'
                    ]
                ]
            ),
            new ToolDefinition(
                'teams_read_channel_messages',
                'Read recent messages from a team channel. Returns sender name, message body text, and timestamp. Messages are returned newest first.',
                [
                    [
                        'name' => 'teamId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The team ID'
                    ],
                    [
                        'name' => 'channelId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The channel ID (from teams_list_channels)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of messages to return (default: 25, max: 50)'
                    ]
                ]
            ),
            new ToolDefinition(
                'teams_send_channel_message',
                'Post a new message to a team channel on behalf of the user. IMPORTANT: Always confirm the message content with the user before sending.',
                [
                    [
                        'name' => 'teamId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The team ID'
                    ],
                    [
                        'name' => 'channelId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The channel ID'
                    ],
                    [
                        'name' => 'message',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The message content to send'
                    ],
                    [
                        'name' => 'contentType',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Content type: "text" (plain text, default) or "html" (rich text with formatting)'
                    ]
                ]
            ),
            new ToolDefinition(
                'teams_create_channel',
                'Create a new channel in a team. IMPORTANT: Always confirm channel name and team with the user before creating.',
                [
                    [
                        'name' => 'teamId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The team ID where the channel will be created'
                    ],
                    [
                        'name' => 'displayName',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The display name for the new channel'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional description for the channel'
                    ]
                ]
            ),
            new ToolDefinition(
                'teams_list_chats',
                'List the user\'s recent 1:1 and group chats. Returns chat type, topic, last updated time, and member names.',
                [
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of chats to return (default: 25, max: 50)'
                    ]
                ]
            ),
            new ToolDefinition(
                'teams_read_chat_messages',
                'Read recent messages from a 1:1 or group chat. Returns sender name, message body text, and timestamp.',
                [
                    [
                        'name' => 'chatId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The chat ID (from teams_list_chats)'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of messages to return (default: 25, max: 50)'
                    ]
                ]
            ),
            new ToolDefinition(
                'teams_send_chat_message',
                'Send a message in an existing 1:1 or group chat on behalf of the user. IMPORTANT: Always confirm the message content with the user before sending.',
                [
                    [
                        'name' => 'chatId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The chat ID (from teams_list_chats)'
                    ],
                    [
                        'name' => 'message',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The message content to send'
                    ],
                    [
                        'name' => 'contentType',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Content type: "text" (plain text, default) or "html" (rich text with formatting)'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('MS Teams integration requires credentials');
        }

        if (isset($credentials['expires_at']) && time() >= $credentials['expires_at']) {
            $credentials = $this->refreshTokenIfNeeded($credentials);
            if (isset($parameters['configId'])) {
                $this->persistRefreshedCredentials((int) $parameters['configId'], $credentials);
            }
        }

        return match ($toolName) {
            'teams_list_teams' => $this->msTeamsService->listTeams($credentials),
            'teams_list_channels' => $this->msTeamsService->listChannels(
                $credentials,
                $parameters['teamId']
            ),
            'teams_read_channel_messages' => $this->msTeamsService->readChannelMessages(
                $credentials,
                $parameters['teamId'],
                $parameters['channelId'],
                min($parameters['limit'] ?? 25, 50)
            ),
            'teams_send_channel_message' => $this->msTeamsService->sendChannelMessage(
                $credentials,
                $parameters['teamId'],
                $parameters['channelId'],
                $parameters['message'],
                $parameters['contentType'] ?? 'text'
            ),
            'teams_create_channel' => $this->msTeamsService->createChannel(
                $credentials,
                $parameters['teamId'],
                $parameters['displayName'],
                $parameters['description'] ?? null
            ),
            'teams_list_chats' => $this->msTeamsService->listChats(
                $credentials,
                min($parameters['limit'] ?? 25, 50)
            ),
            'teams_read_chat_messages' => $this->msTeamsService->readChatMessages(
                $credentials,
                $parameters['chatId'],
                min($parameters['limit'] ?? 25, 50)
            ),
            'teams_send_chat_message' => $this->msTeamsService->sendChatMessage(
                $credentials,
                $parameters['chatId'],
                $parameters['message'],
                $parameters['contentType'] ?? 'text'
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
                'Authenticate with your Microsoft account to access MS Teams'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/msteams_full.xml.twig', [
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
        return '/images/logos/msteams-icon.svg';
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
            error_log('Failed to persist refreshed MS Teams credentials for config ' . $configId . ': ' . $e->getMessage());
        }
    }

    private function refreshTokenIfNeeded(array $credentials): array
    {
        if (!isset($credentials['refresh_token'])) {
            throw new \Exception('MS Teams token expired and no refresh token available');
        }

        $newTokens = $this->msTeamsService->refreshToken(
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
