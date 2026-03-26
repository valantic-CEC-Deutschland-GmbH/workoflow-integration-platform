<?php

namespace App\Service\ScheduledTask;

use App\Entity\Organisation;
use App\Entity\ScheduledTask;
use App\Entity\User;
use App\Entity\UserOrganisation;

class WebPayloadBuilder implements WebhookPayloadBuilderInterface
{
    public function supports(string $tenantType): bool
    {
        return $tenantType === 'web';
    }

    public function buildPayload(
        ScheduledTask $task,
        Organisation $org,
        User $user,
        UserOrganisation $userOrg,
        string $webhookAuthHeader,
    ): array {
        return [
            'headers' => [
                'authorization' => $webhookAuthHeader,
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'text' => $task->getPrompt(),
                'type' => 'message',
                'timestamp' => (new \DateTime())->format('Y-m-d\TH:i:s.v\Z'),
                'from' => [
                    'id' => $userOrg->getWorkflowUserId() ?? '',
                    'name' => $user->getName() ?? $user->getEmail(),
                    'email' => $user->getEmail(),
                ],
                'organisation' => [
                    'id' => $org->getUuid(),
                    'name' => $org->getName(),
                ],
                'scheduledTask' => [
                    'uuid' => $task->getUuid(),
                    'name' => $task->getName(),
                ],
            ],
        ];
    }
}
