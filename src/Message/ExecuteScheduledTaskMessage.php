<?php

namespace App\Message;

class ExecuteScheduledTaskMessage
{
    public function __construct(
        private int $scheduledTaskId,
        private int $executionId,
        private string $trigger,
    ) {
    }

    public function getScheduledTaskId(): int
    {
        return $this->scheduledTaskId;
    }

    public function getExecutionId(): int
    {
        return $this->executionId;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }
}
