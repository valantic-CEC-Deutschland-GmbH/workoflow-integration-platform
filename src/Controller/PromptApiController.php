<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Enum\PromptPlatform;
use App\Integration\IntegrationRegistry;
use App\Integration\PersonalizedSkillInterface;
use App\Repository\PromptRepository;
use App\Repository\UserOrganisationRepository;
use App\Service\AuditLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/prompts')]
class PromptApiController extends AbstractController
{
    public function __construct(
        private PromptRepository $promptRepository,
        private UserOrganisationRepository $userOrganisationRepository,
        private AuditLogService $auditLogService,
        private IntegrationRegistry $integrationRegistry,
    ) {
    }

    #[Route('', name: 'api_prompts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->json([
                'error' => 'No API token provided',
                'hint' => 'Include token via X-Prompt-Token header or Authorization: Bearer <token>',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userOrganisation = $this->userOrganisationRepository->findOneBy([
            'personalAccessToken' => $token,
        ]);

        if ($userOrganisation === null) {
            return $this->json([
                'error' => 'Invalid API token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = $userOrganisation->getUser();
        $organisation = $userOrganisation->getOrganisation();

        if ($user === null || $organisation === null) {
            return $this->json([
                'error' => 'Invalid token configuration',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $scope = $request->query->get('scope');
        $category = $request->query->get('category');
        $skill = $request->query->get('skill');
        $platform = $request->query->get('platform');
        $uuid = $request->query->get('uuid');

        // Validate scope parameter
        if ($scope !== null && !in_array($scope, [Prompt::SCOPE_PERSONAL, Prompt::SCOPE_ORGANISATION], true)) {
            return $this->json([
                'error' => 'Invalid scope parameter',
                'valid_values' => [Prompt::SCOPE_PERSONAL, Prompt::SCOPE_ORGANISATION],
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate category parameter
        if ($category !== null && !array_key_exists($category, Prompt::getCategories())) {
            return $this->json([
                'error' => 'Invalid category parameter',
                'valid_values' => array_keys(Prompt::getCategories()),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate skill parameter
        if ($skill !== null) {
            $validSkills = [];
            foreach ($this->integrationRegistry->all() as $integration) {
                if ($integration instanceof PersonalizedSkillInterface) {
                    $validSkills[] = $integration->getType();
                }
            }
            if (!in_array($skill, $validSkills, true)) {
                return $this->json([
                    'error' => 'Invalid skill parameter',
                    'valid_values' => $validSkills,
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        // Validate platform parameter
        if ($platform !== null && PromptPlatform::tryFrom($platform) === null) {
            return $this->json([
                'error' => 'Invalid platform parameter',
                'valid_values' => array_column(PromptPlatform::cases(), 'value'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $prompts = $this->promptRepository->findForApi(
            $user,
            $organisation,
            $scope,
            $category,
            $uuid,
            $skill,
            $platform
        );

        $this->auditLogService->logWithOrganisation(
            'api.prompts.list',
            $organisation,
            $user,
            [
                'scope' => $scope,
                'category' => $category,
                'skill' => $skill,
                'platform' => $platform,
                'uuid' => $uuid,
                'result_count' => count($prompts),
            ]
        );

        return $this->json([
            'prompts' => $this->serializePrompts($prompts),
            'meta' => [
                'total' => count($prompts),
                'scope' => $scope,
                'category' => $category,
                'skill' => $skill,
                'platform' => $platform,
            ],
        ]);
    }

    private function extractToken(Request $request): ?string
    {
        // Try X-Prompt-Token header first
        $token = $request->headers->get('X-Prompt-Token');

        if ($token !== null && $token !== '') {
            return $token;
        }

        // Try Authorization: Bearer header
        $authHeader = $request->headers->get('Authorization', '');

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param Prompt[] $prompts
     * @return array<int, array<string, mixed>>
     */
    private function serializePrompts(array $prompts): array
    {
        return array_map(function (Prompt $prompt): array {
            return [
                'uuid' => $prompt->getUuid(),
                'title' => $prompt->getTitle(),
                'content' => $prompt->getContent(),
                'description' => $prompt->getDescription(),
                'category' => $prompt->getCategory(),
                'skill' => $prompt->getSkill(),
                'platform' => $prompt->getPlatform(),
                'scope' => $prompt->getScope(),
                'owner' => [
                    'name' => $prompt->getOwner()?->getName(),
                    'email' => $prompt->getOwner()?->getEmail(),
                ],
                'upvotes' => $prompt->getUpvoteCount(),
                'comments_count' => $prompt->getComments()->count(),
                'created_at' => $prompt->getCreatedAt()?->format('c'),
                'updated_at' => $prompt->getUpdatedAt()?->format('c'),
            ];
        }, $prompts);
    }
}
