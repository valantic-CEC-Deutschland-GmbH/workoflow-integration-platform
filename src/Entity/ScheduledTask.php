<?php

namespace App\Entity;

use App\Repository\ScheduledTaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ScheduledTaskRepository::class)]
#[ORM\Table(name: 'scheduled_task')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_scheduled_task_active_next', columns: ['active', 'next_execution_at'])]
class ScheduledTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::GUID, unique: true)]
    private ?string $uuid = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $prompt = null;

    #[ORM\Column(length: 20)]
    private string $frequency = 'manual';

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $executionTime = null;

    #[ORM\Column(nullable: true)]
    private ?int $weekday = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextExecutionAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastExecutionAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organisation $organisation = null;

    /** @var Collection<int, ScheduledTaskExecution> */
    #[ORM\OneToMany(targetEntity: ScheduledTaskExecution::class, mappedBy: 'scheduledTask', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['executedAt' => 'DESC'])]
    private Collection $executions;

    public function __construct()
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->executions = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPrompt(): ?string
    {
        return $this->prompt;
    }

    public function setPrompt(string $prompt): static
    {
        $this->prompt = $prompt;
        return $this;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function setFrequency(string $frequency): static
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function getExecutionTime(): ?string
    {
        return $this->executionTime;
    }

    public function setExecutionTime(?string $executionTime): static
    {
        $this->executionTime = $executionTime;
        return $this;
    }

    public function getWeekday(): ?int
    {
        return $this->weekday;
    }

    public function setWeekday(?int $weekday): static
    {
        $this->weekday = $weekday;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function getNextExecutionAt(): ?\DateTimeInterface
    {
        return $this->nextExecutionAt;
    }

    public function setNextExecutionAt(?\DateTimeInterface $nextExecutionAt): static
    {
        $this->nextExecutionAt = $nextExecutionAt;
        return $this;
    }

    public function getLastExecutionAt(): ?\DateTimeInterface
    {
        return $this->lastExecutionAt;
    }

    public function setLastExecutionAt(?\DateTimeInterface $lastExecutionAt): static
    {
        $this->lastExecutionAt = $lastExecutionAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getOrganisation(): ?Organisation
    {
        return $this->organisation;
    }

    public function setOrganisation(?Organisation $organisation): static
    {
        $this->organisation = $organisation;
        return $this;
    }

    /**
     * @return Collection<int, ScheduledTaskExecution>
     */
    public function getExecutions(): Collection
    {
        return $this->executions;
    }

    public function computeNextExecutionAt(): void
    {
        $now = new \DateTime();

        switch ($this->frequency) {
            case 'manual':
                $this->nextExecutionAt = null;
                return;

            case 'hourly':
                $next = new \DateTime();
                $next->modify('+1 hour');
                if ($this->executionTime !== null) {
                    $minutes = (int) substr($this->executionTime, 3, 2);
                    $next->setTime((int) $next->format('H'), $minutes);
                    if ($next <= $now) {
                        $next->modify('+1 hour');
                    }
                } else {
                    $next->setTime((int) $next->format('H'), 0);
                    if ($next <= $now) {
                        $next->modify('+1 hour');
                    }
                }
                $this->nextExecutionAt = $next;
                return;

            case 'daily':
                $next = $this->buildNextFromTime($now);
                if ($next <= $now) {
                    $next->modify('+1 day');
                }
                $this->nextExecutionAt = $next;
                return;

            case 'weekdays':
                $next = $this->buildNextFromTime($now);
                if ($next <= $now) {
                    $next->modify('+1 day');
                }
                // Skip weekends
                while ((int) $next->format('N') > 5) {
                    $next->modify('+1 day');
                }
                $this->nextExecutionAt = $next;
                return;

            case 'weekly':
                $next = $this->buildNextFromTime($now);
                $targetDay = $this->weekday ?? 1; // Default Monday
                $currentDay = (int) $next->format('w'); // 0=Sunday
                $daysUntil = ($targetDay - $currentDay + 7) % 7;
                if ($daysUntil === 0 && $next <= $now) {
                    $daysUntil = 7;
                }
                if ($daysUntil > 0) {
                    $next->modify("+{$daysUntil} days");
                }
                $this->nextExecutionAt = $next;
                return;
        }
    }

    private function buildNextFromTime(\DateTime $now): \DateTime
    {
        $next = clone $now;
        if ($this->executionTime !== null) {
            $parts = explode(':', $this->executionTime);
            $next->setTime((int) $parts[0], (int) ($parts[1] ?? 0));
        } else {
            $next->setTime(9, 0); // Default 09:00
        }

        return $next;
    }
}
