<?php

namespace App\Controller;

use App\DTO\ToolFilterCriteria;
use App\Entity\IntegrationConfig;
use App\Integration\IntegrationRegistry;
use App\Repository\IntegrationConfigRepository;
use App\Repository\UserOrganisationRepository;
use App\Service\AuditLogService;
use App\Service\ConnectionStatusService;
use App\Service\EncryptionService;
use App\Service\Integration\RemoteMcpService;
use App\Service\ToolProviderService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dedicated API endpoints for MCP server integration.
 * Uses X-Prompt-Token authentication (personal access token).
 * Token identifies user AND organisation automatically.
 */
#[Route('/api/mcp')]
class McpApiController extends AbstractController
{
    public function __construct(
        private UserOrganisationRepository $userOrganisationRepository,
        private IntegrationConfigRepository $integrationConfigRepository,
        private EncryptionService $encryptionService,
        private IntegrationRegistry $integrationRegistry,
        private ToolProviderService $toolProviderService,
        private AuditLogService $auditLogService,
        private ConnectionStatusService $connectionStatusService,
        private RemoteMcpService $remoteMcpService,
        #[Autowire(service: 'monolog.logger.integration_api')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * GET /api/mcp/tools
     * List available tools for the authenticated user.
     * Headers: X-Prompt-Token (required)
     * Query: tool_type (optional, CSV filter)
     */
    #[Route('/tools', name: 'api_mcp_tools', methods: ['GET'])]
    public function getTools(Request $request): JsonResponse
    {
        // Validate token and get user/organisation
        $authResult = $this->authenticateToken($request);
        if ($authResult instanceof JsonResponse) {
            return $authResult;
        }

        [$userOrganisation, $user, $organisation] = $authResult;

        // Parse filter criteria (supports CSV: ?tool_type=system,jira,confluence)
        $criteria = ToolFilterCriteria::fromCsv(
            $userOrganisation->getWorkflowUserId(),
            $request->query->get('tool_type')
        );

        // Capture execution_id for audit logging
        $executionId = $request->query->get('execution_id');

        // Delegate to service
        $tools = $this->toolProviderService->getToolsForOrganisation($organisation, $criteria);

        // Log API access
        $this->auditLogService->logWithOrganisation(
            'api.mcp.get_tools',
            $organisation,
            $user,
            [
                'workflow_user_id' => $criteria->getWorkflowUserId(),
                'tool_types' => $criteria->getToolTypes(),
                'tools_count' => count($tools),
            ],
            $executionId
        );

        // Use manual json_encode to preserve stdClass as {} (Symfony serializer converts to [])
        return new JsonResponse(json_encode(['tools' => $tools]), Response::HTTP_OK, [], true);
    }

    /**
     * POST /api/mcp/execute
     * Execute a tool on behalf of the authenticated user.
     * Headers: X-Prompt-Token (required)
     * Body: { tool_id, parameters }
     */
    #[Route('/execute', name: 'api_mcp_execute', methods: ['POST'])]
    public function executeTool(Request $request): JsonResponse
    {
        // Validate token and get user/organisation
        $authResult = $this->authenticateToken($request);
        if ($authResult instanceof JsonResponse) {
            return $authResult;
        }

        [$userOrganisation, $user, $organisation] = $authResult;
        $workflowUserId = $userOrganisation->getWorkflowUserId();

        // Get request data
        $data = json_decode($request->getContent(), true);
        $toolId = $data['tool_id'] ?? null;
        $parameters = $data['parameters'] ?? [];
        $executionId = $data['execution_id'] ?? $request->query->get('execution_id');

        if (!$toolId) {
            return $this->json(['error' => 'Tool ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('MCP Tool execution requested', [
            'organisation' => $organisation->getName(),
            'tool_id' => $toolId,
            'workflow_user_id' => $workflowUserId
        ]);

        // Parse tool ID (might have _configId suffix for user integrations or _org suffix for org MCP)
        $parts = explode('_', $toolId);
        $configId = null;
        $toolName = $toolId;
        $isOrgMcp = false;

        // Check if this is an org-wide MCP tool (ends with _org)
        if (count($parts) > 1 && end($parts) === 'org') {
            $isOrgMcp = true;
            array_pop($parts);
            $toolName = implode('_', $parts);
        } elseif (count($parts) > 1 && is_numeric(end($parts))) {
            // Check if this is a user integration tool with config ID
            $configId = (int) array_pop($parts);
            $toolName = implode('_', $parts);
        }

        // Handle org-wide MCP tool execution
        if ($isOrgMcp) {
            return $this->executeOrgMcpTool(
                $organisation,
                $user,
                $toolName,
                $parameters,
                $workflowUserId,
                $executionId
            );
        }

        $config = null;
        $targetIntegration = null;

        try {
            // Find the integration that handles this tool
            $targetTool = null;

            foreach ($this->integrationRegistry->getAllIntegrations() as $integration) {
                foreach ($integration->getTools() as $tool) {
                    if ($tool->getName() === $toolName) {
                        $targetIntegration = $integration;
                        $targetTool = $tool;
                        break 2;
                    }
                }
            }

            if (!$targetIntegration || !$targetTool) {
                // Fallback: Check if this is a remote MCP tool (tools are dynamic, not in getTools())
                if ($configId) {
                    $remoteMcpResult = $this->executeRemoteMcpTool(
                        $configId,
                        $organisation,
                        $user,
                        $toolName,
                        $parameters,
                        $workflowUserId,
                        $executionId
                    );
                    if ($remoteMcpResult !== null) {
                        return $remoteMcpResult;
                    }
                }

                return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
            }

            // Get credentials if needed
            $credentials = null;
            if ($targetIntegration->requiresCredentials()) {
                if (!$configId) {
                    return $this->json(['error' => 'Configuration ID required for user integration tools'], Response::HTTP_BAD_REQUEST);
                }

                $config = $this->integrationConfigRepository->find($configId);
                if (!$config || $config->getOrganisation() !== $organisation) {
                    return $this->json(['error' => 'Configuration not found'], Response::HTTP_NOT_FOUND);
                }

                if (!$config->isActive() || $config->isToolDisabled($toolName)) {
                    return $this->json(['error' => 'Tool is disabled'], Response::HTTP_FORBIDDEN);
                }

                // Decrypt credentials
                $encryptedCreds = $config->getEncryptedCredentials();
                if ($encryptedCreds) {
                    $credentials = json_decode($this->encryptionService->decrypt($encryptedCreds), true);
                }

                // Validate credentials are available for user integrations
                if (empty($credentials)) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Credentials not available',
                        'message' => 'Failed to retrieve or decrypt credentials for this integration',
                        'error_code' => 400,
                        'context' => [
                            'tool_id' => $toolId,
                            'tool_name' => $toolName,
                            'integration_type' => $targetIntegration->getType(),
                        ],
                        'hint' => 'Re-configure the integration with valid credentials',
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Update last accessed
                $this->integrationConfigRepository->updateLastAccessed($config);
            } else {
                // For system tools, check if disabled (if config exists)
                $config = $this->integrationConfigRepository->findOneByOrganisationAndType(
                    $organisation,
                    $targetIntegration->getType(),
                    $workflowUserId
                );

                if ($config && (!$config->isActive() || $config->isToolDisabled($toolName))) {
                    return $this->json(['error' => 'Tool is disabled'], Response::HTTP_FORBIDDEN);
                }
            }

            // Add organisation context to parameters
            $parameters['organisationId'] = $organisation->getId();
            $parameters['organisationUuid'] = $organisation->getUuid();
            $parameters['workflowUserId'] = $workflowUserId;

            // Log tool execution start
            $this->auditLogService->logWithOrganisation(
                'tool_execution.started',
                $organisation,
                $user,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => $targetIntegration->getType(),
                    'workflow_user_id' => $workflowUserId,
                    'request_payload' => $this->auditLogService->sanitizeData($parameters),
                    'source' => 'mcp',
                ],
                $executionId
            );

            // Execute the tool
            $result = $targetIntegration->executeTool($toolName, $parameters, $credentials);

            $this->logger->info('MCP Tool executed successfully', [
                'organisation' => $organisation->getName(),
                'tool_id' => $toolId,
                'integration_type' => $targetIntegration->getType()
            ]);

            // Log successful execution
            $this->auditLogService->logWithOrganisation(
                'tool_execution.completed',
                $organisation,
                $user,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => $targetIntegration->getType(),
                    'workflow_user_id' => $workflowUserId,
                    'success' => true,
                    'response_data' => $this->auditLogService->truncateData($result, 5000),
                    'source' => 'mcp',
                ],
                $executionId
            );

            return $this->json([
                'success' => true,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            $errorDetails = $this->formatExceptionDetails($e);

            // Check for credential failure and mark integration as disconnected
            if (
                $config instanceof IntegrationConfig &&
                $targetIntegration !== null &&
                $targetIntegration->requiresCredentials() &&
                $this->connectionStatusService->isCredentialFailure($e, $targetIntegration->getType())
            ) {
                $this->connectionStatusService->markDisconnected($config, $errorDetails['message']);
            }

            $this->logger->error('MCP Tool execution failed', [
                'organisation' => $organisation->getName(),
                'tool_id' => $toolId,
                'tool_name' => $toolName,
                'error' => $errorDetails['message'],
                'exception_class' => get_class($e),
                'error_code' => $errorDetails['code'],
            ]);

            // Log failed execution
            $this->auditLogService->logWithOrganisation(
                'tool_execution.failed',
                $organisation,
                $user,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => $targetIntegration?->getType() ?? 'unknown',
                    'workflow_user_id' => $workflowUserId,
                    'success' => false,
                    'error' => $errorDetails['message'],
                    'source' => 'mcp',
                ],
                $executionId
            );

            return $this->json([
                'success' => false,
                'error' => 'Tool execution failed',
                'message' => $errorDetails['message'],
                'error_code' => $errorDetails['code'],
                'context' => [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => $targetIntegration?->getType() ?? 'unknown',
                ],
                'hint' => $errorDetails['hint'],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Execute a remote MCP tool by config ID.
     * Returns JsonResponse if handled, null if not a remote MCP config.
     */
    private function executeRemoteMcpTool(
        int $configId,
        \App\Entity\Organisation $organisation,
        \App\Entity\User $user,
        string $toolName,
        array $parameters,
        ?string $workflowUserId,
        ?string $executionId
    ): ?JsonResponse {
        $config = $this->integrationConfigRepository->find($configId);
        if (
            !$config
            || $config->getIntegrationType() !== 'remote_mcp'
            || $config->getOrganisation() !== $organisation
        ) {
            return null;
        }

        if (!$config->isActive() || !$config->isConnected()) {
            return $this->json(['error' => 'Remote MCP server is not active or disconnected'], Response::HTTP_FORBIDDEN);
        }

        $encryptedCreds = $config->getEncryptedCredentials();
        if (!$encryptedCreds) {
            return $this->json(['error' => 'No credentials configured'], Response::HTTP_BAD_REQUEST);
        }

        $credentials = json_decode($this->encryptionService->decrypt($encryptedCreds), true);
        if (empty($credentials)) {
            return $this->json(['error' => 'Failed to decrypt credentials'], Response::HTTP_BAD_REQUEST);
        }

        // Add organisation context
        $parameters['organisationId'] = $organisation->getId();
        $parameters['organisationUuid'] = $organisation->getUuid();
        $parameters['workflowUserId'] = $workflowUserId;

        $toolId = $toolName . '_' . $configId;

        // Log execution start
        $this->auditLogService->logWithOrganisation(
            'tool_execution.started',
            $organisation,
            $user,
            [
                'tool_id' => $toolId,
                'tool_name' => $toolName,
                'integration_type' => 'remote_mcp',
                'workflow_user_id' => $workflowUserId,
                'request_payload' => $this->auditLogService->sanitizeData($parameters),
                'source' => 'mcp',
            ],
            $executionId
        );

        try {
            // Strip internal parameters before forwarding
            $forwardParams = array_diff_key($parameters, array_flip([
                'organisationId', 'organisationUuid', 'workflowUserId', 'configId',
            ]));

            $result = $this->remoteMcpService->executeTool($credentials, $toolName, $forwardParams);

            $this->integrationConfigRepository->updateLastAccessed($config);

            $this->auditLogService->logWithOrganisation(
                'tool_execution.completed',
                $organisation,
                $user,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => 'remote_mcp',
                    'workflow_user_id' => $workflowUserId,
                    'success' => true,
                    'response_data' => $this->auditLogService->truncateData($result, 5000),
                    'source' => 'mcp',
                ],
                $executionId
            );

            return $this->json(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            $errorDetails = $this->formatExceptionDetails($e);

            if ($this->connectionStatusService->isCredentialFailure($e, 'remote_mcp')) {
                $this->connectionStatusService->markDisconnected($config, $errorDetails['message']);
            }

            $this->auditLogService->logWithOrganisation(
                'tool_execution.failed',
                $organisation,
                $user,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => 'remote_mcp',
                    'workflow_user_id' => $workflowUserId,
                    'success' => false,
                    'error' => $errorDetails['message'],
                    'source' => 'mcp',
                ],
                $executionId
            );

            return $this->json([
                'success' => false,
                'error' => 'Tool execution failed',
                'message' => $errorDetails['message'],
                'error_code' => $errorDetails['code'],
                'context' => [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => 'remote_mcp',
                ],
                'hint' => $errorDetails['hint'],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Execute a tool on the organisation-wide MCP server.
     */
    private function executeOrgMcpTool(
        \App\Entity\Organisation $organisation,
        \App\Entity\User $user,
        string $toolName,
        array $parameters,
        ?string $workflowUserId,
        ?string $executionId
    ): JsonResponse {
        $orgMcpUrl = $organisation->getOrgMcpServerUrl();
        if (!$orgMcpUrl) {
            return $this->json(['error' => 'Organisation MCP server not configured'], Response::HTTP_BAD_REQUEST);
        }

        $customHeaders = '';
        $encryptedAuthHeader = $organisation->getEncryptedOrgMcpAuthHeader();
        if ($encryptedAuthHeader) {
            try {
                $customHeaders = $this->encryptionService->decrypt($encryptedAuthHeader);
            } catch (\Exception) {
                return $this->json(['error' => 'Failed to decrypt org MCP auth header'], Response::HTTP_BAD_REQUEST);
            }
        }

        $credentials = [
            'server_url' => $orgMcpUrl,
            'auth_type' => 'none',
            'custom_headers' => $customHeaders,
        ];

        $toolId = $toolName . '_org';

        // Log execution start
        $this->auditLogService->logWithOrganisation(
            'tool_execution.started',
            $organisation,
            $user,
            [
                'tool_id' => $toolId,
                'tool_name' => $toolName,
                'integration_type' => 'remote_mcp_org',
                'workflow_user_id' => $workflowUserId,
                'request_payload' => $this->auditLogService->sanitizeData($parameters),
                'source' => 'mcp',
            ],
            $executionId
        );

        try {
            // Strip internal parameters before forwarding
            $forwardParams = array_diff_key($parameters, array_flip([
                'organisationId', 'organisationUuid', 'workflowUserId', 'configId',
            ]));

            $result = $this->remoteMcpService->executeTool($credentials, $toolName, $forwardParams);

            $this->auditLogService->logWithOrganisation(
                'tool_execution.completed',
                $organisation,
                $user,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => 'remote_mcp_org',
                    'workflow_user_id' => $workflowUserId,
                    'success' => true,
                    'response_data' => $this->auditLogService->truncateData($result, 5000),
                    'source' => 'mcp',
                ],
                $executionId
            );

            return $this->json(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            $errorDetails = $this->formatExceptionDetails($e);

            $this->auditLogService->logWithOrganisation(
                'tool_execution.failed',
                $organisation,
                $user,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => 'remote_mcp_org',
                    'workflow_user_id' => $workflowUserId,
                    'success' => false,
                    'error' => $errorDetails['message'],
                    'source' => 'mcp',
                ],
                $executionId
            );

            return $this->json([
                'success' => false,
                'error' => 'Tool execution failed',
                'message' => $errorDetails['message'],
                'error_code' => $errorDetails['code'],
                'context' => [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => 'remote_mcp_org',
                ],
                'hint' => $errorDetails['hint'],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Authenticate using X-Prompt-Token header.
     * Returns [UserOrganisation, User, Organisation] on success, or JsonResponse on failure.
     *
     * @return array{0: \App\Entity\UserOrganisation, 1: \App\Entity\User, 2: \App\Entity\Organisation}|JsonResponse
     */
    private function authenticateToken(Request $request): array|JsonResponse
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->json([
                'error' => 'No API token provided',
                'message' => 'Please generate a token at /profile/',
                'hint' => 'Include token via X-Prompt-Token header or Authorization: Bearer <token>',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userOrganisation = $this->userOrganisationRepository->findOneBy([
            'personalAccessToken' => $token,
        ]);

        if ($userOrganisation === null) {
            return $this->json([
                'error' => 'Invalid or expired token',
                'message' => 'Please generate a new token at /profile/',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = $userOrganisation->getUser();
        $organisation = $userOrganisation->getOrganisation();

        if ($user === null || $organisation === null) {
            return $this->json([
                'error' => 'Invalid token configuration',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return [$userOrganisation, $user, $organisation];
    }

    /**
     * Extract token from X-Prompt-Token header or Authorization: Bearer header.
     */
    private function extractToken(Request $request): ?string
    {
        // Try X-Prompt-Token header first
        $token = $request->headers->get('X-Prompt-Token');

        if ($token !== null && $token !== '') {
            return $token;
        }

        // Try Authorization: Bearer header
        $authHeader = $request->headers->get('Authorization', '');

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * Format exception details for API response.
     *
     * @return array{message: string, code: int, hint: string}
     */
    private function formatExceptionDetails(\Throwable $e): array
    {
        // Handle Symfony HttpClient ClientException (4xx errors)
        if ($e instanceof \Symfony\Component\HttpClient\Exception\ClientException) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            try {
                $errorData = $response->toArray(false);
                $errorText = $errorData['errorMessages'][0]
                    ?? $errorData['message']
                    ?? $errorData['error']['message']
                    ?? $errorData['error_description']
                    ?? (!empty($errorData['errors']) ? json_encode($errorData['errors']) : null)
                    ?? 'Client error';
            } catch (\Throwable) {
                $errorText = $e->getMessage();
            }
            return [
                'message' => "API Error (HTTP {$statusCode}): {$errorText}",
                'code' => $statusCode,
                'hint' => 'Request error - check parameters and credentials',
            ];
        }

        // Handle Symfony HttpClient ServerException (5xx errors)
        if ($e instanceof \Symfony\Component\HttpClient\Exception\ServerException) {
            $statusCode = $e->getResponse()->getStatusCode();
            return [
                'message' => "Server Error (HTTP {$statusCode}): The external service encountered an internal error",
                'code' => $statusCode,
                'hint' => 'External service returned a server error (5xx) - try again later',
            ];
        }

        // Handle Symfony HttpClient TransportException (network errors)
        if ($e instanceof \Symfony\Component\HttpClient\Exception\TransportException) {
            return [
                'message' => "Connection Error: Cannot reach external service. Details: " . $e->getMessage(),
                'code' => 0,
                'hint' => 'Network error - check if the external service is reachable',
            ];
        }

        // Handle generic exceptions with smart hint detection
        $msg = strtolower($e->getMessage());
        $hint = match (true) {
            str_contains($msg, 'undefined array key') || str_contains($msg, 'credentials')
                => 'Credentials may be missing or invalid - verify integration configuration',
            str_contains($msg, 'required')
                => 'Required field missing - check API documentation for required parameters',
            str_contains($msg, 'not found') || str_contains($msg, '404')
                => 'Resource not found - verify the ID/key exists',
            str_contains($msg, 'permission') || str_contains($msg, 'forbidden') || str_contains($msg, '403')
                => 'Permission denied - check API token permissions',
            str_contains($msg, 'unauthorized') || str_contains($msg, '401')
                => 'Authentication failed - verify credentials',
            default => 'Check the error message for details',
        };

        return [
            'message' => $e->getMessage() ?: 'Unknown error occurred',
            'code' => $e->getCode() ?: 500,
            'hint' => $hint,
        ];
    }
}
