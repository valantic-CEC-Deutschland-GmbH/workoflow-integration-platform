<?php

namespace App\Controller;

use App\Entity\Organisation;
use App\Repository\IntegrationConfigRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;

class DashboardController extends AbstractController
{
    #[Route('/general', name: 'app_general')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request, IntegrationConfigRepository $integrationConfigRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);
        $userOrganisation = $user->getCurrentUserOrganisation($sessionOrgId);

        if (!$organisation) {
            // If user has no organisations at all, redirect to create
            if ($user->getOrganisations()->isEmpty()) {
                return $this->redirectToRoute('app_tenant_create');
            }
            // If user has organisations but none selected, select the first one
            $organisation = $user->getOrganisations()->first();
            if ($organisation) {
                $request->getSession()->set('current_organisation_id', $organisation->getId());
                $userOrganisation = $user->getCurrentUserOrganisation($organisation->getId());
            }
        }

        // Get workflow user ID if available
        $workflowUserId = $userOrganisation ? $userOrganisation->getWorkflowUserId() : null;
        $integrations = $integrationConfigRepository->findByOrganisationAndWorkflowUser($organisation, $workflowUserId);

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'organisation' => $organisation,
            'userOrganisation' => $userOrganisation,
            'integrations' => $integrations,
        ]);
    }

    #[Route('/tenant/create', name: 'app_tenant_create', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createOrganisation(
        Request $request,
        EntityManagerInterface $em,
        AuditLogService $auditLogService
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');

            if ($name) {
                $organisation = new Organisation();
                $organisation->setName($name);

                $user->addOrganisation($organisation);
                $user->setRoles([User::ROLE_USER, User::ROLE_ADMIN]);

                // Set this as the current organisation in session
                $request->getSession()->set('current_organisation_id', $organisation->getId());

                $em->persist($organisation);
                $em->flush();

                $auditLogService->log(
                    'organisation.created',
                    $user,
                    ['name' => $name]
                );

                $this->addFlash('success', 'organisation.created.success');
                return $this->redirectToRoute('app_general');
            }
        }

        return $this->render('organisation/create.html.twig');
    }
}
