<?php

namespace App\Service;

use App\Entity\Organisation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KnowledgeBaseService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiAuthUser,
        private readonly string $apiAuthPassword,
    ) {
    }

    public function listDocuments(Organisation $organisation, int $page = 1, int $perPage = 25): array
    {
        return $this->request($organisation, 'GET', '/api/kb/documents', [
            'query' => [
                'org_uuid' => $organisation->getUuid(),
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function getDocument(Organisation $organisation, string $docId): array
    {
        return $this->request($organisation, 'GET', "/api/kb/documents/{$docId}", [
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    public function deleteDocument(Organisation $organisation, string $docId): array
    {
        return $this->request($organisation, 'DELETE', "/api/kb/documents/{$docId}", [
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    public function uploadDocument(Organisation $organisation, string $filePath, string $filename, string $uploadedBy, string $documentType = 'general'): array
    {
        $baseUrl = $this->getBaseUrl($organisation);
        if (!$baseUrl) {
            return ['error' => 'Orchestrator URL not configured'];
        }

        try {
            $formData = new FormDataPart([
                'org_uuid' => $organisation->getUuid(),
                'uploaded_by' => $uploadedBy,
                'document_type' => $documentType,
                'file' => DataPart::fromPath($filePath, $filename),
            ]);

            $response = $this->httpClient->request('POST', $baseUrl . '/api/kb/upload', [
                'auth_basic' => [$this->apiAuthUser, $this->apiAuthPassword],
                'timeout' => 60,
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
            ]);
            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('KB upload failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function downloadDocument(Organisation $organisation, string $docId): array
    {
        $baseUrl = $this->getBaseUrl($organisation);
        if (!$baseUrl) {
            return ['error' => 'Orchestrator URL not configured'];
        }

        try {
            $response = $this->httpClient->request('GET', $baseUrl . "/api/kb/documents/{$docId}/download", [
                'auth_basic' => [$this->apiAuthUser, $this->apiAuthPassword],
                'timeout' => 30,
                'query' => ['org_uuid' => $organisation->getUuid()],
            ]);

            $contentDisposition = $response->getHeaders()['content-disposition'][0] ?? '';
            $contentType = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
            $filename = 'document';
            if (preg_match('/filename="([^"]+)"/', $contentDisposition, $m)) {
                $filename = $m[1];
            } elseif (preg_match('/filename=([^;\s]+)/', $contentDisposition, $m)) {
                $filename = $m[1];
            }

            return [
                'content' => $response->getContent(),
                'filename' => $filename,
                'content_type' => $contentType,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('KB download failed', ['error' => $e->getMessage(), 'docId' => $docId]);
            return ['error' => $e->getMessage()];
        }
    }

    public function addSnippet(Organisation $organisation, string $title, string $text, string $uploadedBy): array
    {
        return $this->request($organisation, 'POST', '/api/kb/snippet', [
            'json' => [
                'org_uuid' => $organisation->getUuid(),
                'title' => $title,
                'text' => $text,
                'uploaded_by' => $uploadedBy,
            ],
        ]);
    }

    public function getSnippetContent(Organisation $organisation, string $docId): array
    {
        return $this->request($organisation, 'GET', "/api/kb/snippet/{$docId}/content", [
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    public function updateSnippet(Organisation $organisation, string $docId, string $title, string $text, string $uploadedBy): array
    {
        return $this->request($organisation, 'PUT', "/api/kb/snippet/{$docId}", [
            'json' => [
                'title' => $title,
                'text' => $text,
                'uploaded_by' => $uploadedBy,
            ],
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    public function listSources(Organisation $organisation): array
    {
        return $this->request($organisation, 'GET', '/api/kb/sources', [
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    public function startCrawl(Organisation $organisation, string $sitemapUrl, string $createdBy): array
    {
        return $this->request($organisation, 'POST', '/api/kb/crawl', [
            'json' => [
                'org_uuid' => $organisation->getUuid(),
                'sitemap_url' => $sitemapUrl,
                'created_by' => $createdBy,
            ],
        ]);
    }

    public function getCrawlJob(Organisation $organisation, string $crawlJobId): array
    {
        return $this->request($organisation, 'GET', "/api/kb/crawl/{$crawlJobId}");
    }

    public function listCrawlJobs(Organisation $organisation): array
    {
        return $this->request($organisation, 'GET', '/api/kb/crawls', [
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    public function deleteCrawlJob(Organisation $organisation, string $crawlJobId): array
    {
        return $this->request($organisation, 'DELETE', "/api/kb/crawl/{$crawlJobId}");
    }

    public function listDomains(Organisation $organisation): array
    {
        return $this->request($organisation, 'GET', '/api/kb/domains', [
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    public function deleteDomain(Organisation $organisation, string $domain): array
    {
        return $this->request($organisation, 'DELETE', "/api/kb/domains/{$domain}", [
            'query' => ['org_uuid' => $organisation->getUuid()],
        ]);
    }

    private function request(Organisation $organisation, string $method, string $path, array $options = []): array
    {
        $baseUrl = $this->getBaseUrl($organisation);
        if (!$baseUrl) {
            return ['error' => 'Orchestrator URL not configured'];
        }

        $options['auth_basic'] = [$this->apiAuthUser, $this->apiAuthPassword];
        $options['timeout'] = $options['timeout'] ?? 15;

        try {
            $response = $this->httpClient->request($method, $baseUrl . $path, $options);
            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('KB API request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    private function getBaseUrl(Organisation $organisation): ?string
    {
        return $organisation->getOrchestratorApiUrl();
    }
}
