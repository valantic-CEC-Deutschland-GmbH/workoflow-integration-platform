<?php

namespace App\Integration\SystemTools;

use App\Integration\PlatformSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Integration\ToolCategory;
use App\Service\ShareFileService;

class ShareFileIntegration implements PlatformSkillInterface
{
    public function __construct(
        private ShareFileService $shareFileService
    ) {
    }

    public function getType(): string
    {
        return 'system.share_file';
    }

    public function getName(): string
    {
        return 'Standard';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'share_file',
                'Upload and share a file. Returns a signed URL that can be used to access the file.',
                [
                    [
                        'name' => 'binaryData',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Base64 encoded file content'
                    ],
                    [
                        'name' => 'fileName',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Original file name (optional, for reference only)'
                    ],
                    [
                        'name' => 'contentType',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'MIME type of the file'
                    ]
                ],
                ToolCategory::WRITE
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($toolName !== 'share_file') {
            throw new \InvalidArgumentException("Unknown tool: $toolName");
        }

        // Extract the actual file parameters
        $binaryData = $parameters['binaryData'] ?? null;
        $contentType = $parameters['contentType'] ?? 'application/octet-stream';
        $orgUuid = $parameters['organisationUuid'] ?? '';

        if (!$binaryData) {
            throw new \InvalidArgumentException('binaryData parameter is required');
        }

        return $this->shareFileService->shareFile(
            $binaryData,
            $contentType,
            $orgUuid
        );
    }

    public function requiresCredentials(): bool
    {
        return false; // System tools don't require external service credentials
    }

    public function validateCredentials(array $credentials): bool
    {
        return true; // No external credentials needed (API Basic Auth still required)
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
