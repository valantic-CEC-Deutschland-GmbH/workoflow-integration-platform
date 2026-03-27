<?php

namespace App\Service\ScheduledTask;

use App\Entity\Organisation;
use App\Entity\ScheduledTask;
use App\Entity\User;
use App\Entity\UserOrganisation;
use Symfony\Component\Uid\Uuid;

class CommonPayloadBuilder implements WebhookPayloadBuilderInterface
{
    public function supports(string $tenantType): bool
    {
        return $tenantType === 'common';
    }

    public function buildPayload(
        ScheduledTask $task,
        Organisation $org,
        User $user,
        UserOrganisation $userOrg,
        string $webhookAuthHeader,
    ): array {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $sessionId = Uuid::v4()->toRfc4122();
        $workflowUserId = $userOrg->getWorkflowUserId() ?? '';

        return [
            'headers' => [
                'authorization' => $webhookAuthHeader,
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'text' => $task->getPrompt(),
                'type' => 'message',
                'timestamp' => $now->format('Y-m-d\TH:i:s.v\Z'),
                'from' => [
                    'id' => $workflowUserId,
                    'name' => $user->getName() ?? $user->getEmail(),
                    'aadObjectId' => $workflowUserId,
                ],
                'conversation' => [
                    'tenantId' => $org->getUuid(),
                    'conversationType' => 'personal',
                    'id' => 'scheduled:' . ($task->getUuid() ?? ''),
                ],
                'custom' => [
                    'isThreadReply' => false,
                    'threadMessageId' => null,
                    'user' => [
                        'aadObjectId' => $workflowUserId,
                        'email' => $user->getEmail(),
                        'name' => $user->getName() ?? $user->getEmail(),
                        'tenantId' => $org->getUuid(),
                    ],
                    'conversationDetails' => [
                        'conversationType' => 'personal',
                        'tenantId' => $org->getUuid(),
                        'isGroup' => false,
                    ],
                    'session' => [
                        'sessionId' => $sessionId,
                        'createdAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
                        'messageCount' => 1,
                        'isNewSession' => true,
                    ],
                ],
                'scheduledTask' => [
                    'uuid' => $task->getUuid(),
                    'name' => $task->getName(),
                ],
            ],
        ];
    }
}
