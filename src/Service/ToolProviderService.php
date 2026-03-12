<?php

namespace App\Service;

use App\DTO\ToolFilterCriteria;
use App\Entity\IntegrationConfig;
use App\Entity\Organisation;
use App\Entity\User;
use App\Integration\IntegrationInterface;
use App\Integration\IntegrationRegistry;
use App\Integration\ToolCategory;
use App\Repository\IntegrationConfigRepository;
use App\Service\Integration\RemoteMcpService;

/**
 * Service for providing and filtering integration tools for API access
 *
 * Handles:
 * - Integration filtering (system vs user)
 * - Tool type filtering (CSV support)
 * - Disabled tool filtering
 * - Tool formatting for API responses
 * - Tool access mode filtering (read/write/delete)
 */
class ToolProviderService
{
    public function __construct(
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly IntegrationConfigRepository $configRepository,
        private readonly EncryptionService $encryptionService,
        private readonly RemoteMcpService $remoteMcpService,
    ) {
    }

    /**
     * Get filtered tools for an organisation
     *
     * @return array<array{type: string, function: array{name: string, description: string, parameters: array}}>
     */
    public function getToolsForOrganisation(
        Organisation $organisation,
        ToolFilterCriteria $criteria,
        ?User $user = null
    ): array {
        // Get configs from DB
        $configs = $this->configRepository->findByOrganisationAndWorkflowUser(
            $organisation,
            $criteria->getWorkflowUserId()
        );

        // Build config map for quick lookup
        $configMap = $this->buildConfigMap($configs);

        // Get allowed categories from user's access mode
        $allowedCategories = $user ? $user->getAllowedToolCategories() : null;

        // Get all integrations and filter
        $allIntegrations = $this->integrationRegistry->getAllIntegrations();
        $tools = [];

        foreach ($allIntegrations as $integration) {
            $integrationType = $integration->getType();
            $integrationConfigs = $configMap[$integrationType] ?? [];

            if (!$integration->requiresCredentials()) {
                // System integration
                $systemTools = $this->processSystemIntegration(
                    $integration,
                    $integrationConfigs,
                    $criteria,
                    $allowedCategories
                );
                $tools = array_merge($tools, $systemTools);
            } else {
                // User integration
                $userTools = $this->processUserIntegration(
                    $integration,
                    $integrationConfigs,
                    $criteria,
                    $allowedCategories
                );
                $tools = array_merge($tools, $userTools);
            }
        }

        // Include organisation-wide MCP server tools
        $orgMcpTools = $this->buildOrgMcpTools($organisation, $criteria, $allowedCategories);
        $tools = array_merge($tools, $orgMcpTools);

        return $tools;
    }

    /**
     * Build tools from organisation-wide MCP server
     *
     * @param ToolCategory[]|null $allowedCategories
     * @return array
     */
    private function buildOrgMcpTools(
        Organisation $organisation,
        ToolFilterCriteria $criteria,
        ?array $allowedCategories = null
    ): array {
        $orgMcpUrl = $organisation->getOrgMcpServerUrl();
        if (!$orgMcpUrl) {
            return [];
        }

        // Skip if filter specifies only system tools
        if ($criteria->includesOnlySystemTools()) {
            return [];
        }

        // Skip if filter specifies types and remote_mcp is not included
        if ($criteria->hasToolTypeFilter() && !$criteria->includesSpecificType('remote_mcp')) {
            return [];
        }

        // Build credentials from organisation fields
        $customHeaders = '';
        $encryptedAuthHeader = $organisation->getEncryptedOrgMcpAuthHeader();
        if ($encryptedAuthHeader) {
            try {
                $customHeaders = $this->encryptionService->decrypt($encryptedAuthHeader);
            } catch (\Exception) {
                // If decryption fails, proceed without auth header
            }
        }

        $credentials = [
            'server_url' => $orgMcpUrl,
            'auth_type' => 'none',
            'custom_headers' => $customHeaders,
        ];

        try {
            $mcpTools = $this->remoteMcpService->discoverTools($credentials);
        } catch (\Exception) {
            return [];
        }

        $tools = [];
        foreach ($mcpTools as $mcpTool) {
            $toolName = $mcpTool['name'] ?? '';
            if ($toolName === '') {
                continue;
            }

            // All remote MCP tools are treated as READ by default
            if ($allowedCategories !== null && !in_array(ToolCategory::READ, $allowedCategories, true)) {
                continue;
            }

            $description = $mcpTool['description'] ?? 'Remote MCP tool';
            $description .= ' (via Org MCP: ' . $orgMcpUrl . ')';

            $parameters = $this->convertMcpInputSchema($mcpTool['inputSchema'] ?? []);

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $toolName . '_org',
                    'tool_id' => $toolName . '_org',
                    'description' => $description,
                    'parameters' => $parameters,
                ],
            ];
        }

        return $tools;
    }

    /**
     * Build config map (type => array of configs)
     *
     * @param IntegrationConfig[] $configs
     * @return array<string, IntegrationConfig[]>
     */
    private function buildConfigMap(array $configs): array
    {
        $configMap = [];
        foreach ($configs as $config) {
            $type = $config->getIntegrationType();
            if (!isset($configMap[$type])) {
                $configMap[$type] = [];
            }
            $configMap[$type][] = $config;
        }

        return $configMap;
    }

    /**
     * Process system integration (no credentials required)
     *
     * @param IntegrationConfig[] $configs
     * @param ToolCategory[]|null $allowedCategories
     * @return array
     */
    private function processSystemIntegration(
        IntegrationInterface $integration,
        array $configs,
        ToolFilterCriteria $criteria,
        ?array $allowedCategories = null
    ): array {
        // System tools are excluded by default unless explicitly requested
        if (!$this->shouldIncludeSystemIntegration($integration, $criteria)) {
            return [];
        }

        // Use first config if exists (for disabled tools tracking)
        $config = $configs[0] ?? null;

        // Skip if explicitly disabled
        if ($config !== null && !$config->isActive()) {
            return [];
        }

        // Build tools
        $disabledTools = $config?->getDisabledTools() ?? [];

        return $this->buildToolsArray($integration, $disabledTools, null, $allowedCategories);
    }

    /**
     * Process user integration (credentials required) - may have multiple instances
     *
     * @param IntegrationConfig[] $configs
     * @param ToolCategory[]|null $allowedCategories
     * @return array
     */
    private function processUserIntegration(
        IntegrationInterface $integration,
        array $configs,
        ToolFilterCriteria $criteria,
        ?array $allowedCategories = null
    ): array {
        // Skip if filter specifies only system tools
        if ($criteria->includesOnlySystemTools()) {
            return [];
        }

        // Skip if filter specifies types and this type is not included
        if (
            $criteria->hasToolTypeFilter() &&
            !$criteria->includesSpecificType($integration->getType())
        ) {
            return [];
        }

        $tools = [];
        foreach ($configs as $config) {
            // Skip if inactive, no credentials, or disconnected
            if (!$config->isActive() || !$config->hasCredentials() || !$config->isConnected()) {
                continue;
            }

            // For remote_mcp integrations, discover tools dynamically
            if ($integration->getType() === 'remote_mcp') {
                $remoteMcpTools = $this->buildRemoteMcpTools($config, $allowedCategories);
                $tools = array_merge($tools, $remoteMcpTools);
                continue;
            }

            // Build tools for this instance with unique IDs
            $disabledTools = $config->getDisabledTools();
            $instanceTools = $this->buildToolsArray(
                $integration,
                $disabledTools,
                $config, // Pass config for instance-specific naming
                $allowedCategories
            );

            $tools = array_merge($tools, $instanceTools);
        }

        return $tools;
    }

    /**
     * Check if system integration should be included based on filter
     */
    private function shouldIncludeSystemIntegration(
        IntegrationInterface $integration,
        ToolFilterCriteria $criteria
    ): bool {
        if (!$criteria->hasToolTypeFilter()) {
            // No filter specified = exclude system tools
            return false;
        }

        $integrationType = $integration->getType();

        // Include if "system" is in the filter
        if ($criteria->includesSystemTools()) {
            return true;
        }

        // Include if this specific type is in the filter
        if ($criteria->includesSpecificType($integrationType)) {
            return true;
        }

        return false;
    }

    /**
     * Build tools array from integration, filtering disabled tools and by access mode
     *
     * @param array<string> $disabledTools
     * @param ToolCategory[]|null $allowedCategories
     * @return array
     */
    private function buildToolsArray(
        IntegrationInterface $integration,
        array $disabledTools,
        ?IntegrationConfig $config = null,
        ?array $allowedCategories = null
    ): array {
        $tools = [];

        foreach ($integration->getTools() as $tool) {
            if (in_array($tool->getName(), $disabledTools, true)) {
                continue; // Skip disabled tools
            }

            // Filter by access mode categories
            if ($allowedCategories !== null && !in_array($tool->getCategory(), $allowedCategories, true)) {
                continue;
            }

            $toolName = $tool->getName();
            $description = $tool->getDescription();

            // For user integrations with config, add instance ID and URL
            if ($config !== null) {
                $toolName .= '_' . $config->getId();

                // Extract URL from credentials to help AI agents identify correct instance
                $credentials = $this->getDecryptedCredentials($config);
                $url = $this->extractInstanceUrl($integration->getType(), $credentials);

                if ($url) {
                    $description .= ' (' . $url . ')';
                }
            }

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $toolName,
                    'tool_id' => $toolName,
                    'description' => $description,
                    'parameters' => $this->formatParameters($tool->getParameters())
                ]
            ];
        }

        return $tools;
    }

    /**
     * Build tools from a remote MCP server via dynamic discovery
     *
     * @param ToolCategory[]|null $allowedCategories
     * @return array
     */
    private function buildRemoteMcpTools(
        IntegrationConfig $config,
        ?array $allowedCategories = null
    ): array {
        $credentials = $this->getDecryptedCredentials($config);
        if (!$credentials) {
            return [];
        }

        try {
            $mcpTools = $this->remoteMcpService->discoverTools($credentials);
        } catch (\Exception $e) {
            return [];
        }

        $disabledTools = $config->getDisabledTools();
        $serverUrl = $credentials['server_url'] ?? '';
        $tools = [];

        foreach ($mcpTools as $mcpTool) {
            $toolName = $mcpTool['name'] ?? '';
            if ($toolName === '' || in_array($toolName, $disabledTools, true)) {
                continue;
            }

            // All remote MCP tools are treated as READ by default
            if ($allowedCategories !== null && !in_array(ToolCategory::READ, $allowedCategories, true)) {
                continue;
            }

            $description = $mcpTool['description'] ?? 'Remote MCP tool';
            if ($serverUrl !== '') {
                $description .= ' (via Remote MCP: ' . $serverUrl . ')';
            }

            // Convert MCP inputSchema to our parameter format
            $parameters = $this->convertMcpInputSchema($mcpTool['inputSchema'] ?? []);

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $toolName . '_' . $config->getId(),
                    'tool_id' => $toolName . '_' . $config->getId(),
                    'description' => $description,
                    'parameters' => $parameters,
                ],
            ];
        }

        return $tools;
    }

    /**
     * Convert MCP inputSchema (JSON Schema) to our API parameter format
     *
     * @param array $inputSchema
     * @return array
     */
    private function convertMcpInputSchema(array $inputSchema): array
    {
        if (empty($inputSchema)) {
            return [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ];
        }

        // MCP inputSchema is already JSON Schema format, pass through
        $properties = $inputSchema['properties'] ?? [];
        $required = $inputSchema['required'] ?? [];

        return [
            'type' => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
            'required' => $required,
        ];
    }

    /**
     * Format tool parameters for API response
     *
     * @param array $parameters
     * @return array
     */
    private function formatParameters(array $parameters): array
    {
        $properties = array_reduce($parameters, function ($props, $param) {
            $props[$param['name']] = [
                'type' => $param['type'] ?? 'string',
                'description' => $param['description'] ?? ''
            ];
            return $props;
        }, []);

        return [
            'type' => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
            'required' => array_values(array_filter(array_map(function ($param) {
                return ($param['required'] ?? false) ? $param['name'] : null;
            }, $parameters)))
        ];
    }

    /**
     * Get decrypted credentials from config
     *
     * @return array<string, mixed>|null
     */
    private function getDecryptedCredentials(IntegrationConfig $config): ?array
    {
        if (!$config->hasCredentials()) {
            return null;
        }

        try {
            $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
            $credentials = json_decode($decrypted, true);

            return is_array($credentials) ? $credentials : null;
        } catch (\Exception $e) {
            // If decryption fails, return null gracefully
            return null;
        }
    }

    /**
     * Extract instance URL from credentials based on integration type
     *
     * @param array<string, mixed>|null $credentials
     */
    private function extractInstanceUrl(string $integrationType, ?array $credentials): ?string
    {
        if (!$credentials) {
            return null;
        }

        return match ($integrationType) {
            'jira', 'confluence' => $credentials['url'] ?? null,
            'gitlab' => $credentials['gitlab_url'] ?? null,
            'sharepoint' => $credentials['sharepoint_url'] ?? null,
            'remote_mcp' => $credentials['server_url'] ?? null,
            default => null
        };
    }
}
