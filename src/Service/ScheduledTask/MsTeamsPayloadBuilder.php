<?php

namespace App\Service\ScheduledTask;

use App\Entity\Organisation;
use App\Entity\ScheduledTask;
use App\Entity\User;
use App\Entity\UserOrganisation;
use Symfony\Component\Uid\Uuid;

class MsTeamsPayloadBuilder implements WebhookPayloadBuilderInterface
{
    public function supports(string $tenantType): bool
    {
        return $tenantType === 'ms_teams';
    }

    public function buildPayload(
        ScheduledTask $task,
        Organisation $org,
        User $user,
        UserOrganisation $userOrg,
        string $webhookAuthHeader,
    ): array {
        $now = new \DateTime();
        $conversationId = 'a:' . Uuid::v4()->toRfc4122();
        $messageId = (string) (int) ($now->format('U') . $now->format('v'));

        return [
            'headers' => [
                'authorization' => $webhookAuthHeader,
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'text' => $task->getPrompt(),
                'textFormat' => 'plain',
                'attachments' => [
                    [
                        'contentType' => 'text/html',
                        'content' => '<p>' . htmlspecialchars((string) $task->getPrompt()) . '</p>',
                    ],
                ],
                'type' => 'message',
                'timestamp' => $now->format('Y-m-d\TH:i:s.v\Z'),
                'localTimestamp' => $now->format('Y-m-d\TH:i:s.v\Z'),
                'id' => $messageId,
                'channelId' => 'msteams',
                'serviceUrl' => 'https://smba.trafficmanager.net/de/' . $org->getUuid() . '/',
                'from' => [
                    'id' => '29:scheduled-task-' . ($task->getUuid() ?? ''),
                    'name' => $user->getName() ?? $user->getEmail(),
                    'aadObjectId' => $userOrg->getWorkflowUserId() ?? '',
                ],
                'conversation' => [
                    'conversationType' => 'personal',
                    'tenantId' => $org->getUuid(),
                    'id' => $conversationId,
                ],
                'recipient' => [
                    'id' => '28:scheduled-task-worker',
                    'name' => 'Workoflow Scheduled Task',
                ],
                'entities' => [
                    [
                        'locale' => 'en-US',
                        'country' => 'US',
                        'platform' => 'Web',
                        'timezone' => 'Europe/Berlin',
                        'type' => 'clientInfo',
                    ],
                ],
                'channelData' => [
                    'tenant' => [
                        'id' => $org->getUuid(),
                    ],
                ],
                'locale' => 'en-US',
                'localTimezone' => 'Europe/Berlin',
                'callerId' => 'urn:botframework:azure',
                'custom' => [
                    'isThreadReply' => false,
                    'threadMessageId' => null,
                    'originalThreadMessage' => null,
                    'user' => [
                        'id' => '29:scheduled-task-' . ($task->getUuid() ?? ''),
                        'name' => $user->getName() ?? $user->getEmail(),
                        'aadObjectId' => $userOrg->getWorkflowUserId() ?? '',
                        'email' => $user->getEmail(),
                        'userPrincipalName' => $user->getEmail(),
                        'tenantId' => $org->getUuid(),
                        'userRole' => 'user',
                        'teamContext' => null,
                        'meetingContext' => null,
                        'fetchedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
                    ],
                    'conversationDetails' => [
                        'conversationType' => 'personal',
                        'conversationId' => $conversationId,
                        'tenantId' => $org->getUuid(),
                        'isGroup' => false,
                    ],
                    'enrichmentTimestamp' => $now->format('Y-m-d\TH:i:s.v\Z'),
                    'session' => [
                        'sessionId' => Uuid::v4()->toRfc4122(),
                        'createdAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
                        'messageCount' => 1,
                        'isNewSession' => true,
                    ],
                ],
            ],
        ];
    }
}
