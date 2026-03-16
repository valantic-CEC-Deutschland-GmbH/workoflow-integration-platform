<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\UrlNormalizer;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

class JiraService
{
    /**
     * Fields to fetch for list/search operations (minimal for AI agents)
     * Reduces response size from ~18KB/issue to ~1-2KB/issue
     */
    private const AI_FRIENDLY_LIST_FIELDS = [
        'summary',
        'status',
        'assignee',
        'reporter',
        'priority',
        'issuetype',
        'project',
        'created',
        'updated',
        'duedate',
        'labels',
        'parent',
        'customfield_10020', // Sprint field
    ];

    /**
     * Fields to fetch for detailed single issue view
     * Includes description, comments, attachments, links, subtasks
     */
    private const AI_FRIENDLY_DETAIL_FIELDS = [
        'summary',
        'status',
        'assignee',
        'reporter',
        'priority',
        'issuetype',
        'project',
        'created',
        'updated',
        'duedate',
        'labels',
        'description',
        'comment',
        'attachment',
        'issuelinks',
        'subtasks',
        'parent',
        'fixVersions',
        'components',
        'resolution',
        'customfield_10020', // Sprint field
    ];

    /**
     * Maximum length for description text in detailed view
     */
    private const MAX_DESCRIPTION_LENGTH = 5000;

    /**
     * Maximum number of comments to include in detailed view
     */
    private const MAX_COMMENTS = 10;

    /**
     * Maximum length for each comment body
     */
    private const MAX_COMMENT_LENGTH = 1000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private UrlNormalizer $urlNormalizer
    ) {
    }

    /**
     * Map a Jira issue to AI-friendly flat format for list views
     * Reduces ~18KB per issue to ~1-2KB
     *
     * @param array $issue Raw Jira issue data
     * @param array|null $additionalFields Custom field IDs to include in _rawFields
     * @return array Flattened AI-friendly format
     */
    private function mapIssueToAIFormat(array $issue, ?array $additionalFields = null): array
    {
        $fields = $issue['fields'] ?? [];

        $mapped = [
            'key' => $issue['key'] ?? '',
            'summary' => $fields['summary'] ?? '',
            'status' => $fields['status']['name'] ?? '',
            'statusCategory' => $fields['status']['statusCategory']['name'] ?? '',
            'priority' => $fields['priority']['name'] ?? '',
            'issueType' => $fields['issuetype']['name'] ?? '',
            'projectKey' => $fields['project']['key'] ?? '',
        ];

        // Add assignee info (flattened)
        if (!empty($fields['assignee'])) {
            $mapped['assignee'] = $fields['assignee']['displayName'] ?? '';
            $mapped['assigneeId'] = $fields['assignee']['accountId'] ?? '';
        } else {
            $mapped['assignee'] = null;
            $mapped['assigneeId'] = null;
        }

        // Add reporter info (flattened)
        if (!empty($fields['reporter'])) {
            $mapped['reporter'] = $fields['reporter']['displayName'] ?? '';
        }

        // Add dates (simplified format)
        if (!empty($fields['created'])) {
            $mapped['created'] = substr($fields['created'], 0, 10);
        }
        if (!empty($fields['updated'])) {
            $mapped['updated'] = substr($fields['updated'], 0, 10);
        }
        if (!empty($fields['duedate'])) {
            $mapped['dueDate'] = $fields['duedate'];
        }

        // Add labels (simple array)
        if (!empty($fields['labels'])) {
            $mapped['labels'] = $fields['labels'];
        }

        // Add parent key if exists
        if (!empty($fields['parent']['key'])) {
            $mapped['parentKey'] = $fields['parent']['key'];
        }

        // Add sprint info if available
        if (!empty($fields['customfield_10020']) && is_array($fields['customfield_10020'])) {
            $activeSprint = null;
            foreach ($fields['customfield_10020'] as $sprint) {
                if (($sprint['state'] ?? '') === 'active') {
                    $activeSprint = $sprint;
                    break;
                }
            }
            if ($activeSprint) {
                $mapped['sprint'] = $activeSprint['name'] ?? '';
                $mapped['sprintId'] = $activeSprint['id'] ?? null;
            }
        }

        // Add raw custom fields if requested
        if (!empty($additionalFields)) {
            $rawFields = [];
            foreach ($additionalFields as $fieldId) {
                if (array_key_exists($fieldId, $fields)) {
                    $rawFields[$fieldId] = $fields[$fieldId];
                }
            }
            if (!empty($rawFields)) {
                $mapped['_rawFields'] = $rawFields;
            }
        }

        return $mapped;
    }

    /**
     * Map a Jira issue to AI-friendly format for detailed single issue view
     * Includes description, comments, attachments, links, subtasks
     *
     * @param array $issue Raw Jira issue data
     * @param string $baseUrl Jira base URL for constructing web links
     * @param array|null $additionalFields Custom field IDs to include in _rawFields
     * @return array Detailed AI-friendly format
     */
    private function mapDetailedIssueToAIFormat(array $issue, string $baseUrl, ?array $additionalFields = null): array
    {
        $fields = $issue['fields'] ?? [];

        // Start with the basic mapping (pass additionalFields for _rawFields)
        $mapped = $this->mapIssueToAIFormat($issue, $additionalFields);

        // Add web URL
        $mapped['webUrl'] = $baseUrl . '/browse/' . ($issue['key'] ?? '');

        // Add description (converted to plain text, truncated)
        if (!empty($fields['description'])) {
            $descriptionText = $this->convertADFToPlainText($fields['description']);
            if (strlen($descriptionText) > self::MAX_DESCRIPTION_LENGTH) {
                $descriptionText = substr($descriptionText, 0, self::MAX_DESCRIPTION_LENGTH) . '...';
                $mapped['descriptionTruncated'] = true;
            }
            $mapped['description'] = $descriptionText;
        } else {
            $mapped['description'] = '';
        }

        // Add assignee email
        if (!empty($fields['assignee']['emailAddress'])) {
            $mapped['assigneeEmail'] = $fields['assignee']['emailAddress'];
        }

        // Add components
        if (!empty($fields['components'])) {
            $mapped['components'] = array_map(
                fn($c) => $c['name'] ?? '',
                $fields['components']
            );
        }

        // Add fix versions
        if (!empty($fields['fixVersions'])) {
            $mapped['fixVersions'] = array_map(
                fn($v) => $v['name'] ?? '',
                $fields['fixVersions']
            );
        }

        // Add resolution
        if (!empty($fields['resolution'])) {
            $mapped['resolution'] = $fields['resolution']['name'] ?? '';
        }

        // Add subtasks (key, summary, status only)
        if (!empty($fields['subtasks'])) {
            $mapped['subtasks'] = array_map(
                fn($st) => [
                    'key' => $st['key'] ?? '',
                    'summary' => $st['fields']['summary'] ?? '',
                    'status' => $st['fields']['status']['name'] ?? '',
                ],
                $fields['subtasks']
            );
        }

        // Add linked issues (simplified)
        if (!empty($fields['issuelinks'])) {
            $mapped['linkedIssues'] = [];
            foreach ($fields['issuelinks'] as $link) {
                $linkType = $link['type']['name'] ?? '';
                if (!empty($link['inwardIssue'])) {
                    $mapped['linkedIssues'][] = [
                        'type' => $link['type']['inward'] ?? $linkType,
                        'key' => $link['inwardIssue']['key'] ?? '',
                        'summary' => $link['inwardIssue']['fields']['summary'] ?? '',
                    ];
                }
                if (!empty($link['outwardIssue'])) {
                    $mapped['linkedIssues'][] = [
                        'type' => $link['type']['outward'] ?? $linkType,
                        'key' => $link['outwardIssue']['key'] ?? '',
                        'summary' => $link['outwardIssue']['fields']['summary'] ?? '',
                    ];
                }
            }
        }

        // Add comments (last N, plain text, truncated)
        if (!empty($fields['comment']['comments'])) {
            $comments = $fields['comment']['comments'];
            // Take last N comments
            $comments = array_slice($comments, -self::MAX_COMMENTS);
            $mapped['comments'] = array_map(function ($c) {
                $body = '';
                if (!empty($c['body'])) {
                    $body = $this->convertADFToPlainText($c['body']);
                    if (strlen($body) > self::MAX_COMMENT_LENGTH) {
                        $body = substr($body, 0, self::MAX_COMMENT_LENGTH) . '...';
                    }
                }
                return [
                    'author' => $c['author']['displayName'] ?? '',
                    'created' => isset($c['created']) ? substr($c['created'], 0, 10) : '',
                    'body' => $body,
                ];
            }, $comments);
            $mapped['totalComments'] = $fields['comment']['total'] ?? count($comments);
        }

        // Add attachments (metadata only)
        if (!empty($fields['attachment'])) {
            $mapped['attachments'] = array_map(
                fn($a) => [
                    'filename' => $a['filename'] ?? '',
                    'size' => $a['size'] ?? 0,
                    'mimeType' => $a['mimeType'] ?? '',
                ],
                $fields['attachment']
            );
        }

        return $mapped;
    }

    /**
     * Convert Atlassian Document Format (ADF) to plain text
     *
     * @param mixed $adf ADF document structure
     * @return string Plain text content
     */
    private function convertADFToPlainText(mixed $adf): string
    {
        if (is_string($adf)) {
            return $adf;
        }

        if (!is_array($adf)) {
            return '';
        }

        return $this->extractTextFromADFNode($adf);
    }

    /**
     * Recursively extract text from an ADF node
     *
     * @param array $node ADF node
     * @return string Extracted text
     */
    private function extractTextFromADFNode(array $node): string
    {
        $text = '';

        // If this node has a text property, use it
        if (isset($node['text'])) {
            return $node['text'];
        }

        // Process content array
        if (!empty($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $text .= $this->extractTextFromADFNode($child);
            }
        }

        // Add line breaks for block-level elements
        $type = $node['type'] ?? '';
        if (in_array($type, ['paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'codeBlock', 'rule'])) {
            $text .= "\n";
        }

        return $text;
    }

    private function validateAndNormalizeUrl(string $url): string
    {
        return $this->urlNormalizer->normalize($url, requireHttps: true);
    }

    public function testConnection(array $credentials): bool
    {
        $result = $this->testConnectionDetailed($credentials);
        return $result['success'];
    }

    /**
     * Test Jira connection with detailed error reporting
     *
     * @param array $credentials Jira credentials
     * @return array Detailed test result with success, message, details, and suggestions
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Validate and normalize URL
            $url = $this->validateAndNormalizeUrl($credentials['url']);

            // Test authentication endpoint
            try {
                $response = $this->httpClient->request('GET', $url . '/rest/api/3/myself', [
                    'auth_basic' => [$credentials['username'], $credentials['api_token']],
                    'timeout' => 10,
                ]);

                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/rest/api/3/myself',
                    'status' => $statusCode === 200 ? 'success' : 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 200) {
                    // Successfully authenticated
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'details' => 'Successfully connected to Jira. API authentication verified.',
                        'suggestion' => '',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    // Non-200 response (should not happen as HttpClient throws exception)
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: Unexpected response from Jira API",
                        'suggestion' => 'Check the error details and verify your Jira instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            /** @phpstan-ignore-next-line catch.neverThrown */
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $testedEndpoints[] = [
                    'endpoint' => '/rest/api/3/myself',
                    'status' => 'failed',
                    'http_code' => $statusCode
                ];

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'message' => 'Authentication failed',
                        'details' => 'Invalid email or API token. Please verify your credentials.',
                        'suggestion' => 'Check that your email and API token are correct. Create a new API token at https://id.atlassian.com/manage-profile/security/api-tokens',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 403) {
                    return [
                        'success' => false,
                        'message' => 'Access forbidden',
                        'details' => 'Your API token does not have sufficient permissions.',
                        'suggestion' => 'Ensure the API token has read and write access to Jira projects and issues.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } elseif ($statusCode === 404) {
                    return [
                        'success' => false,
                        'message' => 'API endpoint not found',
                        'details' => 'The URL may not be a valid Jira instance or the API is not available.',
                        'suggestion' => 'Verify the URL points to a Jira Cloud instance (e.g., https://your-domain.atlassian.net)',
                        'tested_endpoints' => $testedEndpoints
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Connection failed',
                        'details' => "HTTP {$statusCode}: " . $e->getMessage(),
                        'suggestion' => 'Check the error details and verify your Jira instance is accessible.',
                        'tested_endpoints' => $testedEndpoints
                    ];
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                return [
                    'success' => false,
                    'message' => 'Cannot reach Jira server',
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
                'suggestion' => 'Enter a valid Jira URL like https://your-domain.atlassian.net',
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

    public function search(array $credentials, string $jql, int $maxResults = 50, ?array $additionalFields = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Default to ordering by created date if JQL is empty
        if (empty(trim($jql))) {
            $jql = 'ORDER BY created DESC';
        }

        // Build fields list including any additional custom fields
        $fieldsToFetch = self::AI_FRIENDLY_LIST_FIELDS;
        if (!empty($additionalFields)) {
            $fieldsToFetch = array_unique(array_merge($fieldsToFetch, $additionalFields));
        }

        try {
            // Use /search/jql endpoint (the /search endpoint was deprecated and removed)
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/search/jql', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'jql' => $jql,
                    'maxResults' => $maxResults,
                    'fields' => implode(',', $fieldsToFetch),
                ],
            ]);

            $data = $response->toArray();

            // Transform issues to AI-friendly format (pass additionalFields for _rawFields)
            if (!empty($data['issues'])) {
                $data['issues'] = array_map(
                    fn($issue) => $this->mapIssueToAIFormat($issue, $additionalFields),
                    $data['issues']
                );
            }

            return $data;
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);  // Don't throw on error status codes

            // Extract Jira's error messages
            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';

            // Build comprehensive error message
            $errorText = '';
            if (!empty($errorMessages)) {
                $errorText = implode(', ', $errorMessages);
            } elseif (!empty($errors)) {
                $errorText = json_encode($errors);
            } else {
                $errorText = $message;
            }

            // Add context-specific suggestions
            $suggestion = '';
            if (stripos($errorText, 'unbounded') !== false || stripos($errorText, 'restriction') !== false) {
                $suggestion = ' - Add filtering conditions like project, status, or assignee to your JQL query';
            } elseif (stripos($errorText, 'syntax') !== false || stripos($errorText, 'invalid') !== false) {
                $suggestion = ' - Check JQL syntax at https://support.atlassian.com/jira-service-management-cloud/docs/use-advanced-search-with-jira-query-language-jql/';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get raw Jira issue data for internal use
     * This method returns the raw API response, used internally for operations
     * that need access to field IDs and structured data.
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key
     * @param array|null $fields Optional specific fields to request
     * @return array Raw Jira issue data
     */
    private function getIssueRaw(array $credentials, string $issueKey, ?array $fields = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $query = [];
            if ($fields !== null) {
                $query['fields'] = implode(',', $fields);
            }

            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/' . $issueKey, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => $query ?: null,
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Verify issue key '{$issueKey}' exists on {$url} and you have permission to view it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to access this issue';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function getIssue(array $credentials, string $issueKey, ?array $additionalFields = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Build fields list including any additional custom fields
        $fieldsToFetch = self::AI_FRIENDLY_DETAIL_FIELDS;
        if (!empty($additionalFields)) {
            $fieldsToFetch = array_unique(array_merge($fieldsToFetch, $additionalFields));
        }

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/' . $issueKey, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'fields' => implode(',', $fieldsToFetch),
                ],
            ]);

            $issue = $response->toArray();

            // Transform to AI-friendly detailed format (pass additionalFields for _rawFields)
            return $this->mapDetailedIssueToAIFormat($issue, $url, $additionalFields);
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Verify issue key '{$issueKey}' exists on {$url} and you have permission to view it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to access this issue';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function getSprintsFromBoard(array $credentials, int $boardId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/board/' . $boardId . '/sprint', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'startAt' => 0,
                    'maxResults' => 50,
                ],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';

            // Check if error indicates Kanban board (sprints not supported)
            if (
                stripos($errorText, 'sprint') !== false &&
                (stripos($errorText, 'nicht unterstützt') !== false ||
                 stripos($errorText, 'not support') !== false ||
                 stripos($errorText, 'does not support') !== false)
            ) {
                // Try to get board details to confirm it's a Kanban board
                try {
                    $board = $this->getBoard($credentials, $boardId);
                    $boardType = $board['type'] ?? 'unknown';
                    $boardName = $board['name'] ?? "Board {$boardId}";

                    if ($boardType === 'kanban') {
                        throw new \RuntimeException(
                            "This is a Kanban board ('{$boardName}') which does not support sprints. " .
                            "Use jira_get_kanban_issues or jira_get_board_issues instead to get issues from this board.",
                            400
                        );
                    }
                } catch (\RuntimeException $boardError) {
                    // If we can't get board details, re-throw the board error if it's our Kanban message
                    if (stripos($boardError->getMessage(), 'Kanban board') !== false) {
                        throw $boardError;
                    }
                    // Otherwise continue with original error handling below
                }

                $suggestion = ' - This board may be a Kanban board. Kanban boards do not have sprints. Use jira_get_kanban_issues or jira_get_board_issues instead.';
            } elseif ($statusCode === 404 || $statusCode === 400) {
                $suggestion = " - Board ID {$boardId} may not exist or you don't have permission to access it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has Jira Software/Agile permissions';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function getSprintIssues(
        array $credentials,
        int $sprintId,
        int $maxResults = 100,
        ?string $assignee = null,
        ?string $jql = null,
        ?array $additionalFields = null
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Build fields list including any additional custom fields
        $fieldsToFetch = self::AI_FRIENDLY_LIST_FIELDS;
        if (!empty($additionalFields)) {
            $fieldsToFetch = array_unique(array_merge($fieldsToFetch, $additionalFields));
        }

        // Build query with optional JQL filtering
        $query = [
            'maxResults' => $maxResults,
            'fields' => implode(',', $fieldsToFetch),
        ];

        $jqlParts = [];
        if ($assignee !== null) {
            $jqlParts[] = "assignee = \"{$assignee}\"";
        }
        if ($jql !== null) {
            $jqlParts[] = "({$jql})";
        }
        if (!empty($jqlParts)) {
            $query['jql'] = implode(' AND ', $jqlParts);
        }

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/sprint/' . $sprintId . '/issue', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => $query,
            ]);

            $data = $response->toArray();

            // Transform issues to AI-friendly format (pass additionalFields for _rawFields)
            if (!empty($data['issues'])) {
                $data['issues'] = array_map(
                    fn($issue) => $this->mapIssueToAIFormat($issue, $additionalFields),
                    $data['issues']
                );
            }

            return $data;
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404 || $statusCode === 400) {
                $suggestion = " - Sprint ID {$sprintId} may not exist or you don't have permission to access it";
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    public function addComment(array $credentials, string $issueKey, string $comment): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        $payload = [
            'body' => [
                'type' => 'doc',
                'version' => 1,
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'text' => $comment,
                                'type' => 'text'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', $url . '/rest/api/3/issue/' . $issueKey . '/comment', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Issue '{$issueKey}' may not exist";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to add comments';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get available transitions for an issue
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @return array Available transitions with IDs and target statuses
     */
    public function getAvailableTransitions(array $credentials, string $issueKey): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/' . $issueKey . '/transitions', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Issue '{$issueKey}' may not exist or you don't have permission to view it";
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Transition an issue to a new status
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @param string $transitionId Transition ID (from getAvailableTransitions)
     * @param string|null $comment Optional comment to add during transition
     * @return array Transition response
     */
    public function transitionIssue(array $credentials, string $issueKey, string $transitionId, ?string $comment = null): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        $payload = [
            'transition' => [
                'id' => $transitionId
            ]
        ];

        // Add comment if provided
        if ($comment !== null) {
            $payload['update'] = [
                'comment' => [
                    [
                        'add' => [
                            'body' => [
                                'type' => 'doc',
                                'version' => 1,
                                'content' => [
                                    [
                                        'type' => 'paragraph',
                                        'content' => [
                                            [
                                                'text' => $comment,
                                                'type' => 'text'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url . '/rest/api/3/issue/' . $issueKey . '/transitions', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            // Transitions return 204 No Content on success
            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                return ['success' => true, 'message' => 'Issue transitioned successfully'];
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 400) {
                $suggestion = " - Transition ID '{$transitionId}' may not be valid for this issue's current status";
            } elseif (stripos($errorText, 'required') !== false || !empty($errors)) {
                $suggestion = ' - Some required fields may be missing for this transition';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Transition an issue to a target status by automatically finding the path
     *
     * This method intelligently navigates through JIRA workflows to reach the target status.
     * It will perform multiple transitions if necessary.
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @param string $targetStatusName Target status name (e.g., "Done", "In Progress")
     * @param string|null $comment Optional comment to add during final transition
     * @param int $maxAttempts Maximum number of transition attempts to prevent infinite loops
     * @return array Result with success status and path taken
     */
    public function transitionIssueToStatus(
        array $credentials,
        string $issueKey,
        string $targetStatusName,
        ?string $comment = null,
        int $maxAttempts = 10
    ): array {
        $path = [];
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // Get current issue status (now returns AI-friendly format with 'status' directly)
            $issue = $this->getIssue($credentials, $issueKey);
            $currentStatus = $issue['status'];

            // Check if we've reached the target
            if (strcasecmp($currentStatus, $targetStatusName) === 0) {
                return [
                    'success' => true,
                    'message' => "Issue successfully transitioned to '{$targetStatusName}'",
                    'currentStatus' => $currentStatus,
                    'path' => $path,
                    'attempts' => $attempt - 1
                ];
            }

            // Get available transitions
            $transitions = $this->getAvailableTransitions($credentials, $issueKey);

            if (empty($transitions['transitions'])) {
                return [
                    'success' => false,
                    'message' => "No transitions available from status '{$currentStatus}'",
                    'currentStatus' => $currentStatus,
                    'path' => $path,
                    'attempts' => $attempt
                ];
            }

            // Look for direct transition to target status
            $targetTransition = null;
            foreach ($transitions['transitions'] as $transition) {
                if (strcasecmp($transition['to']['name'], $targetStatusName) === 0) {
                    $targetTransition = $transition;
                    break;
                }
            }

            // If direct transition found, use it
            if ($targetTransition !== null) {
                $transitionComment = ($comment !== null && strcasecmp($targetTransition['to']['name'], $targetStatusName) === 0)
                    ? $comment
                    : null;

                $this->transitionIssue($credentials, $issueKey, $targetTransition['id'], $transitionComment);

                $path[] = [
                    'from' => $currentStatus,
                    'to' => $targetTransition['to']['name'],
                    'transitionId' => $targetTransition['id'],
                    'transitionName' => $targetTransition['name']
                ];

                continue;
            }

            // No direct path found - try first available transition (heuristic approach)
            // In a more sophisticated version, we could implement path finding algorithms
            $firstTransition = $transitions['transitions'][0];

            // Avoid going backwards or loops
            $isLoop = false;
            foreach ($path as $step) {
                if ($step['to'] === $firstTransition['to']['name']) {
                    $isLoop = true;
                    break;
                }
            }

            if ($isLoop) {
                return [
                    'success' => false,
                    'message' => "Cannot find path to '{$targetStatusName}' - workflow loop detected",
                    'currentStatus' => $currentStatus,
                    'targetStatus' => $targetStatusName,
                    'path' => $path,
                    'attempts' => $attempt,
                    'availableTransitions' => array_map(
                        fn($t) => $t['to']['name'],
                        $transitions['transitions']
                    )
                ];
            }

            // Make the transition
            $this->transitionIssue($credentials, $issueKey, $firstTransition['id']);

            $path[] = [
                'from' => $currentStatus,
                'to' => $firstTransition['to']['name'],
                'transitionId' => $firstTransition['id'],
                'transitionName' => $firstTransition['name']
            ];
        }

        // Max attempts reached
        $issue = $this->getIssue($credentials, $issueKey);
        $currentStatus = $issue['status'];

        return [
            'success' => false,
            'message' => "Could not reach '{$targetStatusName}' after {$maxAttempts} attempts",
            'currentStatus' => $currentStatus,
            'targetStatus' => $targetStatusName,
            'path' => $path,
            'attempts' => $attempt
        ];
    }

    /**
     * Get board details including type (scrum/kanban)
     *
     * @param array $credentials Jira credentials
     * @param int $boardId Board ID
     * @return array Board details with id, name, type (scrum/kanban), and location info
     */
    public function getBoard(array $credentials, int $boardId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/board/' . $boardId, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Board ID {$boardId} does not exist or you don't have permission to access it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has Jira Software/Agile permissions';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get issues from any board type (Scrum or Kanban)
     * Automatically detects board type and uses appropriate method
     *
     * @param array $credentials Jira credentials
     * @param int $boardId Board ID
     * @param int $maxResults Maximum number of results
     * @param string|null $assignee Filter by assignee accountId
     * @param string|null $jql Additional JQL filter
     * @param array|null $additionalFields Custom field IDs to include in _rawFields
     * @return array Issues from the board
     */
    public function getBoardIssues(
        array $credentials,
        int $boardId,
        int $maxResults = 50,
        ?string $assignee = null,
        ?string $jql = null,
        ?array $additionalFields = null
    ): array {
        // First, get board details to determine type
        $board = $this->getBoard($credentials, $boardId);
        $boardType = $board['type'] ?? 'unknown';

        if ($boardType === 'scrum') {
            // For Scrum boards, get issues from active sprint
            $sprints = $this->getSprintsFromBoard($credentials, $boardId);

            // Find active sprint
            $activeSprint = null;
            foreach ($sprints['values'] ?? [] as $sprint) {
                if (($sprint['state'] ?? '') === 'active') {
                    $activeSprint = $sprint;
                    break;
                }
            }

            if ($activeSprint === null) {
                // No active sprint, return empty result
                return [
                    'expand' => '',
                    'startAt' => 0,
                    'maxResults' => $maxResults,
                    'total' => 0,
                    'issues' => [],
                    'boardType' => 'scrum',
                    'message' => 'No active sprint found on this Scrum board'
                ];
            }

            // Get issues from active sprint with optional filters (pass additionalFields)
            $result = $this->getSprintIssues($credentials, $activeSprint['id'], $maxResults, $assignee, $jql, $additionalFields);
            $result['boardType'] = 'scrum';
            $result['sprintId'] = $activeSprint['id'];
            $result['sprintName'] = $activeSprint['name'];

            return $result;
        } elseif ($boardType === 'kanban') {
            // For Kanban boards, get all board issues with optional filters (pass additionalFields)
            $result = $this->getKanbanIssues($credentials, $boardId, $maxResults, $assignee, $jql, $additionalFields);
            $result['boardType'] = 'kanban';

            return $result;
        } else {
            throw new \RuntimeException(
                "Unknown board type '{$boardType}' for board ID {$boardId}. Expected 'scrum' or 'kanban'."
            );
        }
    }

    /**
     * Get issues from a Kanban board
     *
     * @param array $credentials Jira credentials
     * @param int $boardId Kanban board ID
     * @param int $maxResults Maximum number of results
     * @param string|null $assignee Filter by assignee accountId
     * @param string|null $jql Additional JQL filter
     * @param array|null $additionalFields Custom field IDs to include in _rawFields
     * @return array Issues from the Kanban board
     */
    public function getKanbanIssues(
        array $credentials,
        int $boardId,
        int $maxResults = 50,
        ?string $assignee = null,
        ?string $jql = null,
        ?array $additionalFields = null
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Build fields list including any additional custom fields
        $fieldsToFetch = self::AI_FRIENDLY_LIST_FIELDS;
        if (!empty($additionalFields)) {
            $fieldsToFetch = array_unique(array_merge($fieldsToFetch, $additionalFields));
        }

        // Build query with optional JQL filtering
        $query = [
            'maxResults' => $maxResults,
            'fields' => implode(',', $fieldsToFetch),
        ];

        $jqlParts = [];
        if ($assignee !== null) {
            $jqlParts[] = "assignee = \"{$assignee}\"";
        }
        if ($jql !== null) {
            $jqlParts[] = "({$jql})";
        }
        if (!empty($jqlParts)) {
            $query['jql'] = implode(' AND ', $jqlParts);
        }

        try {
            // Use the Jira Agile API to get all issues on the board
            // This endpoint works for both Kanban and Scrum boards
            $response = $this->httpClient->request('GET', $url . '/rest/agile/1.0/board/' . $boardId . '/issue', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => $query,
            ]);

            $data = $response->toArray();

            // Transform issues to AI-friendly format (pass additionalFields for _rawFields)
            if (!empty($data['issues'])) {
                $data['issues'] = array_map(
                    fn($issue) => $this->mapIssueToAIFormat($issue, $additionalFields),
                    $data['issues']
                );
            }

            return $data;
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404 || $statusCode === 400) {
                $suggestion = " - Board ID {$boardId} may not exist, may not be a Kanban board, or you don't have permission to access it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has Jira Software/Agile permissions';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Convert plain text to Atlassian Document Format (ADF)
     * Helper method for comment formatting
     *
     * @param string $text Plain text to convert
     * @return array ADF document structure
     */
    private function convertPlainTextToADF(string $text): array
    {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'text' => $text,
                            'type' => 'text'
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Format a custom field value based on its schema
     *
     * @param mixed $value The value to format
     * @param array $schema The field schema from Jira metadata
     * @return mixed The formatted value
     */
    private function formatCustomFieldValue(mixed $value, array $schema): mixed
    {
        $schemaType = $schema['type'] ?? '';
        $custom = $schema['custom'] ?? '';
        $itemsType = $schema['items'] ?? '';

        // Handle array types (multiselect, labels, etc.)
        if ($schemaType === 'array') {
            // Ensure value is an array
            $values = is_array($value) ? $value : [$value];

            // Array of options (multiselect) - each element needs {"id": value}
            if ($itemsType === 'option') {
                return array_map(fn($v) => is_array($v) ? $v : ['id' => (string) $v], $values);
            }

            // Array of strings (labels, etc.) - pass through
            if ($itemsType === 'string') {
                return array_map('strval', $values);
            }

            // Other array types - pass through as-is
            return $values;
        }

        // Already formatted as array/object for non-array schema - pass through
        if (is_array($value)) {
            return $value;
        }

        // Textarea fields need Atlassian Document Format
        if (str_contains($custom, 'textarea')) {
            return $this->convertPlainTextToADF((string) $value);
        }

        // Option fields (radio buttons, single select) need {"id": value}
        if ($schemaType === 'option') {
            return ['id' => (string) $value];
        }

        // User fields need {"id": accountId}
        if ($schemaType === 'user') {
            return ['id' => (string) $value];
        }

        // Date fields - validate format
        if ($schemaType === 'date') {
            $dateValue = (string) $value;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                throw new \InvalidArgumentException(
                    "Date field must be in YYYY-MM-DD format (e.g., 2025-12-25). Got: {$dateValue}"
                );
            }
            return $dateValue;
        }

        return $value;
    }

    /**
     * Get edit metadata for an issue
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @return array Editable fields metadata with field definitions, allowed values, and required status
     */
    public function getEditMetadata(array $credentials, string $issueKey): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/' . $issueKey . '/editmeta', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Issue '{$issueKey}' may not exist or you don't have permission to view it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to edit this issue';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Delete an issue
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @return array Success status
     */
    public function deleteIssue(array $credentials, string $issueKey): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('DELETE', $url . '/rest/api/3/issue/' . $issueKey, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            // DELETE endpoint returns 204 No Content on success
            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                return [
                    'success' => true,
                    'message' => "Issue '{$issueKey}' deleted successfully"
                ];
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Issue '{$issueKey}' may not exist or was already deleted";
            } elseif ($statusCode === 403 || stripos($errorText, 'permission') !== false) {
                $suggestion = ' - You do not have permission to delete this issue. Check your project role and Jira permissions.';
            } elseif ($statusCode === 400 && stripos($errorText, 'subtask') !== false) {
                $suggestion = ' - This issue has subtasks. Delete subtasks first or use deleteSubtasks=true parameter.';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get available issue link types
     *
     * @param array $credentials Jira credentials
     * @return array Available link types with IDs, names, inward/outward descriptions
     */
    public function getIssueLinkTypes(array $credentials): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issueLinkType', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to view issue link types';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Link two issues together
     *
     * @param array $credentials Jira credentials
     * @param string $inwardIssue Inward issue key (e.g., PROJ-123)
     * @param string $outwardIssue Outward issue key (e.g., PROJ-456)
     * @param string $linkTypeId Link type ID from getIssueLinkTypes
     * @param string|null $comment Optional comment to add with the link
     * @return array Link creation result
     */
    public function linkIssues(
        array $credentials,
        string $inwardIssue,
        string $outwardIssue,
        string $linkTypeId,
        ?string $comment = null
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        $linkPayload = [
            'type' => ['id' => $linkTypeId],
            'inwardIssue' => ['key' => $inwardIssue],
            'outwardIssue' => ['key' => $outwardIssue]
        ];

        if ($comment) {
            $linkPayload['comment'] = [
                'body' => $this->convertPlainTextToADF($comment)
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url . '/rest/api/3/issueLink', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $linkPayload
            ]);

            // Link creation returns 201 Created on success
            $statusCode = $response->getStatusCode();
            if ($statusCode === 201) {
                return [
                    'success' => true,
                    'message' => "Issues linked successfully: '{$inwardIssue}' and '{$outwardIssue}'"
                ];
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - One or both issues ('{$inwardIssue}', '{$outwardIssue}') may not exist";
            } elseif ($statusCode === 400 && stripos($errorText, 'link type') !== false) {
                $suggestion = " - Link type ID '{$linkTypeId}' may not be valid. Use jira_get_issue_link_types to get valid IDs";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to link issues';
            } elseif (stripos($errorText, 'already exists') !== false || stripos($errorText, 'duplicate') !== false) {
                $suggestion = ' - This link may already exist between these issues';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get project metadata including issue types and components
     *
     * @param array $credentials Jira credentials
     * @param string $projectKey Project key (e.g., PROJ, TEST)
     * @return array Project metadata with issue types, components, versions, and project info
     */
    public function getProjectMetadata(array $credentials, string $projectKey): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            // Get basic project info
            $projectResponse = $this->httpClient->request('GET', $url . '/rest/api/3/project/' . $projectKey, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);
            $projectData = $projectResponse->toArray();

            // Get issue types for this project with field information
            $issueTypesResponse = $this->httpClient->request('GET', $url . '/rest/api/3/issue/createmeta/' . $projectKey . '/issuetypes', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);
            $issueTypesData = $issueTypesResponse->toArray();

            // Log warning if no issue types are returned
            if (empty($issueTypesData['issueTypes'])) {
                $this->logger->warning('No issue types returned from Jira API for project', [
                    'project_key' => $projectKey,
                    'response_keys' => array_keys($issueTypesData),
                    'url' => $url,
                ]);
            }

            // Combine the data
            return [
                'id' => $projectData['id'],
                'key' => $projectData['key'],
                'name' => $projectData['name'],
                'description' => $projectData['description'] ?? '',
                'projectTypeKey' => $projectData['projectTypeKey'] ?? '',
                'issueTypes' => $issueTypesData['issueTypes'] ?? [],
                'components' => $projectData['components'] ?? [],
                'versions' => $projectData['versions'] ?? [],
            ];
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Project '{$projectKey}' may not exist or you don't have permission to view it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to view project metadata';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get create field metadata for a specific issue type
     *
     * @param array $credentials Jira credentials
     * @param string $projectKey Project key (e.g., PROJ, TEST)
     * @param string $issueTypeId Issue type ID
     * @return array Field metadata for creating issues of this type
     */
    public function getCreateFieldMetadata(array $credentials, string $projectKey, string $issueTypeId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/issue/createmeta/' . $projectKey . '/issuetypes/' . $issueTypeId, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Project '{$projectKey}' or issue type ID '{$issueTypeId}' may not exist or you don't have permission to access it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to view issue metadata';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Create a new Jira issue
     *
     * @param array $credentials Jira credentials
     * @param array $issueData Issue data including project, issueType, summary, description, etc.
     * @return array Created issue data with key, id, and self URL
     */
    public function createIssue(array $credentials, array $issueData): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Build the fields object
        $fields = [
            'project' => ['key' => $issueData['projectKey']],
            'issuetype' => ['id' => $issueData['issueTypeId']],
            'summary' => $issueData['summary'],
        ];

        // Add description if provided
        if (!empty($issueData['description'])) {
            $fields['description'] = $this->convertPlainTextToADF($issueData['description']);
        }

        // Add optional standard fields
        if (!empty($issueData['priorityId'])) {
            $fields['priority'] = ['id' => $issueData['priorityId']];
        }

        if (!empty($issueData['assigneeId'])) {
            $fields['assignee'] = ['id' => $issueData['assigneeId']];
        }

        if (!empty($issueData['labels']) && is_array($issueData['labels'])) {
            $fields['labels'] = $issueData['labels'];
        }

        if (!empty($issueData['componentIds']) && is_array($issueData['componentIds'])) {
            $fields['components'] = array_map(fn($id) => ['id' => $id], $issueData['componentIds']);
        }

        if (!empty($issueData['dueDate'])) {
            $dueDate = $issueData['dueDate'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                throw new \InvalidArgumentException(
                    "Due date must be in YYYY-MM-DD format (e.g., 2025-12-25). Got: {$dueDate}"
                );
            }
            $fields['duedate'] = $dueDate;
        }

        if (!empty($issueData['reporterId'])) {
            $fields['reporter'] = ['id' => $issueData['reporterId']];
        }

        // Add custom fields with auto-formatting
        if (isset($issueData['customFields']) && is_array($issueData['customFields'])) {
            // Get field metadata to determine proper formatting
            $fieldMetadata = $this->getCreateFieldMetadata(
                $credentials,
                $issueData['projectKey'],
                $issueData['issueTypeId']
            );
            $schemaMap = [];
            foreach ($fieldMetadata['fields'] ?? [] as $field) {
                $schemaMap[$field['fieldId']] = $field['schema'] ?? [];
            }

            foreach ($issueData['customFields'] as $fieldId => $value) {
                $schema = $schemaMap[$fieldId] ?? [];
                $fields[$fieldId] = $this->formatCustomFieldValue($value, $schema);
            }
        }

        $payload = ['fields' => $fields];

        try {
            $response = $this->httpClient->request('POST', $url . '/rest/api/3/issue', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';

            // Build comprehensive error message
            $errorText = '';
            if (!empty($errorMessages)) {
                $errorText = implode(', ', $errorMessages);
            } elseif (!empty($errors)) {
                $errorText = json_encode($errors);
            } else {
                $errorText = $message;
            }

            $suggestion = '';
            if (stripos($errorText, 'required') !== false || !empty($errors)) {
                $suggestion = ' - Some required fields may be missing. Use jira_get_create_field_metadata to see all required fields for this issue type';
            } elseif ($statusCode === 404) {
                $suggestion = ' - Project or issue type may not exist';
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to create issues in this project';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get all available priorities
     *
     * @param array $credentials Jira credentials
     * @return array List of priorities with id, name, description, iconUrl, statusColor
     */
    public function getPriorities(array $credentials): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/priority', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Update an existing Jira issue
     *
     * @param array $credentials Jira credentials (domain, email, apiToken)
     * @param string $issueKey Issue key (e.g., PROJ-123)
     * @param array $updates Fields to update
     * @return array Updated issue information
     */
    public function updateIssue(array $credentials, string $issueKey, array $updates): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Build payload with fields to update
        $updatePayload = ['fields' => []];

        if (isset($updates['summary'])) {
            $updatePayload['fields']['summary'] = $updates['summary'];
        }

        if (isset($updates['description'])) {
            $updatePayload['fields']['description'] = $this->convertPlainTextToADF($updates['description']);
        }

        if (isset($updates['priorityId'])) {
            $updatePayload['fields']['priority'] = ['id' => $updates['priorityId']];
        }

        if (array_key_exists('assigneeId', $updates)) {
            // Handle null assignee for unassignment
            if ($updates['assigneeId'] === null) {
                $updatePayload['fields']['assignee'] = null;
            } else {
                $updatePayload['fields']['assignee'] = ['id' => $updates['assigneeId']];
            }
        }

        if (isset($updates['labels']) && is_array($updates['labels'])) {
            $updatePayload['fields']['labels'] = $updates['labels'];
        }

        if (isset($updates['componentIds']) && is_array($updates['componentIds'])) {
            $updatePayload['fields']['components'] = array_map(fn($id) => ['id' => $id], $updates['componentIds']);
        }

        // Handle custom fields with auto-formatting
        if (isset($updates['customFields']) && is_array($updates['customFields'])) {
            // Get issue details to determine project and issue type for field metadata
            $issue = $this->getIssueRaw($credentials, $issueKey, ['project', 'issuetype']);
            $projectKey = $issue['fields']['project']['key'] ?? null;
            $issueTypeId = $issue['fields']['issuetype']['id'] ?? null;

            $schemaMap = [];
            if ($projectKey && $issueTypeId) {
                $fieldMetadata = $this->getCreateFieldMetadata($credentials, $projectKey, $issueTypeId);
                foreach ($fieldMetadata['fields'] ?? [] as $field) {
                    $schemaMap[$field['fieldId']] = $field['schema'] ?? [];
                }
            }

            foreach ($updates['customFields'] as $fieldId => $value) {
                $schema = $schemaMap[$fieldId] ?? [];
                $updatePayload['fields'][$fieldId] = $this->formatCustomFieldValue($value, $schema);
            }
        }

        try {
            $response = $this->httpClient->request('PUT', $url . '/rest/api/3/issue/' . $issueKey, [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $updatePayload
            ]);

            // Update endpoint returns 204 No Content on success
            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                return [
                    'success' => true,
                    'message' => "Issue '{$issueKey}' updated successfully"
                ];
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';

            // Build comprehensive error message
            $errorText = '';
            if (!empty($errorMessages)) {
                $errorText = implode(', ', $errorMessages);
            } elseif (!empty($errors)) {
                $errorText = json_encode($errors);
            } else {
                $errorText = $message;
            }

            $suggestion = '';
            if ($statusCode === 404) {
                $suggestion = " - Issue '{$issueKey}' may not exist or you don't have permission to view it";
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to edit this issue';
            } elseif (stripos($errorText, 'required') !== false || !empty($errors)) {
                $suggestion = ' - Some required fields may be missing or field values are invalid';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Bulk create issues (up to 50)
     *
     * @param array $credentials Jira credentials
     * @param array $issuesData Array of issue data objects
     * @return array Created issues and any errors
     */
    public function bulkCreateIssues(array $credentials, array $issuesData): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Build issueUpdates array
        $bulkPayload = ['issueUpdates' => []];

        foreach ($issuesData as $issueData) {
            $fields = [
                'project' => ['key' => $issueData['projectKey']],
                'issuetype' => ['id' => $issueData['issueTypeId']],
                'summary' => $issueData['summary'],
            ];

            // Add description if provided
            if (!empty($issueData['description'])) {
                $fields['description'] = $this->convertPlainTextToADF($issueData['description']);
            }

            // Add optional standard fields
            if (!empty($issueData['priorityId'])) {
                $fields['priority'] = ['id' => $issueData['priorityId']];
            }

            if (!empty($issueData['assigneeId'])) {
                $fields['assignee'] = ['id' => $issueData['assigneeId']];
            }

            if (!empty($issueData['labels']) && is_array($issueData['labels'])) {
                $fields['labels'] = $issueData['labels'];
            }

            if (!empty($issueData['componentIds']) && is_array($issueData['componentIds'])) {
                $fields['components'] = array_map(fn($id) => ['id' => $id], $issueData['componentIds']);
            }

            if (!empty($issueData['dueDate'])) {
                $dueDate = $issueData['dueDate'];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                    throw new \InvalidArgumentException(
                        "Due date must be in YYYY-MM-DD format (e.g., 2025-12-25). Got: {$dueDate}"
                    );
                }
                $fields['duedate'] = $dueDate;
            }

            // Add custom fields with auto-formatting
            if (isset($issueData['customFields']) && is_array($issueData['customFields'])) {
                // Get field metadata to determine proper formatting
                $fieldMetadata = $this->getCreateFieldMetadata(
                    $credentials,
                    $issueData['projectKey'],
                    $issueData['issueTypeId']
                );
                $schemaMap = [];
                foreach ($fieldMetadata['fields'] ?? [] as $field) {
                    $schemaMap[$field['fieldId']] = $field['schema'] ?? [];
                }

                foreach ($issueData['customFields'] as $fieldId => $value) {
                    $schema = $schemaMap[$fieldId] ?? [];
                    $fields[$fieldId] = $this->formatCustomFieldValue($value, $schema);
                }
            }

            $bulkPayload['issueUpdates'][] = ['fields' => $fields];
        }

        try {
            $response = $this->httpClient->request('POST', $url . '/rest/api/3/issue/bulk', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $bulkPayload
            ]);

            $result = $response->toArray();
            $issues = $result['issues'] ?? [];
            $errors = $result['errors'] ?? [];
            $hasErrors = count($errors) > 0;
            $hasSuccess = count($issues) > 0;

            // Return both successes and errors with accurate success flag
            return [
                'success' => !$hasErrors,
                'partial' => $hasErrors && $hasSuccess,
                'issues' => $issues,
                'errors' => $errors,
                'totalCreated' => count($issues),
                'totalFailed' => count($errors)
            ];
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $errors = $errorData['errors'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';

            // Build comprehensive error message
            $errorText = '';
            if (!empty($errorMessages)) {
                $errorText = implode(', ', $errorMessages);
            } elseif (!empty($errors)) {
                $errorText = json_encode($errors);
            } else {
                $errorText = $message;
            }

            $suggestion = '';
            if (stripos($errorText, 'required') !== false || !empty($errors)) {
                $suggestion = ' - Some required fields may be missing. Check each issue\'s data structure';
            } elseif ($statusCode === 400 && stripos($errorText, 'limit') !== false) {
                $suggestion = ' - Bulk create is limited to 50 issues per request';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Assign issue to user
     *
     * @param array $credentials Jira credentials
     * @param string $issueKey Issue key
     * @param string|null $accountId User account ID (null to unassign)
     * @return array Success status
     */
    public function assignIssue(array $credentials, string $issueKey, ?string $accountId): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        // Payload: {"accountId": "xxx"} or {"accountId": null}
        $payload = ['accountId' => $accountId];

        try {
            $response = $this->httpClient->request('PUT', $url . '/rest/api/3/issue/' . $issueKey . '/assignee', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            // Assign endpoint returns 204 No Content on success
            $statusCode = $response->getStatusCode();
            if ($statusCode === 204) {
                $message = $accountId === null
                    ? "Issue '{$issueKey}' unassigned successfully"
                    : "Issue '{$issueKey}' assigned successfully";
                return [
                    'success' => true,
                    'message' => $message
                ];
            }

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 404) {
                if (stripos($errorText, 'user') !== false || stripos($errorText, 'account') !== false) {
                    $suggestion = " - User account ID '{$accountId}' may not exist or is not valid";
                } else {
                    $suggestion = " - Issue '{$issueKey}' may not exist or you don't have permission to view it";
                }
            } elseif (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to assign issues';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Search for users
     *
     * @param array $credentials Jira credentials
     * @param string $query Search query
     * @return array Array of users
     */
    public function searchUsers(array $credentials, string $query): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            // API: GET /rest/api/3/user/search?query={query}
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/user/search', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
                'query' => [
                    'query' => $query
                ],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if (stripos($errorText, 'permission') !== false) {
                $suggestion = ' - Check that your API token has permission to browse users';
            } elseif (empty($query)) {
                $suggestion = ' - Search query cannot be empty';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get current authenticated user information
     *
     * @param array $credentials Jira credentials
     * @return array User object with accountId, displayName, emailAddress, etc.
     */
    public function getMyself(array $credentials): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/myself', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            return $response->toArray();
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            $suggestion = '';
            if ($statusCode === 401) {
                $suggestion = ' - Invalid credentials. Check your email and API token';
            } elseif ($statusCode === 403) {
                $suggestion = ' - Your API token does not have permission to access user information';
            }

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}{$suggestion}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get all available fields in the Jira instance
     * Returns both system and custom fields with their metadata
     *
     * @param array $credentials Jira credentials
     * @return array Array of fields with id, name, custom flag, and schema
     */
    public function getFields(array $credentials): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['url']);

        try {
            $response = $this->httpClient->request('GET', $url . '/rest/api/3/field', [
                'auth_basic' => [$credentials['username'], $credentials['api_token']],
            ]);

            $fields = $response->toArray();

            // Transform to AI-friendly format with essential info
            return array_map(function ($field) {
                $result = [
                    'id' => $field['id'] ?? '',
                    'name' => $field['name'] ?? '',
                    'custom' => $field['custom'] ?? false,
                ];

                // Include schema type for understanding field format
                if (!empty($field['schema']['type'])) {
                    $result['type'] = $field['schema']['type'];
                }
                if (!empty($field['schema']['items'])) {
                    $result['itemType'] = $field['schema']['items'];
                }
                if (!empty($field['schema']['custom'])) {
                    $result['customType'] = $field['schema']['custom'];
                }

                return $result;
            }, $fields);
        /** @phpstan-ignore-next-line catch.neverThrown */
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $errorData = $response->toArray(false);

            $errorMessages = $errorData['errorMessages'] ?? [];
            $message = $errorData['message'] ?? 'Unknown Jira error';
            $errorText = !empty($errorMessages) ? implode(', ', $errorMessages) : $message;

            throw new \RuntimeException(
                "Jira API Error (HTTP {$statusCode}): {$errorText}",
                $statusCode,
                $e
            );
        }
    }
}
