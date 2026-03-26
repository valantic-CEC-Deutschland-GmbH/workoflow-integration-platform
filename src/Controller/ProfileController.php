<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogService $auditLogService,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/', name: 'app_profile')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        // Check if a new token was just generated (from session flash)
        $newToken = $request->getSession()->get('new_prompt_token');
        if ($newToken !== null) {
            $request->getSession()->remove('new_prompt_token');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'userOrganisation' => $userOrganisation,
            'newToken' => $newToken,
        ]);
    }

    #[Route('/token/generate', name: 'app_profile_token_generate', methods: ['POST'])]
    public function generateToken(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        if ($userOrganisation === null) {
            $this->addFlash('error', $this->translator->trans('profile.no_organisation'));
            return $this->redirectToRoute('app_profile');
        }

        if (!$this->isCsrfTokenValid('generate-token', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $token = $userOrganisation->regenerateToken();
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation(
            'user.token_generated',
            $userOrganisation->getOrganisation(),
            $user,
            []
        );

        // Store token in session to display once
        $request->getSession()->set('new_prompt_token', $token);

        $this->addFlash('success', $this->translator->trans('profile.token_generated'));

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/tool-mode', name: 'app_profile_tool_mode', methods: ['POST'])]
    public function updateToolMode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('tool-mode', $request->request->get('_csrf_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $mode = $request->request->get('mode');
        $allowedModes = ['read_only', 'standard', 'full'];

        if (!in_array($mode, $allowedModes, true)) {
            return new JsonResponse(['error' => 'Invalid mode'], Response::HTTP_BAD_REQUEST);
        }

        $user->setToolAccessMode($mode);
        $this->entityManager->flush();

        $this->auditLogService->log('user.tool_mode_changed', $user, ['mode' => $mode]);

        return new JsonResponse([
            'success' => true,
            'message' => $this->translator->trans('profile.mode_updated'),
        ]);
    }

    #[Route('/delete', name: 'app_profile_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('delete-account', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->auditLogService->log('user.deleted', $user, ['email' => $user->getEmail()]);

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $request->getSession()->invalidate();

        return $this->redirectToRoute('app_login');
    }
}
