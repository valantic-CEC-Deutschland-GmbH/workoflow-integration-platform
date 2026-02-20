<?php

namespace App\Entity;

use App\Repository\PromptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PromptRepository::class)]
#[ORM\Table(name: 'prompt')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_prompt_import_key_org', columns: ['import_key', 'organisation_id'])]
#[ORM\Index(name: 'idx_prompt_scope', columns: ['scope'])]
#[ORM\Index(name: 'idx_prompt_category', columns: ['category'])]
#[ORM\Index(name: 'idx_prompt_deleted', columns: ['deleted_at'])]
class Prompt
{
    public const SCOPE_PERSONAL = 'personal';
    public const SCOPE_ORGANISATION = 'organisation';

    public const CATEGORY_PROJECT_MANAGER = 'project_manager';
    public const CATEGORY_DEVELOPER = 'developer';
    public const CATEGORY_QA_ENGINEER = 'qa_engineer';
    public const CATEGORY_DEVOPS = 'devops';
    public const CATEGORY_DESIGNER = 'designer';
    public const CATEGORY_BUSINESS_ANALYST = 'business_analyst';
    public const CATEGORY_SCRUM_MASTER = 'scrum_master';
    public const CATEGORY_PRODUCT_OWNER = 'product_owner';
    public const CATEGORY_TECH_LEAD = 'tech_lead';
    public const CATEGORY_SOLUTION_ARCHITECT = 'solution_architect';
    public const CATEGORY_DATA_ENGINEER = 'data_engineer';
    public const CATEGORY_SECURITY_ENGINEER = 'security_engineer';
    public const CATEGORY_SUPPORT_ENGINEER = 'support_engineer';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::GUID, unique: true)]
    private ?string $uuid = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $importKey = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private ?string $category = null;

    #[ORM\Column(length: 20)]
    private string $scope = self::SCOPE_PERSONAL;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organisation $organisation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $deletedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    /** @var Collection<int, PromptComment> */
    #[ORM\OneToMany(targetEntity: PromptComment::class, mappedBy: 'prompt', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $comments;

    /** @var Collection<int, PromptUpvote> */
    #[ORM\OneToMany(targetEntity: PromptUpvote::class, mappedBy: 'prompt', cascade: ['persist', 'remove'])]
    private Collection $upvotes;

    public function __construct()
    {
        $this->uuid = Uuid::v4()->toRfc4122();
        $this->comments = new ArrayCollection();
        $this->upvotes = new ArrayCollection();
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

    public function getImportKey(): ?string
    {
        return $this->importKey;
    }

    public function setImportKey(?string $importKey): static
    {
        $this->importKey = $importKey;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function isPersonal(): bool
    {
        return $this->scope === self::SCOPE_PERSONAL;
    }

    public function isOrganisation(): bool
    {
        return $this->scope === self::SCOPE_ORGANISATION;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
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

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeInterface $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }

    public function setDeletedBy(?User $deletedBy): static
    {
        $this->deletedBy = $deletedBy;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
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

    /**
     * @return Collection<int, PromptComment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(PromptComment $comment): static
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setPrompt($this);
        }
        return $this;
    }

    public function removeComment(PromptComment $comment): static
    {
        if ($this->comments->removeElement($comment)) {
            if ($comment->getPrompt() === $this) {
                $comment->setPrompt(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, PromptUpvote>
     */
    public function getUpvotes(): Collection
    {
        return $this->upvotes;
    }

    public function getUpvoteCount(): int
    {
        return $this->upvotes->count();
    }

    public function addUpvote(PromptUpvote $upvote): static
    {
        if (!$this->upvotes->contains($upvote)) {
            $this->upvotes->add($upvote);
            $upvote->setPrompt($this);
        }
        return $this;
    }

    public function removeUpvote(PromptUpvote $upvote): static
    {
        if ($this->upvotes->removeElement($upvote)) {
            if ($upvote->getPrompt() === $this) {
                $upvote->setPrompt(null);
            }
        }
        return $this;
    }

    public function hasUserUpvoted(User $user): bool
    {
        foreach ($this->upvotes as $upvote) {
            if ($upvote->getUser() === $user) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        // Sorted alphabetically by English translated name
        return [
            self::CATEGORY_BUSINESS_ANALYST => 'prompt.category_business_analyst',
            self::CATEGORY_DATA_ENGINEER => 'prompt.category_data_engineer',
            self::CATEGORY_DESIGNER => 'prompt.category_designer',
            self::CATEGORY_DEVELOPER => 'prompt.category_developer',
            self::CATEGORY_DEVOPS => 'prompt.category_devops',
            self::CATEGORY_PRODUCT_OWNER => 'prompt.category_product_owner',
            self::CATEGORY_PROJECT_MANAGER => 'prompt.category_project_manager',
            self::CATEGORY_QA_ENGINEER => 'prompt.category_qa_engineer',
            self::CATEGORY_SCRUM_MASTER => 'prompt.category_scrum_master',
            self::CATEGORY_SECURITY_ENGINEER => 'prompt.category_security_engineer',
            self::CATEGORY_SOLUTION_ARCHITECT => 'prompt.category_solution_architect',
            self::CATEGORY_SUPPORT_ENGINEER => 'prompt.category_support_engineer',
            self::CATEGORY_TECH_LEAD => 'prompt.category_tech_lead',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getScopes(): array
    {
        return [
            self::SCOPE_PERSONAL => 'prompt.scope_personal',
            self::SCOPE_ORGANISATION => 'prompt.scope_organisation',
        ];
    }
}
