<?php

namespace App\Service\ScheduledTask;

use App\Entity\Organisation;
use App\Entity\ScheduledTask;
use App\Entity\User;
use App\Entity\UserOrganisation;

interface WebhookPayloadBuilderInterface
{
    public function supports(string $tenantType): bool;

    /**
     * @return array{headers: array<string, string>, body: array<string, mixed>}
     */
    public function buildPayload(
        ScheduledTask $task,
        Organisation $org,
        User $user,
        UserOrganisation $userOrg,
        string $webhookAuthHeader,
    ): array;
}
