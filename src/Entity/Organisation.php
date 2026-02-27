<?php

namespace App\Entity;

use App\Entity\UserOrganisation;
use App\Repository\OrganisationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrganisationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Organisation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::GUID, unique: true)]
    private ?string $uuid = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: UserOrganisation::class, mappedBy: 'organisation', cascade: ['persist', 'remove'])]
    private Collection $userOrganisations;

    /**
     * @var Collection<int, User> Cached collection of users
     */
    private Collection $users;

    #[ORM\OneToMany(targetEntity: AuditLog::class, mappedBy: 'organisation')]
    private Collection $auditLogs;

    #[ORM\OneToMany(targetEntity: IntegrationConfig::class, mappedBy: 'organisation')]
    private Collection $integrationConfigs;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $webhookType = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $tenantType = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $workflowUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedN8nApiKey = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $orgMcpServerUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedOrgMcpAuthHeader = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedWebhookAuthHeader = null;

    public function __construct()
    {
        $this->userOrganisations = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->auditLogs = new ArrayCollection();
        $this->integrationConfigs = new ArrayCollection();
        $this->uuid = Uuid::v4()->toRfc4122();
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

    public function getUsers(): Collection
    {
        $users = new ArrayCollection();
        foreach ($this->userOrganisations as $userOrg) {
            $users->add($userOrg->getUser());
        }
        return $users;
    }

    /**
     * @return Collection<int, UserOrganisation>
     */
    public function getUserOrganisations(): Collection
    {
        return $this->userOrganisations;
    }

    public function addUser(User $user): static
    {
        // Check if already exists
        foreach ($this->userOrganisations as $userOrg) {
            if ($userOrg->getUser() === $user) {
                return $this;
            }
        }

        $userOrganisation = new UserOrganisation();
        $userOrganisation->setUser($user);
        $userOrganisation->setOrganisation($this);
        $this->userOrganisations->add($userOrganisation);

        return $this;
    }

    public function removeUser(User $user): static
    {
        foreach ($this->userOrganisations as $userOrg) {
            if ($userOrg->getUser() === $user) {
                $this->userOrganisations->removeElement($userOrg);
                break;
            }
        }
        return $this;
    }

    public function addUserOrganisation(UserOrganisation $userOrganisation): static
    {
        if (!$this->userOrganisations->contains($userOrganisation)) {
            $this->userOrganisations->add($userOrganisation);
            $userOrganisation->setOrganisation($this);
        }
        return $this;
    }

    public function removeUserOrganisation(UserOrganisation $userOrganisation): static
    {
        if ($this->userOrganisations->removeElement($userOrganisation)) {
            if ($userOrganisation->getOrganisation() === $this) {
                $userOrganisation->setOrganisation(null);
            }
        }
        return $this;
    }

    public function getAuditLogs(): Collection
    {
        return $this->auditLogs;
    }


    public function getIntegrationConfigs(): Collection
    {
        return $this->integrationConfigs;
    }

    public function addIntegrationConfig(IntegrationConfig $integrationConfig): static
    {
        if (!$this->integrationConfigs->contains($integrationConfig)) {
            $this->integrationConfigs->add($integrationConfig);
            $integrationConfig->setOrganisation($this);
        }
        return $this;
    }

    public function removeIntegrationConfig(IntegrationConfig $integrationConfig): static
    {
        if ($this->integrationConfigs->removeElement($integrationConfig)) {
            if ($integrationConfig->getOrganisation() === $this) {
                $integrationConfig->setOrganisation(null);
            }
        }
        return $this;
    }

    public function getWebhookType(): ?string
    {
        return $this->webhookType;
    }

    public function setWebhookType(?string $webhookType): static
    {
        $this->webhookType = $webhookType;
        return $this;
    }

    public function getTenantType(): ?string
    {
        return $this->tenantType;
    }

    public function setTenantType(?string $tenantType): static
    {
        $this->tenantType = $tenantType;
        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $webhookUrl): static
    {
        $this->webhookUrl = $webhookUrl;
        return $this;
    }

    public function getWorkflowUrl(): ?string
    {
        return $this->workflowUrl;
    }

    public function setWorkflowUrl(?string $workflowUrl): static
    {
        $this->workflowUrl = $workflowUrl;
        return $this;
    }

    public function getEncryptedN8nApiKey(): ?string
    {
        return $this->encryptedN8nApiKey;
    }

    public function setEncryptedN8nApiKey(?string $encryptedN8nApiKey): static
    {
        $this->encryptedN8nApiKey = $encryptedN8nApiKey;
        return $this;
    }

    public function getOrgMcpServerUrl(): ?string
    {
        return $this->orgMcpServerUrl;
    }

    public function setOrgMcpServerUrl(?string $orgMcpServerUrl): static
    {
        $this->orgMcpServerUrl = $orgMcpServerUrl;
        return $this;
    }

    public function getEncryptedOrgMcpAuthHeader(): ?string
    {
        return $this->encryptedOrgMcpAuthHeader;
    }

    public function setEncryptedOrgMcpAuthHeader(?string $encryptedOrgMcpAuthHeader): static
    {
        $this->encryptedOrgMcpAuthHeader = $encryptedOrgMcpAuthHeader;
        return $this;
    }

    public function getEncryptedWebhookAuthHeader(): ?string
    {
        return $this->encryptedWebhookAuthHeader;
    }

    public function setEncryptedWebhookAuthHeader(?string $encryptedWebhookAuthHeader): static
    {
        $this->encryptedWebhookAuthHeader = $encryptedWebhookAuthHeader;
        return $this;
    }

    public function getIntegrationApiUrl(): string
    {
        return sprintf('/api/integrations/%s', $this->uuid);
    }
}
