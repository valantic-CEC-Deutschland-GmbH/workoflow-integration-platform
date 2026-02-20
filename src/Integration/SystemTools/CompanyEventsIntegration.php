<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;

class CompanyEventsIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.fetch_valantic_events';
    }

    public function getName(): string
    {
        return 'Company Events';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'fetch_valantic_events',
                'Use this tool to find out about upcoming events at Valantic.',
                []  // No parameters required - sub-workflow trigger
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'fetch_valantic_events') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - fetch_valantic_events'
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
