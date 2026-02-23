<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organisation;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\IntegrationConfigRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class DashboardStatsService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly IntegrationConfigRepository $integrationConfigRepository,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(Organisation $organisation, User $user, ?string $workflowUserId): array
    {
        $orgId = $organisation->getId();
        $userId = $user->getId();

        if ($orgId === null || $userId === null) {
            return $this->emptyStats();
        }

        $cacheKey = sprintf('dashboard_stats_%d_%d', $orgId, $userId);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($orgId, $userId, $workflowUserId, $organisation): array {
            $item->expiresAfter(self::CACHE_TTL);

            $since = new \DateTime('-30 days');

            $agentSessions = $this->auditLogRepository->countDistinctExecutionIds($orgId, $userId, $workflowUserId, $since);

            $toolExecCounts = $this->auditLogRepository->countToolExecutions($orgId, $userId, $workflowUserId, $since);

            $successRate = null;
            if ($toolExecCounts['started'] > 0) {
                $successRate = round(($toolExecCounts['completed'] / $toolExecCounts['started']) * 100, 1);
            }

            $apiCalls = $this->auditLogRepository->countApiCalls($orgId, $userId, $workflowUserId, $since);
            $promptActivity = $this->auditLogRepository->countPromptActivity($orgId, $userId, $workflowUserId, $since);

            $activeSkills = count(
                $this->integrationConfigRepository->findByOrganisationAndWorkflowUser($organisation, $workflowUserId)
            );

            $toolTypesUsed = $this->auditLogRepository->countUniqueToolTypes($orgId, $userId, $workflowUserId, $since);
            $topTools = $this->auditLogRepository->findTopTools($orgId, $userId, $workflowUserId, $since);

            return [
                'agent_sessions' => $agentSessions,
                'tool_executions' => $toolExecCounts['started'],
                'tool_success_rate' => $successRate,
                'api_calls' => $apiCalls,
                'prompt_activity' => $promptActivity,
                'active_skills' => $activeSkills,
                'tool_types_used' => $toolTypesUsed,
                'top_tools' => $topTools,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(): array
    {
        return [
            'agent_sessions' => 0,
            'tool_executions' => 0,
            'tool_success_rate' => null,
            'api_calls' => 0,
            'prompt_activity' => 0,
            'active_skills' => 0,
            'tool_types_used' => 0,
            'top_tools' => [],
        ];
    }
}
