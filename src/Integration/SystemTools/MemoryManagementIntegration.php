<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\ToolCategory;
use App\Integration\CredentialField;

class MemoryManagementIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.clear_memory_tool';
    }

    public function getName(): string
    {
        return 'Memory Management';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'clear_memory_tool',
                'Use this tool to clear the conversation memory when requested by the user.',
                [],  // No parameters required based on screenshot
                ToolCategory::DELETE
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'clear_memory_tool') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - clear_memory_tool'
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
