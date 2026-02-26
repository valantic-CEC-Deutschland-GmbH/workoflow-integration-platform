<?php

namespace App\Service\ScheduledTask;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskExecution;
use App\Service\AuditLogService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ScheduledTaskExecutor
{
    /** @var iterable<WebhookPayloadBuilderInterface> */
    private iterable $payloadBuilders;

    /**
     * @param iterable<WebhookPayloadBuilderInterface> $payloadBuilders
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
        iterable $payloadBuilders,
    ) {
        $this->payloadBuilders = $payloadBuilders;
    }

    public function execute(ScheduledTask $task, string $trigger): ScheduledTaskExecution
    {
        $execution = new ScheduledTaskExecution();
        $execution->setScheduledTask($task);
        $execution->setTrigger($trigger);

        $startTime = microtime(true);

        try {
            $org = $task->getOrganisation();
            $user = $task->getUser();

            if ($org === null || $user === null) {
                throw new \RuntimeException('Task has no organisation or user');
            }

            $webhookUrl = $org->getWebhookUrl();
            if (empty($webhookUrl)) {
                throw new \RuntimeException('Organisation has no webhook URL configured');
            }

            $tenantType = $org->getTenantType();
            if (empty($tenantType)) {
                throw new \RuntimeException('Organisation has no tenant type configured');
            }

            // Decrypt auth header
            $webhookAuthHeader = '';
            $encryptedHeader = $org->getEncryptedWebhookAuthHeader();
            if (!empty($encryptedHeader)) {
                $webhookAuthHeader = $this->encryptionService->decrypt($encryptedHeader);
            }

            // Find the user's organisation relationship
            $userOrg = null;
            foreach ($user->getUserOrganisations() as $uo) {
                if ($uo->getOrganisation() === $org) {
                    $userOrg = $uo;
                    break;
                }
            }

            if ($userOrg === null) {
                throw new \RuntimeException('User is not a member of the task organisation');
            }

            // Find matching payload builder
            $builder = null;
            foreach ($this->payloadBuilders as $payloadBuilder) {
                if ($payloadBuilder->supports($tenantType)) {
                    $builder = $payloadBuilder;
                    break;
                }
            }

            if ($builder === null) {
                throw new \RuntimeException(sprintf('No payload builder found for tenant type "%s"', $tenantType));
            }

            $payload = $builder->buildPayload($task, $org, $user, $userOrg, $webhookAuthHeader);

            // Ensure webhook URL has protocol
            $url = $webhookUrl;
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'https://' . $url;
            }

            $response = $this->httpClient->request('POST', $url, [
                'headers' => $payload['headers'],
                'json' => $payload['body'],
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            $execution->setHttpStatusCode($statusCode);
            $execution->setOutput($responseBody);

            if ($statusCode >= 200 && $statusCode < 300) {
                $execution->setStatus('success');
            } else {
                $execution->setStatus('failed');
                $execution->setErrorMessage(sprintf('HTTP %d response', $statusCode));
            }
        } catch (\Throwable $e) {
            $execution->setStatus('failed');
            $execution->setErrorMessage($e->getMessage());
            $this->logger->error('Scheduled task execution failed', [
                'task_uuid' => $task->getUuid(),
                'error' => $e->getMessage(),
            ]);
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);
        $execution->setDuration($duration);

        // Update task
        $task->setLastExecutionAt(new \DateTime());
        $task->computeNextExecutionAt();

        $this->entityManager->persist($execution);
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation(
            'scheduled_task.executed',
            $task->getOrganisation(),
            $task->getUser(),
            [
                'task_uuid' => $task->getUuid(),
                'task_name' => $task->getName(),
                'trigger' => $trigger,
                'status' => $execution->getStatus(),
                'duration_ms' => $duration,
            ],
        );

        return $execution;
    }
}
