<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\CredentialField;
use App\Integration\PersonalizedSkillInterface;
use App\Service\Integration\RemoteMcpService;
use Twig\Environment;

class RemoteMcpIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private RemoteMcpService $remoteMcpService,
        private Environment $twig,
    ) {
    }

    public function getType(): string
    {
        return 'remote_mcp';
    }

    public function getName(): string
    {
        return 'Remote MCP Server';
    }

    /**
     * Remote MCP tools are dynamic and discovered at runtime.
     * Returns empty array since tools come from the remote server.
     *
     * @return array<int, \App\Integration\ToolDefinition>
     */
    public function getTools(): array
    {
        return [];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($credentials === null) {
            throw new \RuntimeException('Credentials are required for Remote MCP Server');
        }

        // Remove internal context parameters before forwarding
        $forwardParams = array_diff_key($parameters, array_flip([
            'organisationId',
            'organisationUuid',
            'workflowUserId',
            'configId',
        ]));

        return $this->remoteMcpService->executeTool($credentials, $toolName, $forwardParams);
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        if (empty($credentials['server_url'])) {
            return false;
        }

        return $this->remoteMcpService->testConnection($credentials);
    }

    /**
     * @return CredentialField[]
     */
    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'server_url',
                'url',
                'Server URL',
                'https://mcp.example.com/mcp',
                true,
                'HTTPS endpoint of the remote MCP server'
            ),
            new CredentialField(
                'auth_type',
                'select',
                'Authentication Type',
                null,
                true,
                'How to authenticate with the remote server',
                [
                    'none' => 'No Authentication',
                    'bearer' => 'Bearer Token',
                    'api_key' => 'API Key Header',
                    'basic' => 'Basic Auth',
                ]
            ),
            new CredentialField(
                'auth_token',
                'password',
                'Bearer Token',
                'Enter your bearer token',
                false,
                'OAuth2 or personal access token',
                null,
                'auth_type',
                'bearer'
            ),
            new CredentialField(
                'api_key_header',
                'text',
                'API Key Header Name',
                'X-API-Key',
                false,
                'HTTP header name for the API key',
                null,
                'auth_type',
                'api_key'
            ),
            new CredentialField(
                'api_key_value',
                'password',
                'API Key Value',
                'Enter your API key',
                false,
                'The API key value',
                null,
                'auth_type',
                'api_key'
            ),
            new CredentialField(
                'basic_username',
                'text',
                'Username',
                'Enter username',
                false,
                null,
                null,
                'auth_type',
                'basic'
            ),
            new CredentialField(
                'basic_password',
                'password',
                'Password',
                'Enter password',
                false,
                null,
                null,
                'auth_type',
                'basic'
            ),
            new CredentialField(
                'custom_headers',
                'text',
                'Custom Headers',
                'X-Custom: value',
                false,
                'Additional HTTP headers, one per line (Key: Value)'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/remote_mcp_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }

    public function isExperimental(): bool
    {
        return true;
    }

    public function getSetupInstructions(): ?string
    {
        return '<p>Connect to any vendor-provided Remote MCP Server. '
            . 'The platform will discover available tools automatically and make them available through your Workoflow MCP endpoint.</p>'
            . '<p><strong>Requirements:</strong> The remote server must support MCP Streamable HTTP transport (JSON-RPC 2.0 over HTTPS).</p>';
    }

    public function getLogoPath(): string
    {
        return '/images/logos/mcp-server-icon.svg';
    }
}
