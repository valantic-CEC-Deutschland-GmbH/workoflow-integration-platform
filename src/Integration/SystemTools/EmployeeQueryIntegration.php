<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;

class EmployeeQueryIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.employees_query_v2';
    }

    public function getName(): string
    {
        return 'Employee Query';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'employees_query_v2',
                'Search for employees based on various criteria including name, skills, languages, roles, and certificates.',
                [
                    [
                        'name' => 'employee_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'First and last name of the employee being searched for, or just parts of it'
                    ],
                    [
                        'name' => 'projects_industries_skills',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'A concise description of what is being searched for. For example, "Experience with Java" or "Banking industry"'
                    ],
                    [
                        'name' => 'languages',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Languages spoken by the employee'
                    ],
                    [
                        'name' => 'roles',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Job roles or positions'
                    ],
                    [
                        'name' => 'certificates',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Professional certificates held by the employee'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results. Cannot be greater than 20. Try to stick to small numbers.'
                    ]
                ]
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'employees_query_v2') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - employees_query_v2'
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
