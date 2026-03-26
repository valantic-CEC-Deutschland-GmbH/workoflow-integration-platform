<?php

namespace App\MessageHandler;

use App\Entity\ScheduledTaskExecution;
use App\Message\ExecuteScheduledTaskMessage;
use App\Repository\ScheduledTaskRepository;
use App\Service\ScheduledTask\ScheduledTaskExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExecuteScheduledTaskHandler
{
    public function __construct(
        private ScheduledTaskRepository $taskRepository,
        private ScheduledTaskExecutor $executor,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteScheduledTaskMessage $message): void
    {
        $task = $this->taskRepository->find($message->getScheduledTaskId());
        if ($task === null) {
            $this->logger->warning('Scheduled task not found for async execution', [
                'task_id' => $message->getScheduledTaskId(),
            ]);
            return;
        }

        $execution = $this->entityManager->getRepository(ScheduledTaskExecution::class)
            ->find($message->getExecutionId());
        if ($execution === null) {
            $this->logger->warning('Execution record not found for async execution', [
                'execution_id' => $message->getExecutionId(),
            ]);
            return;
        }

        $this->executor->executeAsync($task, $execution);
    }
}
