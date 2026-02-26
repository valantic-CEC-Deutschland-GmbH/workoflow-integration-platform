<?php

namespace App\Command;

use App\Repository\ScheduledTaskRepository;
use App\Service\ScheduledTask\ScheduledTaskExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scheduled-task:worker',
    description: 'Persistent worker that executes due scheduled tasks',
)]
class ScheduledTaskWorkerCommand extends Command
{
    private bool $shouldStop = false;

    public function __construct(
        private ScheduledTaskRepository $taskRepository,
        private ScheduledTaskExecutor $executor,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run once and exit (for testing)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $once = $input->getOption('once');

        $io->info('Scheduled task worker started');

        // Register signal handlers for graceful shutdown
        if (\function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($io): void {
                $io->info('Received SIGTERM, shutting down gracefully...');
                $this->shouldStop = true;
            });
            pcntl_signal(SIGINT, function () use ($io): void {
                $io->info('Received SIGINT, shutting down gracefully...');
                $this->shouldStop = true;
            });
        }

        do {
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->shouldStop) {
                break;
            }

            try {
                // Clear entity manager to avoid stale data
                $this->entityManager->clear();

                $now = new \DateTime();
                $dueTasks = $this->taskRepository->findDueForExecution($now);

                if (count($dueTasks) > 0) {
                    $io->info(sprintf('Found %d due task(s)', count($dueTasks)));
                }

                foreach ($dueTasks as $task) {
                    if ($this->shouldStop) { // @phpstan-ignore if.alwaysFalse (modified by signal handler)
                        break;
                    }

                    $io->info(sprintf('Executing task "%s" (UUID: %s)', $task->getName(), $task->getUuid()));

                    try {
                        $execution = $this->executor->execute($task, 'scheduled');
                        $io->info(sprintf(
                            'Task "%s" executed: %s (HTTP %s, %dms)',
                            $task->getName(),
                            $execution->getStatus(),
                            $execution->getHttpStatusCode() ?? 'N/A',
                            $execution->getDuration() ?? 0,
                        ));
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to execute scheduled task', [
                            'task_uuid' => $task->getUuid(),
                            'error' => $e->getMessage(),
                        ]);
                        $io->error(sprintf('Task "%s" failed: %s', $task->getName(), $e->getMessage()));
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Worker loop error', ['error' => $e->getMessage()]);
                $io->error('Worker loop error: ' . $e->getMessage());
            }

            if ($once) {
                break;
            }

            // Sleep 60 seconds between checks
            sleep(60);
        } while (!$this->shouldStop); // @phpstan-ignore booleanNot.alwaysTrue (modified by signal handler)

        $io->info('Scheduled task worker stopped');

        return Command::SUCCESS;
    }
}
