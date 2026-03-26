<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/audit-log')]
#[IsGranted('ROLE_USER')]
class AuditLogController extends AbstractController
{
    public function __construct(
        private AuditLogRepository $auditLogRepository
    ) {
    }

    #[Route('/', name: 'app_audit_log')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        // Get filter parameters from request
        $search = $request->query->get('search', '');
        $dateFrom = $request->query->get('date_from', '');
        $dateTo = $request->query->get('date_to', '');
        $executionId = $request->query->get('execution_id', '');
        $viewMode = $request->query->get('view_mode', 'flat'); // 'flat' or 'grouped'
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 50; // Items per page for flat view, 20 for grouped

        // Get sorting parameters with validation
        $sortBy = $request->query->get('sortBy', 'createdAt');
        $sortDir = strtoupper($request->query->get('sortDir', 'DESC'));

        // Whitelist of sortable columns (mapped to entity properties)
        $allowedSortFields = ['createdAt', 'action', 'ip', 'executionId'];
        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        // Validate view mode
        if (!in_array($viewMode, ['flat', 'grouped'], true)) {
            $viewMode = 'flat';
        }

        // Validate sort direction
        if (!in_array($sortDir, ['ASC', 'DESC'], true)) {
            $sortDir = 'DESC';
        }

        // Build filters array
        $filters = [];
        if (!empty($search)) {
            $filters['search'] = $search;
        }

        // Default to 15 minutes ago if no date_from specified
        // (Clear Filters also resets to this default)
        if (empty($dateFrom)) {
            $filters['date_from'] = new \DateTime('-15 minutes');
        } else {
            try {
                $filters['date_from'] = new \DateTime($dateFrom);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Invalid date format for "From" date');
                $filters['date_from'] = new \DateTime('-15 minutes');
            }
        }

        if (!empty($dateTo)) {
            try {
                $filters['date_to'] = new \DateTime($dateTo);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Invalid date format for "To" date');
            }
        }
        if (!empty($executionId)) {
            $filters['execution_id'] = $executionId;
        }

        // Get audit logs based on view mode
        if ($viewMode === 'grouped') {
            $result = $this->auditLogRepository->findByOrganisationGroupedByExecutionId(
                $organisation->getId(),
                $filters,
                $page,
                20, // Fewer groups per page
                $sortDir
            );

            return $this->render('audit_log/index.html.twig', [
                'logs' => [],
                'grouped_logs' => $result['groups'],
                'total' => $result['total'],
                'pages' => $result['pages'],
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'organisation' => $organisation,
                'filters' => [
                    'search' => $search,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'execution_id' => $executionId,
                    'view_mode' => $viewMode,
                    'sortBy' => $sortBy,
                    'sortDir' => $sortDir,
                ],
            ]);
        }

        // Flat view (default)
        $result = $this->auditLogRepository->findByOrganisationWithFilters(
            $organisation->getId(),
            $filters,
            $page,
            $limit,
            $sortBy,
            $sortDir
        );

        return $this->render('audit_log/index.html.twig', [
            'logs' => $result['results'],
            'grouped_logs' => [],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'current_page' => $result['current_page'],
            'per_page' => $result['per_page'],
            'organisation' => $organisation,
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'execution_id' => $executionId,
                'view_mode' => $viewMode,
                'sortBy' => $sortBy,
                'sortDir' => $sortDir,
            ],
        ]);
    }
}
