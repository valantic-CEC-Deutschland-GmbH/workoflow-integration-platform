<?php

namespace App\Controller;

use App\Entity\Organisation;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/organisation')]
#[IsGranted('ROLE_USER')]
class OrganisationController extends AbstractController
{
    #[Route('/settings', name: 'app_organisation_settings')]
    #[IsGranted('ROLE_ADMIN')]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');

            if ($name && $name !== $organisation->getName()) {
                $oldName = $organisation->getName();
                $organisation->setName($name);
                $em->flush();

                $auditLogService->log(
                    'organisation.updated',
                    $user,
                    ['old_name' => $oldName, 'new_name' => $name]
                );

                $this->addFlash('success', 'organisation.updated.success');
                return $this->redirectToRoute('app_organisation_settings');
            }
        }

        return $this->render('organisation/settings.html.twig', [
            'organisation' => $organisation,
        ]);
    }

    #[Route('/members', name: 'app_organisation_members')]
    #[IsGranted('ROLE_ADMIN')]
    public function members(Request $request, UserRepository $userRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $members = $userRepository->findByOrganisation($organisation->getId());

        return $this->render('organisation/members.html.twig', [
            'organisation' => $organisation,
            'members' => $members,
            'user_organisations' => $user->getOrganisations(),
        ]);
    }

    #[Route('/members/{id}/role', name: 'app_organisation_member_role', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateMemberRole(
        User $member,
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        $memberBelongsToOrg = false;
        foreach ($member->getUserOrganisations() as $userOrg) {
            if ($userOrg->getOrganisation()->getId() === $organisation->getId()) {
                $memberBelongsToOrg = true;
                break;
            }
        }
        if (!$memberBelongsToOrg) {
            throw $this->createAccessDeniedException();
        }

        if ($member->getId() === $user->getId()) {
            $this->addFlash('error', 'organisation.member.cannot_change_own_role');
            return $this->redirectToRoute('app_organisation_members');
        }

        $role = $request->request->get('role');
        if ($role === 'admin') {
            $member->setRoles([User::ROLE_USER, User::ROLE_ADMIN]);
        } else {
            $member->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);
        }

        $em->flush();

        $auditLogService->log(
            'organisation.member.role_updated',
            $user,
            ['member_id' => $member->getId(), 'new_role' => $role]
        );

        $this->addFlash('success', 'organisation.member.role_updated');
        return $this->redirectToRoute('app_organisation_members');
    }

    #[Route('/switch/{id}', name: 'app_organisation_switch')]
    public function switch(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Check if user has access to this organisation
        $hasAccess = false;
        $targetOrganisation = null;
        foreach ($user->getOrganisations() as $org) {
            if ($org->getId() === $id) {
                $hasAccess = true;
                $targetOrganisation = $org;
                break;
            }
        }

        if (!$hasAccess || !$targetOrganisation) {
            $this->addFlash('error', 'You do not have access to this organisation');
            return $this->redirectToRoute('app_general');
        }

        // Store the selected organisation ID in session
        $session = $request->getSession();
        $session->set('current_organisation_id', $id);

        $auditLogService->log(
            'organisation.switched',
            $user,
            ['organisation_id' => $id, 'organisation_name' => $targetOrganisation->getName()]
        );

        $this->addFlash('success', 'Switched to organisation: ' . $targetOrganisation->getName());

        // Redirect to the referer or dashboard
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_general');
    }

    #[Route('/invite', name: 'app_organisation_invite', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function invite(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $organisationId = (int) $request->request->get('organisation_id');
        $organisation = $em->getRepository(Organisation::class)->find($organisationId);

        // Verify the admin has access to the selected organisation
        if (!$organisation || !$user->getOrganisations()->contains($organisation)) {
            $this->addFlash('error', 'organisation.invite.invalid_organisation');
            return $this->redirectToRoute('app_organisation_members');
        }

        $email = $request->request->get('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'organisation.invite.invalid_email');
            return $this->redirectToRoute('app_organisation_members');
        }

        $existingUser = $userRepository->findOneBy(['email' => $email]);
        if ($existingUser) {
            // Check if user is already in the selected organisation
            if ($existingUser->getOrganisations()->contains($organisation)) {
                $this->addFlash('error', 'organisation.invite.user_already_in_organisation');
            } else {
                $existingUser->addOrganisation($organisation);
                if (!$existingUser->isAdmin()) {
                    $existingUser->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);
                }
                $em->flush();

                $auditLogService->log(
                    'organisation.member.added',
                    $user,
                    ['invited_email' => $email, 'organisation_id' => $organisationId]
                );

                $this->addFlash('success', 'organisation.invite.user_added');
            }
        } else {
            $this->addFlash('info', 'organisation.invite.user_not_found');
        }

        return $this->redirectToRoute('app_organisation_members');
    }

    #[Route('/update-name', name: 'app_organisation_update_name', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateName(
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['success' => false, 'error' => 'No organisation found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $newName = $data['name'] ?? null;

        if (!$newName || trim($newName) === '') {
            return new JsonResponse(['success' => false, 'error' => 'Name cannot be empty'], 400);
        }

        $oldName = $organisation->getName();
        if ($newName !== $oldName) {
            $organisation->setName($newName);
            $em->flush();

            $auditLogService->log(
                'organisation.name_updated',
                $user,
                ['old_name' => $oldName, 'new_name' => $newName]
            );

            return new JsonResponse([
                'success' => true,
                'name' => $newName,
                'message' => 'Organisation name updated successfully'
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'name' => $newName,
            'message' => 'No changes made'
        ]);
    }
}
