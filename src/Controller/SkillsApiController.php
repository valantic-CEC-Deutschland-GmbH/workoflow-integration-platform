<?php

namespace App\Controller;

use App\DTO\ToolFilterCriteria;
use App\Entity\Organisation;
use App\Integration\IntegrationRegistry;
use App\Integration\PersonalizedSkillInterface;
use App\Repository\IntegrationConfigRepository;
use App\Repository\OrganisationRepository;
use App\Service\AuditLogService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/skills')]
class SkillsApiController extends AbstractController
{
    public function __construct(
        private OrganisationRepository $organisationRepository,
        private IntegrationConfigRepository $integrationConfigRepository,
        private IntegrationRegistry $integrationRegistry,
        private AuditLogService $auditLogService,
        #[Autowire(service: 'monolog.logger.integration_api')]
        private LoggerInterface $logger,
        private string $apiAuthUser,
        private string $apiAuthPassword
    ) {
    }

    #[Route('/', name: 'api_skills_list', methods: ['GET'])]
    public function getSkills(Request $request): JsonResponse
    {
        // Validate Basic Auth
        if (!$this->validateBasicAuth($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Optional organisation UUID for filtering user integrations
        $organisationUuid = $request->query->get('organisation_uuid');
        $workflowUserId = $request->query->get('workflow_user_id');
        $toolTypeFilter = $request->query->get('tool_type'); // CSV: "jira,confluence" or single: "sharepoint"
        $executionId = $request->query->get('execution_id');

        $skills = [];

        // Parse tool_type filter
        $toolTypes = $toolTypeFilter ? array_map('trim', explode(',', $toolTypeFilter)) : [];

        // If organisation is specified, include user integrations
        $organisation = null;
        if ($organisationUuid) {
            $organisation = $this->organisationRepository->findOneBy(['uuid' => $organisationUuid]);
            if (!$organisation) {
                return $this->json(['error' => 'Organisation not found'], Response::HTTP_NOT_FOUND);
            }

            $skills = array_merge($skills, $this->getUserIntegrationSkills($organisation, $workflowUserId, $toolTypes));
        }

        // Include system integrations if requested
        if (empty($toolTypes) || in_array('system', $toolTypes, true) || $this->hasSystemTypeInFilter($toolTypes)) {
            $skills = array_merge($skills, $this->getSystemIntegrationSkills($toolTypes));
        }

        // Log API access with execution_id
        if ($organisation) {
            $this->auditLogService->logWithOrganisation(
                'api.get_skills',
                $organisation,
                null,
                [
                    'workflow_user_id' => $workflowUserId,
                    'tool_types' => $toolTypes,
                    'skills_count' => count($skills),
                ],
                $executionId
            );
        }

        return $this->json(['skills' => $skills]);
    }

    private function getUserIntegrationSkills(Organisation $organisation, ?string $workflowUserId, array $toolTypes): array
    {
        $skills = [];

        // Security: Require workflow_user_id for user integrations to prevent cross-user data leakage
        if ($workflowUserId === null) {
            return $skills;
        }

        // Get all configs for this user using proper UserOrganisation join filtering
        $allUserConfigs = $this->integrationConfigRepository->findByOrganisationAndWorkflowUser(
            $organisation,
            $workflowUserId
        );

        // Get user integrations
        foreach ($this->integrationRegistry->getUserIntegrations() as $integration) {
            $integrationType = $integration->getType();

            // Apply tool_type filter
            if (!empty($toolTypes) && !in_array($integrationType, $toolTypes, true)) {
                continue;
            }

            // Filter configs by integration type and active status
            $configs = array_filter(
                $allUserConfigs,
                fn($config) => $config->getIntegrationType() === $integrationType && $config->isActive()
            );

            // Create skill entry for each configured instance
            foreach ($configs as $config) {
                $skillData = [
                    'type' => $integrationType,
                    'name' => $integration->getName(),
                    'instance_id' => $config->getId(),
                    'instance_name' => $config->getName(), // The friendly name from the form
                ];

                // Add system prompt if this is a personalized skill
                if ($integration instanceof PersonalizedSkillInterface) {
                    try {
                        $skillData['system_prompt'] = $integration->getSystemPrompt($config);
                    } catch (\Exception $e) {
                        $this->logger->error('Failed to get system prompt for {type} instance {id}: {error}', [
                            'type' => $integrationType,
                            'id' => $config->getId(),
                            'error' => $e->getMessage(),
                        ]);
                        $skillData['system_prompt'] = null;
                        $skillData['system_prompt_error'] = 'Failed to generate system prompt. The integration may need to be reconnected.';
                    }
                }

                $skills[] = $skillData;
            }
        }

        return $skills;
    }

    private function getSystemIntegrationSkills(array $toolTypes): array
    {
        $skills = [];

        foreach ($this->integrationRegistry->getSystemIntegrations() as $integration) {
            $integrationType = $integration->getType();

            // System integrations have type prefix "system."
            // Extract the base type for filtering (e.g., "system.share_file" -> "share_file")
            $baseType = str_replace('system.', '', $integrationType);

            // Apply tool_type filter
            if (!empty($toolTypes)) {
                $shouldInclude = in_array('system', $toolTypes, true)
                    || in_array($integrationType, $toolTypes, true)
                    || in_array($baseType, $toolTypes, true);

                if (!$shouldInclude) {
                    continue;
                }
            }

            $skillData = [
                'type' => $integrationType,
                'name' => $integration->getName(),
                'instance_id' => null, // System tools don't have instances
                'instance_name' => null,
            ];

            // Add system prompt if this is a personalized skill (system tools don't implement this)
            if ($integration instanceof PersonalizedSkillInterface) {
                $skillData['system_prompt'] = $integration->getSystemPrompt();
            }

            $skills[] = $skillData;
        }

        return $skills;
    }

    private function hasSystemTypeInFilter(array $toolTypes): bool
    {
        foreach ($toolTypes as $type) {
            if (str_starts_with($type, 'system.')) {
                return true;
            }
        }
        return false;
    }

    private function validateBasicAuth(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $credentials = base64_decode(substr($authHeader, 6));
        [$username, $password] = explode(':', $credentials, 2);

        return $username === $this->apiAuthUser && $password === $this->apiAuthPassword;
    }
}
