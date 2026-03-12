<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;

class WebSearchIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.searxng_tool';
    }

    public function getName(): string
    {
        return 'Web Search';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'SearXNG_tool',
                'Use this tool to search in web, currently you have these search engines enabled: google, duckduckgo',
                [
                    [
                        'name' => 'search_string',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The search query string to search for on the web'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'SearXNG_tool') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - SearXNG_tool'
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
