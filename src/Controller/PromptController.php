<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Form\PromptCommentType;
use App\Form\PromptType;
use App\Repository\PromptRepository;
use App\Service\AuditLogService;
use App\Service\PromptService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/prompts')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PromptController extends AbstractController
{
    public function __construct(
        private PromptRepository $promptRepository,
        private PromptService $promptService,
        private AuditLogService $auditLogService,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/', name: 'app_prompts')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $scope = $request->query->get('scope', Prompt::SCOPE_PERSONAL);
        $category = $request->query->get('category');
        $sort = $request->query->get('sort', 'newest');

        // Treat empty string as null for category filter
        if ($category === '') {
            $category = null;
        }

        // Validate sort option
        $sortOptions = PromptRepository::getSortOptions();
        if (!array_key_exists($sort, $sortOptions)) {
            $sort = 'newest';
        }

        if ($scope === Prompt::SCOPE_PERSONAL) {
            $prompts = $this->promptRepository->findPersonalPrompts($user, $organisation, $category, $sort);
        } else {
            $prompts = $this->promptRepository->findOrganisationPrompts($organisation, $category, $sort);
        }

        $counts = $this->promptRepository->countByScope($organisation, $user);

        // Get user's API token for the helper section
        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        return $this->render('prompt/index.html.twig', [
            'prompts' => $prompts,
            'activeScope' => $scope,
            'activeCategory' => $category,
            'activeSort' => $sort,
            'categories' => $this->promptService->getCategories(),
            'sortOptions' => $sortOptions,
            'counts' => $counts,
            'organisation' => $organisation,
            'userOrganisation' => $userOrganisation,
        ]);
    }

    #[Route('/new', name: 'app_prompt_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $prompt = new Prompt();
        $form = $this->createForm(PromptType::class, $prompt);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $prompt->setOwner($user);
            $prompt->setOrganisation($organisation);

            $this->entityManager->persist($prompt);
            $this->entityManager->flush();

            $this->auditLogService->logWithOrganisation('prompt.created', $organisation, $user, [
                'prompt_uuid' => $prompt->getUuid(),
                'title' => $prompt->getTitle(),
                'scope' => $prompt->getScope(),
                'category' => $prompt->getCategory(),
            ]);

            $this->addFlash('success', $this->translator->trans('prompt.created.success'));

            return $this->redirectToRoute('app_prompt_show', ['uuid' => $prompt->getUuid()]);
        }

        return $this->render('prompt/new.html.twig', [
            'form' => $form,
            'organisation' => $organisation,
        ]);
    }

    #[Route('/{uuid}', name: 'app_prompt_show', methods: ['GET'])]
    public function show(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $prompt = $this->promptRepository->findByUuid($uuid);

        if (!$prompt || !$this->promptService->canView($prompt, $user, $organisation)) {
            throw $this->createNotFoundException('Prompt not found');
        }

        $commentForm = null;
        if ($prompt->isOrganisation()) {
            $commentForm = $this->createForm(PromptCommentType::class);
        }

        return $this->render('prompt/show.html.twig', [
            'prompt' => $prompt,
            'commentForm' => $commentForm?->createView(),
            'canEdit' => $this->promptService->canEdit($prompt, $user),
            'canDelete' => $this->promptService->canDelete($prompt, $user),
            'hasUpvoted' => $prompt->isOrganisation() ? $prompt->hasUserUpvoted($user) : false,
            'organisation' => $organisation,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_prompt_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $prompt = $this->promptRepository->findByUuid($uuid);

        if (!$prompt || !$this->promptService->canView($prompt, $user, $organisation)) {
            throw $this->createNotFoundException('Prompt not found');
        }

        if (!$this->promptService->canEdit($prompt, $user)) {
            $this->addFlash('error', $this->translator->trans('prompt.edit.not_allowed'));
            return $this->redirectToRoute('app_prompt_show', ['uuid' => $uuid]);
        }

        $form = $this->createForm(PromptType::class, $prompt);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->auditLogService->logWithOrganisation('prompt.updated', $organisation, $user, [
                'prompt_uuid' => $prompt->getUuid(),
                'title' => $prompt->getTitle(),
                'scope' => $prompt->getScope(),
                'category' => $prompt->getCategory(),
            ]);

            $this->addFlash('success', $this->translator->trans('prompt.updated.success'));

            return $this->redirectToRoute('app_prompt_show', ['uuid' => $prompt->getUuid()]);
        }

        return $this->render('prompt/edit.html.twig', [
            'form' => $form,
            'prompt' => $prompt,
            'organisation' => $organisation,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_prompt_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $prompt = $this->promptRepository->findByUuid($uuid);

        if (!$prompt || !$this->promptService->canView($prompt, $user, $organisation)) {
            throw $this->createNotFoundException('Prompt not found');
        }

        if (!$this->promptService->canDelete($prompt, $user)) {
            $this->addFlash('error', $this->translator->trans('prompt.delete.not_allowed'));
            return $this->redirectToRoute('app_prompt_show', ['uuid' => $uuid]);
        }

        if (!$this->isCsrfTokenValid('delete-prompt-' . $uuid, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->promptService->softDelete($prompt, $user);

        $this->addFlash('success', $this->translator->trans('prompt.deleted.success'));

        return $this->redirectToRoute('app_prompts', ['scope' => $prompt->getScope()]);
    }

    #[Route('/{uuid}/upvote', name: 'app_prompt_upvote', methods: ['POST'])]
    public function upvote(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], Response::HTTP_BAD_REQUEST);
        }

        $prompt = $this->promptRepository->findByUuid($uuid);

        if (!$prompt || !$this->promptService->canView($prompt, $user, $organisation)) {
            return new JsonResponse(['error' => 'Prompt not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$prompt->isOrganisation()) {
            return new JsonResponse(['error' => 'Cannot upvote personal prompts'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('upvote-prompt-' . $uuid, $request->request->get('_csrf_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $hasUpvoted = $this->promptService->toggleUpvote($prompt, $user);

        return new JsonResponse([
            'hasUpvoted' => $hasUpvoted,
            'upvoteCount' => $prompt->getUpvoteCount(),
        ]);
    }

    #[Route('/{uuid}/comment', name: 'app_prompt_comment', methods: ['POST'])]
    public function addComment(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $prompt = $this->promptRepository->findByUuid($uuid);

        if (!$prompt || !$this->promptService->canView($prompt, $user, $organisation)) {
            throw $this->createNotFoundException('Prompt not found');
        }

        if (!$prompt->isOrganisation()) {
            $this->addFlash('error', $this->translator->trans('prompt.comment.not_allowed'));
            return $this->redirectToRoute('app_prompt_show', ['uuid' => $uuid]);
        }

        $form = $this->createForm(PromptCommentType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $content = $form->get('content')->getData();
            $this->promptService->addComment($prompt, $user, $content);
            $this->addFlash('success', $this->translator->trans('prompt.comment.added'));
        }

        return $this->redirectToRoute('app_prompt_show', ['uuid' => $uuid]);
    }

    #[Route('/admin/deleted', name: 'app_prompts_deleted')]
    #[IsGranted('ROLE_ADMIN')]
    public function deletedPrompts(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $deletedPrompts = $this->promptRepository->findDeleted($organisation);

        return $this->render('prompt/admin/deleted.html.twig', [
            'prompts' => $deletedPrompts,
            'organisation' => $organisation,
        ]);
    }

    #[Route('/admin/{uuid}/restore', name: 'app_prompt_restore', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function restore(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $prompt = $this->promptRepository->findByUuidIncludingDeleted($uuid);

        if (!$prompt || $prompt->getOrganisation() !== $organisation) {
            throw $this->createNotFoundException('Prompt not found');
        }

        if (!$this->isCsrfTokenValid('restore-prompt-' . $uuid, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->promptService->restore($prompt, $user);

        $this->addFlash('success', $this->translator->trans('prompt.restored.success'));

        return $this->redirectToRoute('app_prompt_show', ['uuid' => $uuid]);
    }
}
