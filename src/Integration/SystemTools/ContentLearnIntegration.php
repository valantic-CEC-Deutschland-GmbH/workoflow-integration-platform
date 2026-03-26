<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\ToolCategory;
use App\Integration\CredentialField;

class ContentLearnIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.content_learn';
    }

    public function getName(): string
    {
        return 'Content Learning';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'content_learn',
                'Call this tool when the user asks you to learn based on an uploaded file. This tool will then upload the file, and the file will be sent to a vector database. Then in the future, you can query on the vector database.',
                [
                    [
                        'name' => 'url',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The download URL of the file to learn from'
                    ],
                    [
                        'name' => 'filetype',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The type of the file (csv, pdf, txt)'
                    ],
                    [
                        'name' => 'userName',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'The name of the user'
                    ],
                    [
                        'name' => 'filename',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'The name of the file'
                    ],
                    [
                        'name' => 'tenantId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'The tenant ID'
                    ]
                ],
                ToolCategory::WRITE
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'content_learn') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - content_learn'
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
