<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;

class ContentQueryIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.content_query';
    }

    public function getName(): string
    {
        return 'Content Query';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'content_query',
                'Use this tool to retrieve content that does not specifically relate to employees or colleagues. The tool returns not only available content but also the source, which needs to be validated against what was requested.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The search query to find relevant content'
                    ],
                    [
                        'name' => 'type',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'The type of content to search for (default: all)'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'content_query') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - content_query'
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
