<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;

class EmployeeProfileIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.employee_profile';
    }

    public function getName(): string
    {
        return 'Employee Profile';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'employee_profile',
                'Use this tool to load information and contact details for a specific employee using the employee_id.',
                [
                    [
                        'name' => 'employee_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'ID of the employee being sought (integer)'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'employee_profile') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - employee_profile'
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
