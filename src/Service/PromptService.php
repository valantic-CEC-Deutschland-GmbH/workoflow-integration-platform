<?php

namespace App\Service;

use App\Entity\Organisation;
use App\Entity\Prompt;
use App\Entity\PromptComment;
use App\Entity\PromptUpvote;
use App\Entity\User;
use App\Repository\PromptCommentRepository;
use App\Repository\PromptRepository;
use App\Repository\PromptUpvoteRepository;
use Doctrine\ORM\EntityManagerInterface;

class PromptService
{
    public function __construct(
        private PromptRepository $promptRepository,
        private PromptUpvoteRepository $upvoteRepository,
        private PromptCommentRepository $commentRepository,
        private EntityManagerInterface $entityManager,
        private AuditLogService $auditLogService,
    ) {
    }

    /**
     * Create a new prompt
     */
    public function createPrompt(
        User $user,
        Organisation $organisation,
        string $title,
        string $content,
        ?string $category,
        string $scope,
        ?string $description = null
    ): Prompt {
        $prompt = new Prompt();
        $prompt->setOwner($user);
        $prompt->setOrganisation($organisation);
        $prompt->setTitle($title);
        $prompt->setContent($content);
        $prompt->setCategory($category);
        $prompt->setScope($scope);
        $prompt->setDescription($description);

        $this->entityManager->persist($prompt);
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation('prompt.created', $organisation, $user, [
            'prompt_uuid' => $prompt->getUuid(),
            'title' => $prompt->getTitle(),
            'scope' => $scope,
            'category' => $category,
        ]);

        return $prompt;
    }

    /**
     * Update an existing prompt
     */
    public function updatePrompt(
        Prompt $prompt,
        User $user,
        string $title,
        string $content,
        ?string $category,
        string $scope,
        ?string $description = null
    ): Prompt {
        $prompt->setTitle($title);
        $prompt->setContent($content);
        $prompt->setCategory($category);
        $prompt->setScope($scope);
        $prompt->setDescription($description);

        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation('prompt.updated', $prompt->getOrganisation(), $user, [
            'prompt_uuid' => $prompt->getUuid(),
            'title' => $prompt->getTitle(),
            'scope' => $scope,
            'category' => $category,
        ]);

        return $prompt;
    }

    /**
     * Soft delete a prompt
     */
    public function softDelete(Prompt $prompt, User $deletedBy): void
    {
        $prompt->setDeletedAt(new \DateTime());
        $prompt->setDeletedBy($deletedBy);
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation('prompt.deleted', $prompt->getOrganisation(), $deletedBy, [
            'prompt_uuid' => $prompt->getUuid(),
            'title' => $prompt->getTitle(),
        ]);
    }

    /**
     * Restore a soft-deleted prompt (admin only)
     */
    public function restore(Prompt $prompt, User $restoredBy): void
    {
        $prompt->setDeletedAt(null);
        $prompt->setDeletedBy(null);
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation('prompt.restored', $prompt->getOrganisation(), $restoredBy, [
            'prompt_uuid' => $prompt->getUuid(),
            'title' => $prompt->getTitle(),
        ]);
    }

    /**
     * Toggle upvote for a prompt (organisation prompts only)
     * @return bool True if upvoted, false if removed
     */
    public function toggleUpvote(Prompt $prompt, User $user): bool
    {
        if ($prompt->getScope() !== Prompt::SCOPE_ORGANISATION) {
            throw new \LogicException('Cannot upvote personal prompts');
        }

        $existing = $this->upvoteRepository->findByPromptAndUser($prompt, $user);

        if ($existing !== null) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            $this->auditLogService->logWithOrganisation('prompt.upvote.removed', $prompt->getOrganisation(), $user, [
                'prompt_uuid' => $prompt->getUuid(),
            ]);

            return false;
        }

        $upvote = new PromptUpvote();
        $upvote->setPrompt($prompt);
        $upvote->setUser($user);

        $this->entityManager->persist($upvote);
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation('prompt.upvote.added', $prompt->getOrganisation(), $user, [
            'prompt_uuid' => $prompt->getUuid(),
        ]);

        return true;
    }

    /**
     * Add a comment to a prompt (organisation prompts only)
     */
    public function addComment(Prompt $prompt, User $user, string $content): PromptComment
    {
        if ($prompt->getScope() !== Prompt::SCOPE_ORGANISATION) {
            throw new \LogicException('Cannot comment on personal prompts');
        }

        $comment = new PromptComment();
        $comment->setPrompt($prompt);
        $comment->setUser($user);
        $comment->setContent($content);

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation('prompt.comment.added', $prompt->getOrganisation(), $user, [
            'prompt_uuid' => $prompt->getUuid(),
            'comment_id' => $comment->getId(),
        ]);

        return $comment;
    }

    /**
     * Delete a comment (by comment owner or admin)
     */
    public function deleteComment(PromptComment $comment, User $deletedBy): void
    {
        $prompt = $comment->getPrompt();
        $commentId = $comment->getId();

        $this->entityManager->remove($comment);
        $this->entityManager->flush();

        if ($prompt !== null) {
            $this->auditLogService->logWithOrganisation('prompt.comment.deleted', $prompt->getOrganisation(), $deletedBy, [
                'prompt_uuid' => $prompt->getUuid(),
                'comment_id' => $commentId,
            ]);
        }
    }

    /**
     * Check if user can edit a prompt
     * Organisation prompts: any org member can edit (wiki-style)
     * Personal prompts: only owner can edit
     */
    public function canEdit(Prompt $prompt, User $user): bool
    {
        if ($prompt->isOrganisation()) {
            // Wiki-style: any org member can edit
            return true;
        }

        // Personal: only owner
        return $prompt->getOwner() === $user;
    }

    /**
     * Check if user can delete a prompt
     * Personal: only owner
     * Organisation: any org member can delete
     */
    public function canDelete(Prompt $prompt, User $user): bool
    {
        return $this->canEdit($prompt, $user);
    }

    /**
     * Check if user can view a prompt
     */
    public function canView(Prompt $prompt, User $user, Organisation $organisation): bool
    {
        // Must be in the same organisation
        if ($prompt->getOrganisation() !== $organisation) {
            return false;
        }

        // Deleted prompts can't be viewed (except by admin in deleted list)
        if ($prompt->isDeleted()) {
            return false;
        }

        // Organisation prompts: any org member
        if ($prompt->isOrganisation()) {
            return true;
        }

        // Personal prompts: only owner
        return $prompt->getOwner() === $user;
    }

    /**
     * Get categories with translation keys
     * @return array<string, string>
     */
    public function getCategories(): array
    {
        return Prompt::getCategories();
    }

    /**
     * Get scopes with translation keys
     * @return array<string, string>
     */
    public function getScopes(): array
    {
        return Prompt::getScopes();
    }
}
