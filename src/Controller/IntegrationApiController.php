<?php

namespace App\Controller;

use App\DTO\ToolFilterCriteria;
use App\Entity\IntegrationConfig;
use App\Entity\Organisation;
use App\Integration\IntegrationRegistry;
use App\Repository\IntegrationConfigRepository;
use App\Repository\OrganisationRepository;
use App\Service\AuditLogService;
use App\Service\ConnectionStatusService;
use App\Service\EncryptionService;
use App\Service\ToolProviderService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/integrations')]
class IntegrationApiController extends AbstractController
{
    public function __construct(
        private OrganisationRepository $organisationRepository,
        private IntegrationConfigRepository $integrationConfigRepository,
        private EncryptionService $encryptionService,
        private IntegrationRegistry $integrationRegistry,
        private ToolProviderService $toolProviderService,
        private AuditLogService $auditLogService,
        private ConnectionStatusService $connectionStatusService,
        #[Autowire(service: 'monolog.logger.integration_api')]
        private LoggerInterface $logger,
        private string $apiAuthUser,
        private string $apiAuthPassword,
    ) {
    }

    #[Route('/{organisationUuid}', name: 'api_integration_tools', methods: ['GET'])]
    public function getTools(string $organisationUuid, Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Get organisation
        $organisation = $this->organisationRepository->findOneBy(['uuid' => $organisationUuid]);
        if (!$organisation) {
            return $this->json(['error' => 'Organisation not found'], Response::HTTP_NOT_FOUND);
        }

        // Parse filter criteria (supports CSV: ?tool_type=system,jira,confluence)
        $criteria = ToolFilterCriteria::fromCsv(
            $request->query->get('workflow_user_id'),
            $request->query->get('tool_type')
        );

        // Capture execution_id for audit logging
        $executionId = $request->query->get('execution_id');

        // Delegate to service
        $tools = $this->toolProviderService->getToolsForOrganisation($organisation, $criteria);

        // Log API access with execution_id
        $this->auditLogService->logWithOrganisation(
            'api.get_tools',
            $organisation,
            null,
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

    #[Route('/{organisationUuid}/execute', name: 'api_integration_execute', methods: ['POST'])]
    public function executeTool(string $organisationUuid, Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Get organisation
        $organisation = $this->organisationRepository->findOneBy(['uuid' => $organisationUuid]);
        if (!$organisation) {
            return $this->json(['error' => 'Organisation not found'], Response::HTTP_NOT_FOUND);
        }

        // Get request data
        $data = json_decode($request->getContent(), true);
        $toolId = $data['tool_id'] ?? null;
        $parameters = $data['parameters'] ?? [];
        $workflowUserId = $data['workflow_user_id'] ?? null;
        $executionId = $data['execution_id'] ?? $request->query->get('execution_id');

        if (!$toolId) {
            return $this->json(['error' => 'Tool ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('API Tool execution requested', [
            'organisation' => $organisation->getName(),
            'tool_id' => $toolId,
            'workflow_user_id' => $workflowUserId
        ]);

        // Parse tool ID (might have _configId suffix for user integrations)
        $parts = explode('_', $toolId);
        $configId = null;
        $toolName = $toolId;

        // Check if this is a user integration tool with config ID
        if (count($parts) > 1 && is_numeric(end($parts))) {
            $configId = (int) array_pop($parts);
            $toolName = implode('_', $parts);
        }

        try {
            // Find the integration that handles this tool
            $targetIntegration = null;
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
                return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
            }

            // Get credentials if needed
            $credentials = null;
            $user = null;
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

                // Get user from config
                $user = $config->getUser();

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
            if ($configId) {
                $parameters['configId'] = $configId;
            }

            // Log tool execution start with sanitized request payload
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
                ],
                $executionId
            );

            // Execute the tool
            $result = $targetIntegration->executeTool($toolName, $parameters, $credentials);

            $this->logger->info('API Tool executed successfully', [
                'organisation' => $organisation->getName(),
                'tool_id' => $toolId,
                'integration_type' => $targetIntegration->getType()
            ]);

            // Log successful execution with truncated response
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
            // Only applies to user integrations with valid config
            if (
                isset($config) &&
                $config instanceof IntegrationConfig &&
                $targetIntegration !== null &&
                $targetIntegration->requiresCredentials() &&
                $this->connectionStatusService->isCredentialFailure($e, $targetIntegration->getType())
            ) {
                $this->connectionStatusService->markDisconnected($config, $errorDetails['message']);
            }

            $this->logger->error('API Tool execution failed', [
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
                $user ?? null,
                [
                    'tool_id' => $toolId,
                    'tool_name' => $toolName,
                    'integration_type' => $targetIntegration?->getType() ?? 'unknown',
                    'workflow_user_id' => $workflowUserId,
                    'success' => false,
                    'error' => $errorDetails['message'],
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

    private function validateBasicAuth(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $encoded = substr($authHeader, 6);
        $decoded = base64_decode($encoded);
        if ($decoded === false) {
            return false;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return $username === $this->apiAuthUser && $password === $this->apiAuthPassword;
    }

    /**
     * Format exception details for API response.
     * Handles HttpClient exceptions (ClientException, ServerException, TransportException)
     * and extracts error details from API responses.
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
                // Extract from various API response formats (Jira, Confluence, SharePoint, etc.)
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
