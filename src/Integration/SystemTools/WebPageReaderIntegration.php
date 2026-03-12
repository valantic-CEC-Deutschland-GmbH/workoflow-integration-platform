<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;

class WebPageReaderIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.read_page_tool';
    }

    public function getName(): string
    {
        return 'Web Page Reader';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'read_page_tool',
                'Call this tool to extract the text from webpage. Dont call that for nexus-netsoft.atlassian.net domains or private pages (as sharepoint pages).',
                [
                    [
                        'name' => 'url',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The URL of the web page to read and extract text from'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'read_page_tool') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - read_page_tool'
        ];
    }

    public function requiresCredentials(): bool
    {
        return false; // System tools don't require external service credentials
    }

    public function validateCredentials(array $credentials): bool
    {
        return true; // No external credentials needed
    }

    public function getCredentialFields(): array
    {
        return []; // System tools don't need credential fields
    }
    public function isExperimental(): bool
    {
        return false;
    }

    public function getSetupInstructions(): ?string
    {
        return null;
    }

    public function getLogoPath(): string
    {
        return '/images/logos/workoflow-logo.png';
    }
}
