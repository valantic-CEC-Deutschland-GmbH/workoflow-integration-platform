<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;

class ReadFileIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.read_file_tool';
    }

    public function getName(): string
    {
        return 'Read File Tool';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'read_file_tool',
                'Call this tool when the user asks you to read a file or is asking a question related to a file.',
                [
                    [
                        'name' => 'url',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The download URL of the file to read'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'read_file_tool') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - read_file_tool'
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
