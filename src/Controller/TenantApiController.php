<?php

namespace App\Controller;

use App\Repository\OrganisationRepository;
use App\Service\AuditLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tenant')]
class TenantApiController extends AbstractController
{
    public function __construct(
        private OrganisationRepository $organisationRepository,
        private AuditLogService $auditLogService,
        private string $apiAuthUser,
        private string $apiAuthPassword,
    ) {
    }

    #[Route('/{organisationUuid}/settings', name: 'api_tenant_settings', methods: ['GET'])]
    public function getSettings(string $organisationUuid, Request $request): JsonResponse
    {
        if (!$this->validateBasicAuth($request)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $organisation = $this->organisationRepository->findOneBy(['uuid' => $organisationUuid]);
        if (!$organisation) {
            return $this->json(['error' => 'Organisation not found'], Response::HTTP_NOT_FOUND);
        }

        $webhookUrl = $organisation->getWebhookUrl();
        $webhookType = $organisation->getWebhookType();
        $complete = !empty($webhookUrl) && !empty($webhookType);

        $this->auditLogService->logWithOrganisation(
            'api.tenant_settings',
            $organisation,
            null,
            [
                'complete' => $complete,
            ]
        );

        return $this->json([
            'success' => true,
            'complete' => $complete,
            'settings' => [
                'name' => $organisation->getName(),
                'uuid' => $organisation->getUuid(),
                'webhook_type' => $webhookType,
                'webhook_url' => $webhookUrl,
            ],
        ]);
    }

    private function validateBasicAuth(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Basic ')) {
            return false;
        }

        $encoded = substr($authHeader, 6);
        $decoded = base64_decode($encoded);
        if ($decoded === false) {
            return false;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return $username === $this->apiAuthUser && $password === $this->apiAuthPassword;
    }
}
