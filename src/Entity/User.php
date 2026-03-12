<?php

namespace App\Entity;

use App\Entity\UserOrganisation;
use App\Integration\ToolCategory;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_MEMBER = 'ROLE_MEMBER';
    public const ROLE_USER = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $tokenExpiresAt = null;

    #[ORM\OneToMany(targetEntity: UserOrganisation::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $userOrganisations;

    /**
     * @var Collection<int, Organisation> Cached collection of organisations
     */
    private Collection $organisations;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;


    #[ORM\OneToMany(targetEntity: IntegrationConfig::class, mappedBy: 'user')]
    private Collection $integrationConfigs;

    #[ORM\OneToMany(targetEntity: AuditLog::class, mappedBy: 'user')]
    private Collection $auditLogs;

    #[ORM\OneToMany(targetEntity: UserChannel::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $userChannels;

    #[ORM\Column(length: 20, options: ['default' => 'standard'])]
    private string $toolAccessMode = 'read_only';

    public function __construct()
    {
        $this->integrationConfigs = new ArrayCollection();
        $this->auditLogs = new ArrayCollection();
        $this->userOrganisations = new ArrayCollection();
        $this->organisations = new ArrayCollection();
        $this->userChannels = new ArrayCollection();
        $this->roles = [self::ROLE_USER];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_USER;
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void
    {
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

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;
        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): static
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeInterface $tokenExpiresAt): static
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
        return $this;
    }

    public function isTokenExpired(): bool
    {
        if (!$this->tokenExpiresAt) {
            return true;
        }

        return $this->tokenExpiresAt < new \DateTime();
    }

    /**
     * @return Collection<int, Organisation>
     */
    public function getOrganisations(): Collection
    {
        $organisations = new ArrayCollection();
        foreach ($this->userOrganisations as $userOrg) {
            $organisations->add($userOrg->getOrganisation());
        }
        return $organisations;
    }

    /**
     * @return Collection<int, UserOrganisation>
     */
    public function getUserOrganisations(): Collection
    {
        return $this->userOrganisations;
    }

    public function addOrganisation(Organisation $organisation): static
    {
        // Check if already exists
        foreach ($this->userOrganisations as $userOrg) {
            if ($userOrg->getOrganisation() === $organisation) {
                return $this;
            }
        }

        $userOrganisation = new UserOrganisation();
        $userOrganisation->setUser($this);
        $userOrganisation->setOrganisation($organisation);
        $this->userOrganisations->add($userOrganisation);

        return $this;
    }

    public function removeOrganisation(Organisation $organisation): static
    {
        foreach ($this->userOrganisations as $userOrg) {
            if ($userOrg->getOrganisation() === $organisation) {
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
            $userOrganisation->setUser($this);
        }
        return $this;
    }

    public function removeUserOrganisation(UserOrganisation $userOrganisation): static
    {
        if ($this->userOrganisations->removeElement($userOrganisation)) {
            if ($userOrganisation->getUser() === $this) {
                $userOrganisation->setUser(null);
            }
        }
        return $this;
    }

    /**
     * Get current organisation from session or fallback to first one
     */
    public function getCurrentOrganisation(?int $sessionOrganisationId = null): ?Organisation
    {
        // If a session organisation ID is provided, try to find it in user's organisations
        if ($sessionOrganisationId !== null) {
            foreach ($this->userOrganisations as $userOrg) {
                if ($userOrg->getOrganisation()->getId() === $sessionOrganisationId) {
                    return $userOrg->getOrganisation();
                }
            }
        }

        // Fallback to first organisation
        $firstUserOrg = $this->userOrganisations->first();
        return $firstUserOrg ? $firstUserOrg->getOrganisation() : null;
    }

    /**
     * Get current UserOrganisation from session or fallback to first one
     */
    public function getCurrentUserOrganisation(?int $sessionOrganisationId = null): ?UserOrganisation
    {
        // If a session organisation ID is provided, try to find it in user's organisations
        if ($sessionOrganisationId !== null) {
            foreach ($this->userOrganisations as $userOrg) {
                if ($userOrg->getOrganisation()->getId() === $sessionOrganisationId) {
                    return $userOrg;
                }
            }
        }

        // Fallback to first organisation
        return $this->userOrganisations->first() ?: null;
    }

    /**
     * Get primary organisation (first one) for backward compatibility
     */
    public function getOrganisation(): ?Organisation
    {
        // This method is kept for backward compatibility
        // In controllers, we'll use getCurrentOrganisation() instead
        $firstUserOrg = $this->userOrganisations->first();
        return $firstUserOrg ? $firstUserOrg->getOrganisation() : null;
    }

    /**
     * Set organisation (replaces all with this one) for backward compatibility
     */
    public function setOrganisation(?Organisation $organisation): static
    {
        $this->userOrganisations->clear();
        if ($organisation) {
            $this->addOrganisation($organisation);
        }
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


    public function getIntegrationConfigs(): Collection
    {
        return $this->integrationConfigs;
    }

    public function addIntegrationConfig(IntegrationConfig $integrationConfig): static
    {
        if (!$this->integrationConfigs->contains($integrationConfig)) {
            $this->integrationConfigs->add($integrationConfig);
            $integrationConfig->setUser($this);
        }
        return $this;
    }

    public function removeIntegrationConfig(IntegrationConfig $integrationConfig): static
    {
        if ($this->integrationConfigs->removeElement($integrationConfig)) {
            if ($integrationConfig->getUser() === $this) {
                $integrationConfig->setUser(null);
            }
        }
        return $this;
    }

    public function getAuditLogs(): Collection
    {
        return $this->auditLogs;
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function isMember(): bool
    {
        return in_array(self::ROLE_MEMBER, $this->roles, true) || !$this->isAdmin();
    }

    /**
     * @return Collection<int, UserChannel>
     */
    public function getUserChannels(): Collection
    {
        return $this->userChannels;
    }

    public function addUserChannel(UserChannel $userChannel): static
    {
        if (!$this->userChannels->contains($userChannel)) {
            $this->userChannels->add($userChannel);
            $userChannel->setUser($this);
        }
        return $this;
    }

    public function removeUserChannel(UserChannel $userChannel): static
    {
        if ($this->userChannels->removeElement($userChannel)) {
            if ($userChannel->getUser() === $this) {
                $userChannel->setUser(null);
            }
        }
        return $this;
    }

    /**
     * Get channels that this user belongs to
     * @return Collection<int, Channel>
     */
    public function getChannels(): Collection
    {
        $channels = new ArrayCollection();
        foreach ($this->userChannels as $userChannel) {
            if ($userChannel->getChannel()) {
                $channels->add($userChannel->getChannel());
            }
        }
        return $channels;
    }

    public function getToolAccessMode(): string
    {
        return $this->toolAccessMode;
    }

    public function setToolAccessMode(string $toolAccessMode): static
    {
        $this->toolAccessMode = $toolAccessMode;
        return $this;
    }

    /**
     * @return ToolCategory[]
     */
    public function getAllowedToolCategories(): array
    {
        return match ($this->toolAccessMode) {
            'read_only' => [ToolCategory::READ],
            'standard' => [ToolCategory::READ, ToolCategory::WRITE],
            'full' => [ToolCategory::READ, ToolCategory::WRITE, ToolCategory::DELETE],
            default => [ToolCategory::READ],
        };
    }
}
