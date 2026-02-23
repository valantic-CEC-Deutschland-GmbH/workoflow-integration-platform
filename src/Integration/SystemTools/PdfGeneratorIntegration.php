<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\ToolCategory;
use App\Integration\CredentialField;

class PdfGeneratorIntegration implements PlatformSkillInterface
{
    public function getType(): string
    {
        return 'system.generate_and_upload_pdf';
    }

    public function getName(): string
    {
        return 'PDF Generator';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'generate_and_upload_pdf',
                'Call this tool when user asking you to generate a PDF file. On success, you will get back a URL to the uploaded file that you can return in webhook response.',
                [
                    [
                        'name' => 'data',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Here you pass the data you want to generate into a PDF in a text form (pass the data as markdown)'
                    ],
                    [
                        'name' => 'userID',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'The AAD Object ID of the user'
                    ],
                    [
                        'name' => 'tenantID',
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
        if ($toolName !== 'generate_and_upload_pdf') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Stub implementation - no actual logic
        return [
            'success' => true,
            'message' => 'Tool execution simulated - generate_and_upload_pdf'
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
