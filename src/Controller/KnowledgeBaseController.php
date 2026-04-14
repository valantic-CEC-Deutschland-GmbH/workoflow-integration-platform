<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\KnowledgeBaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/knowledge-base')]
#[IsGranted('ROLE_USER')]
class KnowledgeBaseController extends AbstractController
{
    public function __construct(
        private KnowledgeBaseService $kbService,
    ) {
    }

    #[Route('/', name: 'app_knowledge_base')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        return $this->render('knowledge_base/index.html.twig', [
            'organisation' => $organisation,
        ]);
    }

    #[Route('/api/documents', name: 'app_kb_api_documents', methods: ['GET'])]
    public function apiDocuments(Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $page = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('per_page', 25);
        $result = $this->kbService->listDocuments($organisation, $page, $perPage);
        return new JsonResponse($result);
    }

    #[Route('/api/documents/{docId}', name: 'app_kb_api_document_delete', methods: ['DELETE'])]
    public function apiDeleteDocument(string $docId, Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->deleteDocument($organisation, $docId);
        return new JsonResponse($result);
    }

    #[Route('/api/upload', name: 'app_kb_api_upload', methods: ['POST'])]
    public function apiUpload(Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'No file provided'], 400);
        }

        $result = $this->kbService->uploadDocument(
            $organisation,
            $file->getPathname(),
            $file->getClientOriginalName(),
            (string) $user->getId()
        );

        $statusCode = isset($result['error']) ? 400 : 202;
        return new JsonResponse($result, $statusCode);
    }

    #[Route('/api/snippet', name: 'app_kb_api_snippet', methods: ['POST'])]
    public function apiSnippet(Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];

        $result = $this->kbService->addSnippet(
            $organisation,
            $data['title'] ?? '',
            $data['text'] ?? '',
            (string) $user->getId()
        );

        $statusCode = isset($result['error']) ? 400 : 201;
        return new JsonResponse($result, $statusCode);
    }

    #[Route('/api/snippet/{docId}/content', name: 'app_kb_api_snippet_content', methods: ['GET'])]
    public function apiSnippetContent(string $docId, Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->getSnippetContent($organisation, $docId);
        $statusCode = isset($result['error']) ? 400 : 200;
        return new JsonResponse($result, $statusCode);
    }

    #[Route('/api/snippet/{docId}', name: 'app_kb_api_snippet_update', methods: ['PUT'])]
    public function apiSnippetUpdate(string $docId, Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];

        $result = $this->kbService->updateSnippet(
            $organisation,
            $docId,
            $data['title'] ?? '',
            $data['text'] ?? '',
            (string) $user->getId()
        );

        $statusCode = isset($result['error']) ? 400 : 200;
        return new JsonResponse($result, $statusCode);
    }

    #[Route('/api/sources', name: 'app_kb_api_sources', methods: ['GET'])]
    public function apiSources(Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->listSources($organisation);
        return new JsonResponse($result);
    }

    #[Route('/api/crawl', name: 'app_kb_api_crawl_start', methods: ['POST'])]
    public function apiStartCrawl(Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $sitemapUrl = $data['sitemap_url'] ?? '';

        if (!$sitemapUrl) {
            return new JsonResponse(['error' => 'sitemap_url is required'], 400);
        }

        $result = $this->kbService->startCrawl($organisation, $sitemapUrl, (string) $user->getId());
        $statusCode = isset($result['error']) ? 400 : 202;
        return new JsonResponse($result, $statusCode);
    }

    #[Route('/api/crawls', name: 'app_kb_api_crawl_list', methods: ['GET'])]
    public function apiCrawlList(Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->listCrawlJobs($organisation);
        return new JsonResponse($result);
    }

    #[Route('/api/crawl/{crawlJobId}', name: 'app_kb_api_crawl_status', methods: ['GET'])]
    public function apiCrawlStatus(string $crawlJobId, Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->getCrawlJob($organisation, $crawlJobId);
        return new JsonResponse($result);
    }

    #[Route('/api/crawl/{crawlJobId}', name: 'app_kb_api_crawl_delete', methods: ['DELETE'])]
    public function apiDeleteCrawl(string $crawlJobId, Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->deleteCrawlJob($organisation, $crawlJobId);
        return new JsonResponse($result);
    }

    #[Route('/api/domains', name: 'app_kb_api_domains', methods: ['GET'])]
    public function apiDomains(Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->listDomains($organisation);
        return new JsonResponse($result);
    }

    #[Route('/api/domains/{domain}', name: 'app_kb_api_domain_delete', methods: ['DELETE'], requirements: ['domain' => '.+'])]
    public function apiDeleteDomain(string $domain, Request $request): JsonResponse
    {
        $organisation = $this->getOrganisation($request);
        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        $result = $this->kbService->deleteDomain($organisation, $domain);
        return new JsonResponse($result);
    }

    private function getOrganisation(Request $request): ?\App\Entity\Organisation
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        return $user->getCurrentOrganisation($sessionOrgId);
    }
}
