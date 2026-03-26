<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;
use App\Service\UrlNormalizer;

class GitLabService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlNormalizer $urlNormalizer,
    ) {
    }

    private function validateAndNormalizeUrl(string $url): string
    {
        $normalized = $this->urlNormalizer->normalize($url);

        // Require HTTPS for gitlab.com
        if (str_contains(parse_url($normalized, PHP_URL_HOST) ?? '', 'gitlab.com') && !str_starts_with($normalized, 'https://')) {
            throw new \InvalidArgumentException(
                sprintf('GitLab.com URL must use HTTPS for security. Got: "%s"', $url)
            );
        }

        return $normalized;
    }

    /**
     * Build authorization headers for GitLab API
     */
    private function getAuthHeaders(string $apiToken): array
    {
        return [
            'PRIVATE-TOKEN' => $apiToken,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Parse project parameter to get project ID or path
     * Supports: numeric ID, "namespace/project", or URL-encoded path
     */
    private function parseProjectParameter(string $project): string
    {
        // If numeric ID, return as-is
        if (is_numeric($project)) {
            return $project;
        }

        // URL-encode the project path
        return urlencode($project);
    }

    public function testConnection(array $credentials): bool
    {
        $result = $this->testConnectionDetailed($credentials);
        return $result['success'];
    }

    /**
     * Test GitLab connection with detailed error reporting
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Validate and normalize URL
            $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);

            // Test authentication endpoint
            try {
                $response = $this->httpClient->request('GET', $url . '/api/v4/user', [
                    'headers' => $this->getAuthHeaders($credentials['api_token']),
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/api/v4/user',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    // Successfully authenticated
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to GitLab. API authentication verified.',
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    // Non-200 response (should not happen as HttpClient throws exception)
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from GitLab API",
                        'suggestion' => 'Check the error details and verify your GitLab instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/api/v4/user',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid API token. Please verify your credentials.',
                        'suggestion' => 'Check that your API token is correct. Create a new API token in GitLab under User Settings > Access Tokens',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your API token does not have sufficient permissions.',
                        'suggestion' => 'Ensure the API token has api, read_api, read_repository, and write_repository scopes.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 404) {
                    return [
                        'success' => false,
                        'message' => 'API endpoint not found',
                        'details' => 'The URL may not be a valid GitLab instance or the API is not available.',
                        'suggestion' => 'Verify the URL points to a GitLab instance (e.g., https://gitlab.com or https://gitlab.example.com)',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your GitLab instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach GitLab server',
                    'details' => 'Network error: ' . $e->getMessage(),
                    'suggestion' => 'Check the URL and your network connection. Verify the domain is correct and accessible.',
                    'tested_endpoints' => $testedEndpoints
                ];
            }
        } catch (InvalidArgumentException $e) {
            // URL validation error
            return [
                'success' => false,
                'message' => 'Invalid URL',
                'details' => $e->getMessage(),
                'suggestion' => 'Enter a valid GitLab URL like https://gitlab.com or https://gitlab.example.com',
                'tested_endpoints' => $testedEndpoints
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Unexpected error',
                'details' => $e->getMessage(),
                'suggestion' => 'Please check your configuration and try again.',
                'tested_endpoints' => $testedEndpoints
            ];
        }
    }

    // Repository & Metadata Tools

    public function getProject(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function listProjects(array $credentials, ?bool $membership = null, int $perPage = 20): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $query = ['per_page' => $perPage];

        if ($membership !== null) {
            $query['membership'] = $membership ? 'true' : 'false';
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getFileContent(array $credentials, string $project, string $filePath, ?string $ref = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);
        $encodedPath = urlencode($filePath);

        $query = [];
        if ($ref !== null) {
            $query['ref'] = $ref;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/files/' . $encodedPath, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function listRepositoryTree(array $credentials, string $project, ?string $path = null, ?string $ref = null, bool $recursive = false): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = ['recursive' => $recursive ? 'true' : 'false'];
        if ($path !== null) {
            $query['path'] = $path;
        }
        if ($ref !== null) {
            $query['ref'] = $ref;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/tree', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    // Tags, Branches & Commits

    public function listTags(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/tags', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function createTag(array $credentials, string $project, string $tagName, string $ref, ?string $message = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [
            'tag_name' => $tagName,
            'ref' => $ref,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/repository/tags', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function deleteTag(array $credentials, string $project, string $tagName): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('DELETE', $url . '/api/v4/projects/' . $projectId . '/repository/tags/' . urlencode($tagName), [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        // DELETE typically returns empty content with 204 status
        $statusCode = $response->getStatusCode();
        return ['status' => $statusCode === 204 ? 'deleted' : 'success'];
    }

    public function listBranches(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/branches', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getCommit(array $credentials, string $project, string $sha): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/commits/' . $sha, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function listCommits(array $credentials, string $project, ?string $refName = null, int $perPage = 20): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = ['per_page' => $perPage];
        if ($refName !== null) {
            $query['ref_name'] = $refName;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/commits', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    // Merge Requests (Read)

    public function searchMergeRequests(array $credentials, string $project, ?string $state = null, ?string $scope = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($state !== null) {
            $query['state'] = $state;
        }
        if ($scope !== null) {
            $query['scope'] = $scope;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getMergeRequest(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getMergeRequestChanges(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/changes', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getMergeRequestDiscussions(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/discussions', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getMergeRequestCommits(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/commits', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    // Merge Requests (Write)

    public function createMergeRequest(array $credentials, string $project, string $sourceBranch, string $targetBranch, string $title, ?string $description = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [
            'source_branch' => $sourceBranch,
            'target_branch' => $targetBranch,
            'title' => $title,
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function updateMergeRequest(array $credentials, string $project, int $mergeRequestIid, ?string $title = null, ?string $description = null, ?string $stateEvent = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [];
        if ($title !== null) {
            $payload['title'] = $title;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }
        if ($stateEvent !== null) {
            $payload['state_event'] = $stateEvent;
        }

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function addMergeRequestNote(array $credentials, string $project, int $mergeRequestIid, string $body): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['body' => $body];

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/notes', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function mergeMergeRequest(
        array $credentials,
        string $project,
        int $mergeRequestIid,
        ?string $mergeCommitMessage = null,
        ?bool $squash = null,
        ?bool $shouldRemoveSourceBranch = null,
        ?bool $mergeWhenPipelineSucceeds = null
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [];
        if ($mergeCommitMessage !== null) {
            $payload['merge_commit_message'] = $mergeCommitMessage;
        }
        if ($squash !== null) {
            $payload['squash'] = $squash;
        }
        if ($shouldRemoveSourceBranch !== null) {
            $payload['should_remove_source_branch'] = $shouldRemoveSourceBranch;
        }
        if ($mergeWhenPipelineSucceeds !== null) {
            $payload['merge_when_pipeline_succeeds'] = $mergeWhenPipelineSucceeds;
        }

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/merge', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function approveMergeRequest(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/approve', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function unapproveMergeRequest(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/unapprove', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        // Unapprove typically returns 201 with empty or minimal content
        $statusCode = $response->getStatusCode();
        return ['status' => $statusCode === 201 ? 'unapproved' : 'success'];
    }

    public function getMergeRequestApprovals(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/approvals', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function listMergeRequestPipelines(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/pipelines', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function createMergeRequestPipeline(array $credentials, string $project, int $mergeRequestIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/pipelines', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function updateMergeRequestAssignees(
        array $credentials,
        string $project,
        int $mergeRequestIid,
        ?array $assigneeIds = null,
        ?array $reviewerIds = null
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [];
        if ($assigneeIds !== null) {
            $payload['assignee_ids'] = $assigneeIds;
        }
        if ($reviewerIds !== null) {
            $payload['reviewer_ids'] = $reviewerIds;
        }

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function listMyMergeRequests(
        array $credentials,
        string $scope = 'assigned_to_me',
        string $state = 'opened',
        ?string $search = null,
        ?string $labels = null,
        ?string $order_by = null,
        ?string $sort = null,
        int $per_page = 20
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);

        $query = [
            'scope' => $scope,
            'state' => $state,
            'per_page' => min($per_page, 100), // Ensure max 100
        ];

        if ($search !== null) {
            $query['search'] = $search;
        }
        if ($labels !== null) {
            $query['labels'] = $labels;
        }
        if ($order_by !== null) {
            $query['order_by'] = $order_by;
        }
        if ($sort !== null) {
            $query['sort'] = $sort;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/merge_requests', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    // Issues

    public function searchIssues(array $credentials, string $project, ?string $state = null, ?string $scope = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($state !== null) {
            $query['state'] = $state;
        }
        if ($scope !== null) {
            $query['scope'] = $scope;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/issues', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getIssue(array $credentials, string $project, int $issueIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/issues/' . $issueIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function createIssue(array $credentials, string $project, string $title, ?string $description = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['title' => $title];
        if ($description !== null) {
            $payload['description'] = $description;
        }

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/issues', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function updateIssue(array $credentials, string $project, int $issueIid, ?string $title = null, ?string $description = null, ?string $stateEvent = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = [];
        if ($title !== null) {
            $payload['title'] = $title;
        }
        if ($description !== null) {
            $payload['description'] = $description;
        }
        if ($stateEvent !== null) {
            $payload['state_event'] = $stateEvent;
        }

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/issues/' . $issueIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    // Packages

    public function searchPackages(array $credentials, string $project, ?string $packageName = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($packageName !== null) {
            $query['package_name'] = $packageName;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/packages', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getPackage(array $credentials, string $project, int $packageId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/packages/' . $packageId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    // CI/CD

    public function listPipelines(array $credentials, string $project, ?string $ref = null, ?string $status = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($ref !== null) {
            $query['ref'] = $ref;
        }
        if ($status !== null) {
            $query['status'] = $status;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/pipelines', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getPipeline(array $credentials, string $project, int $pipelineId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/pipelines/' . $pipelineId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function getPipelineJobs(array $credentials, string $project, int $pipelineId, ?array $scope = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($scope !== null) {
            $query['scope'] = $scope;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/pipelines/' . $pipelineId . '/jobs', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getJobTrace(array $credentials, string $project, int $jobId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/jobs/' . $jobId . '/trace', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        // Get full trace content
        $fullTrace = $response->getContent();

        // Split into lines and count
        $lines = explode("\n", $fullTrace);
        $totalLines = count($lines);

        // Get max lines from environment (default: 500)
        $maxLines = (int)($_ENV['GITLAB_JOB_TRACE_MAX_LINES'] ?? 500);

        // Limit to last N lines if trace exceeds limit
        $truncated = $totalLines > $maxLines;
        if ($truncated) {
            $lines = array_slice($lines, -$maxLines);
        }
        $returnedLines = count($lines);

        // Reconstruct trace from limited lines
        $limitedTrace = implode("\n", $lines);

        return [
            'job_id' => $jobId,
            'trace' => $limitedTrace,
            'metadata' => [
                'total_lines' => $totalLines,
                'returned_lines' => $returnedLines,
                'truncated' => $truncated,
            ],
        ];
    }

    public function getJob(array $credentials, string $project, int $jobId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/jobs/' . $jobId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function retryPipeline(array $credentials, string $project, int $pipelineId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/pipelines/' . $pipelineId . '/retry', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function cancelPipeline(array $credentials, string $project, int $pipelineId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/pipelines/' . $pipelineId . '/cancel', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function createPipeline(array $credentials, string $project, string $ref, ?array $variables = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['ref' => $ref];
        if ($variables !== null) {
            $payload['variables'] = $variables;
        }

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/pipeline', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function retryJob(array $credentials, string $project, int $jobId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/jobs/' . $jobId . '/retry', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function cancelJob(array $credentials, string $project, int $jobId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/jobs/' . $jobId . '/cancel', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function downloadJobArtifacts(array $credentials, string $project, int $jobId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/jobs/' . $jobId . '/artifacts', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        // Artifacts are returned as binary data
        $content = $response->getContent();

        return [
            'artifacts_file' => base64_encode($content),
            'filename' => 'artifacts.zip',
            'size' => strlen($content),
        ];
    }

    // Repository Comparison & File Management

    public function compareBranches(array $credentials, string $project, string $from, string $to, ?bool $straight = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = ['from' => $from, 'to' => $to];
        if ($straight !== null) {
            $query['straight'] = $straight ? 'true' : 'false';
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/repository/compare', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function createFile(array $credentials, string $project, string $filePath, string $branch, string $content, string $commitMessage): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);
        $encodedPath = urlencode($filePath);

        $payload = [
            'branch' => $branch,
            'content' => $content,
            'commit_message' => $commitMessage,
        ];

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/repository/files/' . $encodedPath, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function updateFile(array $credentials, string $project, string $filePath, string $branch, string $content, string $commitMessage): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);
        $encodedPath = urlencode($filePath);

        $payload = [
            'branch' => $branch,
            'content' => $content,
            'commit_message' => $commitMessage,
        ];

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/repository/files/' . $encodedPath, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function deleteFile(array $credentials, string $project, string $filePath, string $branch, string $commitMessage): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);
        $encodedPath = urlencode($filePath);

        $payload = [
            'branch' => $branch,
            'commit_message' => $commitMessage,
        ];

        $response = $this->httpClient->request('DELETE', $url . '/api/v4/projects/' . $projectId . '/repository/files/' . $encodedPath, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        return ['status' => $statusCode === 204 ? 'deleted' : 'success'];
    }

    public function protectBranch(array $credentials, string $project, string $branch, ?int $pushAccessLevel = null, ?int $mergeAccessLevel = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['name' => $branch];
        if ($pushAccessLevel !== null) {
            $payload['push_access_level'] = $pushAccessLevel;
        }
        if ($mergeAccessLevel !== null) {
            $payload['merge_access_level'] = $mergeAccessLevel;
        }

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/protected_branches', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    // Branch Management

    public function createBranch(array $credentials, string $project, string $branch, string $ref): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['branch' => $branch, 'ref' => $ref];

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/repository/branches', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function deleteBranch(array $credentials, string $project, string $branch): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('DELETE', $url . '/api/v4/projects/' . $projectId . '/repository/branches/' . urlencode($branch), [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        $statusCode = $response->getStatusCode();
        return ['status' => $statusCode === 204 ? 'deleted' : 'success'];
    }

    // Issue Enhancements

    public function addIssueNote(array $credentials, string $project, int $issueIid, string $body): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['body' => $body];

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/issues/' . $issueIid . '/notes', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function listIssueNotes(array $credentials, string $project, int $issueIid): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/issues/' . $issueIid . '/notes', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function updateIssueAssignees(array $credentials, string $project, int $issueIid, array $assigneeIds): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['assignee_ids' => $assigneeIds];

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/issues/' . $issueIid, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function listAllIssues(array $credentials, string $scope = 'assigned_to_me', string $state = 'opened', ?string $search = null, int $perPage = 20): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);

        $query = [
            'scope' => $scope,
            'state' => $state,
            'per_page' => min($perPage, 100),
        ];

        if ($search !== null) {
            $query['search'] = $search;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/issues', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    // Discussion Threads

    public function createDiscussion(array $credentials, string $project, int $mergeRequestIid, string $body): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['body' => $body];

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/discussions', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function resolveDiscussion(array $credentials, string $project, int $mergeRequestIid, string $discussionId, bool $resolved): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['resolved' => $resolved];

        $response = $this->httpClient->request('PUT', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/discussions/' . $discussionId, [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    public function addDiscussionNote(array $credentials, string $project, int $mergeRequestIid, string $discussionId, string $body): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $payload = ['body' => $body];

        $response = $this->httpClient->request('POST', $url . '/api/v4/projects/' . $projectId . '/merge_requests/' . $mergeRequestIid . '/discussions/' . $discussionId . '/notes', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    // Labels & Milestones

    public function listLabels(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/labels', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function listMilestones(array $credentials, string $project, ?string $state = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $query = [];
        if ($state !== null) {
            $query['state'] = $state;
        }

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/milestones', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }

    public function getMilestoneIssues(array $credentials, string $project, int $milestoneId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/milestones/' . $milestoneId . '/issues', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    // Users & Project Members

    public function listProjectMembers(array $credentials, string $project): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);
        $projectId = $this->parseProjectParameter($project);

        $response = $this->httpClient->request('GET', $url . '/api/v4/projects/' . $projectId . '/members', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
        ]);

        return $response->toArray();
    }

    public function searchUsers(array $credentials, string $search, int $perPage = 20): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['gitlab_url']);

        $query = [
            'search' => $search,
            'per_page' => $perPage,
        ];

        $response = $this->httpClient->request('GET', $url . '/api/v4/users', [
            'headers' => $this->getAuthHeaders($credentials['api_token']),
            'query' => $query,
        ]);

        return $response->toArray();
    }
}
