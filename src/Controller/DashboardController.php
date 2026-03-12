<?php

namespace App\Controller;

use App\Entity\Organisation;
use App\Entity\User;
use App\Enum\TenantType;
use App\Integration\IntegrationRegistry;
use App\Repository\IntegrationConfigRepository;
use App\Service\AuditLogService;
use App\Service\DashboardStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    #[Route('/my-agent', name: 'app_my_agent')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request, IntegrationConfigRepository $integrationConfigRepository, IntegrationRegistry $integrationRegistry, DashboardStatsService $dashboardStatsService): Response
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

        // Collect unique configured skill types with their logo paths for the capability chips
        $configuredSkills = [];
        foreach ($integrations as $integration) {
            $type = $integration->getIntegrationType();
            if (!isset($configuredSkills[$type])) {
                $registeredIntegration = $integrationRegistry->get($type);
                $configuredSkills[$type] = [
                    'type' => $type,
                    'name' => $integration->getName() ?? ucfirst((string) $type),
                    'logoPath' => $registeredIntegration ? $registeredIntegration->getLogoPath() : '/images/logos/workoflow-logo.png',
                ];
            }
        }

        $isOnboarding = count($integrations) === 0;
        $stats = null;
        if (!$isOnboarding && $organisation !== null) {
            $stats = $dashboardStatsService->getStats($organisation, $user, $workflowUserId);
        }

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'organisation' => $organisation,
            'userOrganisation' => $userOrganisation,
            'integrations' => $integrations,
            'configuredSkills' => $configuredSkills,
            'isOnboarding' => $isOnboarding,
            'stats' => $stats,
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
            $tenantType = $request->request->get('tenant_type');

            if ($name) {
                $organisation = new Organisation();
                $organisation->setName($name);

                $validTenantTypes = array_values(TenantType::choices());
                if ($tenantType && in_array($tenantType, $validTenantTypes, true)) {
                    $organisation->setTenantType($tenantType);
                }

                $user->addOrganisation($organisation);
                $user->setRoles([User::ROLE_USER, User::ROLE_ADMIN]);

                // Set this as the current organisation in session
                $request->getSession()->set('current_organisation_id', $organisation->getId());

                $em->persist($organisation);
                $em->flush();

                $auditLogService->log(
                    'organisation.created',
                    $user,
                    ['name' => $name, 'tenant_type' => $tenantType]
                );

                $this->addFlash('success', 'organisation.created.success');
                return $this->redirectToRoute('app_my_agent');
            }
        }

        return $this->render('organisation/create.html.twig', [
            'tenantTypeChoices' => TenantType::choices(),
        ]);
    }
}
