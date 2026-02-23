<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\ToolCategory;
use App\Integration\CredentialField;

class PowerPointGeneratorIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.generate_pptx';
    }

    public function getName(): string
    {
        return 'PowerPoint Generator';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'generate_pptx',
                'Use this tool to prepare text content as a PowerPoint presentation. You must provide the data basis, for which the tool will then create a presentation.',
                [
                    [
                        'name' => 'input',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'A collection of content that should be prepared in the slide deck'
                    ],
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'A summary of the request that the user has made. Will be used as context when generating the slides'
                    ],
                    [
                        'name' => 'user_id',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'The AAD Object ID of the user'
                    ],
                    [
                        'name' => 'tenant_id',
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
        if ($toolName !== 'generate_pptx') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - generate_pptx'
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
