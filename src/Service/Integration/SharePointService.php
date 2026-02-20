<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Response;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

class SharePointService
{
    private const GRAPH_API_BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    public function testConnection(array $credentials): bool
    {
        try {
            error_log('Testing SharePoint connection with token: ' . substr($credentials['access_token'], 0, 20) . '...');
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me', [
                'auth_bearer' => $credentials['access_token'],
            ]);
            error_log('Test connection successful, status: ' . $response->getStatusCode());
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            error_log('Test connection failed: ' . $e->getMessage());
            return false;
        }
    }

    public function search(array $credentials, string $kqlQuery, int $limit = 25, ?string $userQuery = null): array
    {
        try {
            error_log('SharePoint search called with KQL: ' . $kqlQuery . ', limit: ' . $limit);
            $results = [];

            // Use the Microsoft Graph Search API with comprehensive entity types
            // Supporting Files, Sites, Pages, Lists, and Drives to match SharePoint native search
            $searchRequest = [
                'requests' => [
                    [
                        'entityTypes' => ['driveItem', 'site', 'listItem', 'list', 'drive'],
                        'query' => [
                            'queryString' => $kqlQuery  // KQL query passed directly from agent
                        ],
                        'size' => $limit,
                        'fields' => [
                            'title',
                            'name',
                            'webUrl',
                            'description',
                            'createdDateTime',
                            'lastModifiedDateTime',
                            'siteId',
                            'id',
                            'fileSystemInfo',
                            'size',
                            'parentReference'
                        ]
                    ]
                ]
            ];

            error_log('Attempting Microsoft Graph Search API with KQL query: ' . json_encode($searchRequest));

            $searchResponse = $this->httpClient->request('POST', self::GRAPH_API_BASE . '/search/query', [
                'auth_bearer' => $credentials['access_token'],
                'json' => $searchRequest
            ]);

            $searchData = $searchResponse->toArray();
            error_log('Search API full response: ' . json_encode($searchData));

            if (isset($searchData['value'][0]['hitsContainers'])) {
                $results = $this->processHitsContainers($searchData['value'][0]['hitsContainers']);
            } else {
                error_log('No hitsContainers found in search response');
                error_log('Response structure: ' . json_encode(array_keys($searchData)));
            }

            error_log('Search API found ' . count($results) . ' results');

            // Auto-retry without path: filter if 0 results and KQL contains path: filter
            $autoBroadened = false;
            if (count($results) === 0 && preg_match('/path:"[^"]*"/', $kqlQuery)) {
                $broadenedKql = trim(preg_replace('/\s*path:"[^"]*"\s*/', ' ', $kqlQuery));
                // Clean up leftover AND/OR at start/end
                $broadenedKql = trim(preg_replace('/^\s*(AND|OR)\s+/i', '', $broadenedKql));
                $broadenedKql = trim(preg_replace('/\s+(AND|OR)\s*$/i', '', $broadenedKql));

                if (!empty($broadenedKql)) {
                    error_log('Auto-retry: stripping path: filter, broadened KQL: ' . $broadenedKql);

                    $broadenedRequest = [
                        'requests' => [
                            [
                                'entityTypes' => ['driveItem', 'site', 'listItem', 'list', 'drive'],
                                'query' => [
                                    'queryString' => $broadenedKql
                                ],
                                'size' => $limit,
                                'fields' => [
                                    'title', 'name', 'webUrl', 'description',
                                    'createdDateTime', 'lastModifiedDateTime',
                                    'siteId', 'id', 'fileSystemInfo', 'size', 'parentReference'
                                ]
                            ]
                        ]
                    ];

                    $broadenedResponse = $this->httpClient->request('POST', self::GRAPH_API_BASE . '/search/query', [
                        'auth_bearer' => $credentials['access_token'],
                        'json' => $broadenedRequest
                    ]);

                    $broadenedData = $broadenedResponse->toArray();

                    if (isset($broadenedData['value'][0]['hitsContainers'])) {
                        $results = $this->processHitsContainers($broadenedData['value'][0]['hitsContainers']);
                        if (count($results) > 0) {
                            $autoBroadened = true;
                            $kqlQuery = $broadenedKql . ' (auto-broadened: path filter removed)';
                            error_log('Auto-retry found ' . count($results) . ' results after removing path: filter');
                        }
                    }
                }
            }

            // Apply server-side relevance scoring when userQuery is provided
            $relevanceScored = false;
            if ($userQuery !== null && $userQuery !== '' && count($results) > 0) {
                $results = $this->scoreResults($results, $userQuery);
                $relevanceScored = true;
                error_log('Results scored and sorted by relevance for userQuery: ' . $userQuery);
            }

            // Detect potential boilerplate terms to help the agent filter results
            $boilerplateTerms = [];
            if ($userQuery !== null && $userQuery !== '' && count($results) > 2) {
                $queryTerms = $this->extractQueryTerms($userQuery);
                $boilerplateTerms = $this->detectBoilerplateTerms($results, $queryTerms);
                if (!empty($boilerplateTerms)) {
                    error_log('Boilerplate terms detected: ' . implode(', ', $boilerplateTerms));
                }
            }

            // Group results by type for better user experience (matching SharePoint native search UI)
            $groupedResults = $this->groupResultsByType($results);

            return [
                'value' => $results,
                'grouped_results' => $groupedResults,
                'count' => count($results),
                'grouped_summary' => $this->createGroupedSummary($groupedResults),
                'searchQuery' => $kqlQuery,
                'relevanceScored' => $relevanceScored,
                'autoBroadened' => $autoBroadened,
                'boilerplateTerms' => $boilerplateTerms,
            ];
        } catch (\Exception $e) {
            error_log('SharePoint Pages Search Error: ' . $e->getMessage());
            error_log('Error trace: ' . $e->getTraceAsString());

            // Return detailed error message for better debugging
            return [
                'value' => [],
                'error' => true,
                'error_message' => $e->getMessage(),
                'error_details' => [
                    'query' => $kqlQuery,
                    'limit' => $limit,
                    'timestamp' => date('Y-m-d H:i:s')
                ],
                'troubleshooting' => [
                    'check_permissions' => 'Ensure Application permissions Sites.Read.All and Files.Read.All are granted',
                    'check_token' => 'Verify access token is valid and not expired',
                    'check_search_api' => 'Confirm Microsoft Graph Search API is enabled in tenant'
                ]
            ];
        }
    }

    /**
     * List accessible SharePoint sites
     *
     * @param array $credentials Authentication credentials
     * @param string|null $searchQuery Optional search query to filter sites
     * @return array List of accessible sites
     */
    public function listSites(array $credentials, ?string $searchQuery = null): array
    {
        try {
            $search = $searchQuery ?? '*';
            error_log('Listing SharePoint sites with search: ' . $search);

            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    'search' => $search,
                    '$top' => 100,
                    '$select' => 'id,displayName,name,webUrl,description'
                ]
            ]);

            $data = $response->toArray();
            $sites = [];

            if (isset($data['value'])) {
                foreach ($data['value'] as $site) {
                    $webUrl = $site['webUrl'] ?? '';
                    $hostname = '';
                    if (!empty($webUrl)) {
                        $parsed = parse_url($webUrl);
                        $hostname = $parsed['host'] ?? '';
                    }

                    $sites[] = [
                        'id' => $site['id'] ?? '',
                        'displayName' => $site['displayName'] ?? '',
                        'name' => $site['name'] ?? '',
                        'webUrl' => $webUrl,
                        'description' => $site['description'] ?? '',
                        'hostname' => $hostname,
                    ];
                }
            }

            error_log('Listed ' . count($sites) . ' SharePoint sites');

            return [
                'sites' => $sites,
                'count' => count($sites),
            ];
        } catch (\Exception $e) {
            error_log('SharePoint List Sites Error: ' . $e->getMessage());

            return [
                'sites' => [],
                'count' => 0,
                'error' => true,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    public function readPage(array $credentials, string $siteId, string $pageId): array
    {
        try {
            error_log('Reading SharePoint page - SiteID: ' . $siteId . ', PageID: ' . $pageId);

            // Check if siteId looks like a name rather than a proper ID
            // A proper site ID contains commas and GUIDs, or is in hostname:path format
            if (!str_contains($siteId, ',') && !str_contains($siteId, '.sharepoint.com')) {
                error_log('SiteID appears to be a name, attempting to resolve to actual site ID');

                // Search for the site by name
                $sitesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        'search' => $siteId,
                        '$top' => 10
                    ]
                ]);

                $sites = $sitesResponse->toArray();

                if (isset($sites['value']) && count($sites['value']) > 0) {
                    // Try to find exact match first
                    $resolvedSiteId = null;
                    foreach ($sites['value'] as $site) {
                        if (isset($site['displayName']) && strcasecmp($site['displayName'], $siteId) === 0) {
                            $resolvedSiteId = $site['id'];
                            error_log('Found exact site match: ' . $site['displayName'] . ' -> ' . $resolvedSiteId);
                            break;
                        }
                        if (isset($site['name']) && strcasecmp($site['name'], $siteId) === 0) {
                            $resolvedSiteId = $site['id'];
                            error_log('Found exact site match: ' . $site['name'] . ' -> ' . $resolvedSiteId);
                            break;
                        }
                    }

                    // If no exact match, use the first result
                    if (!$resolvedSiteId && isset($sites['value'][0]['id'])) {
                        $resolvedSiteId = $sites['value'][0]['id'];
                        $siteName = $sites['value'][0]['displayName'] ?? $sites['value'][0]['name'] ?? 'Unknown';
                        error_log('Using first site match: ' . $siteName . ' -> ' . $resolvedSiteId);
                    }

                    if ($resolvedSiteId) {
                        $siteId = $resolvedSiteId;
                    } else {
                        throw new \Exception('Could not resolve site name "' . $siteId . '" to a valid site ID');
                    }
                } else {
                    throw new \Exception('No sites found matching "' . $siteId . '"');
                }
            }

            // Check if pageId looks like a name/title rather than a GUID
            // A proper page ID is a GUID format like "7b6fd3e8-80ad-4392-9643-6bab61be81e0"
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $pageId)) {
                error_log('PageID appears to be a name/title, attempting to resolve to actual page ID');

                // Get all pages from the site and find the one matching the name/title
                $pagesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/pages", [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        '$top' => 100  // Get more pages to find the right one
                    ]
                ]);

                $pages = $pagesResponse->toArray();

                if (isset($pages['value'])) {
                    $resolvedPageId = null;
                    $pageNameLower = strtolower($pageId);

                    // Try to find exact match first by title or name
                    foreach ($pages['value'] as $page) {
                        // Check title match
                        if (isset($page['title']) && strtolower($page['title']) === $pageNameLower) {
                            $resolvedPageId = $page['id'];
                            error_log('Found exact page match by title: ' . $page['title'] . ' -> ' . $resolvedPageId);
                            break;
                        }
                        // Check name match
                        if (isset($page['name']) && strtolower($page['name']) === $pageNameLower) {
                            $resolvedPageId = $page['id'];
                            error_log('Found exact page match by name: ' . $page['name'] . ' -> ' . $resolvedPageId);
                            break;
                        }
                        // Check for URL-friendly name match (with hyphens instead of spaces)
                        $urlFriendlyPageId = str_replace('-', ' ', $pageId);
                        if (isset($page['title']) && strtolower($page['title']) === strtolower($urlFriendlyPageId)) {
                            $resolvedPageId = $page['id'];
                            error_log('Found page match by URL-friendly title: ' . $page['title'] . ' -> ' . $resolvedPageId);
                            break;
                        }
                    }

                    // If no exact match, try partial match
                    if (!$resolvedPageId) {
                        foreach ($pages['value'] as $page) {
                            if (
                                (isset($page['title']) && str_contains(strtolower($page['title']), $pageNameLower)) ||
                                (isset($page['name']) && str_contains(strtolower($page['name']), $pageNameLower))
                            ) {
                                $resolvedPageId = $page['id'];
                                $pageTitle = $page['title'] ?? $page['name'] ?? 'Unknown';
                                error_log('Using partial page match: ' . $pageTitle . ' -> ' . $resolvedPageId);
                                break;
                            }
                        }
                    }

                    if ($resolvedPageId) {
                        $pageId = $resolvedPageId;
                    } else {
                        throw new \Exception('Could not resolve page name "' . $pageId . '" to a valid page ID. Available pages: ' .
                            implode(', ', array_map(function ($p) {
                                return $p['title'] ?? $p['name'] ?? 'Untitled';
                            }, $pages['value'])));
                    }
                } else {
                    throw new \Exception('No pages found in site');
                }
            }

            // Expand canvasLayout to get page content
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/pages/{$pageId}/microsoft.graph.sitePage", [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$expand' => 'canvasLayout'
                ]
            ]);

            $data = $response->toArray();

            // Extract content from canvasLayout if available
            $content = $this->extractPageContent($data);
            if ($content !== null) {
                $data['content'] = $content;
                $data['contentLength'] = strlen($content);
                error_log('SharePoint page read successfully with content (length: ' . strlen($content) . ')');
            } else {
                error_log('SharePoint page read successfully (no extractable content)');
            }

            return $data;
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Read Page Client Error (' . $statusCode . '): ' . $errorContent);
            error_log('Request URL: ' . self::GRAPH_API_BASE . "/sites/{$siteId}/pages/{$pageId}");

            // Parse error details if available
            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to read SharePoint page (HTTP ' . $statusCode . '): ' . $errorContent);
        } catch (\Exception $e) {
            error_log('SharePoint Read Page Error: ' . $e->getMessage());
            throw new \Exception('Failed to read SharePoint page: ' . $e->getMessage());
        }
    }

    public function listFiles(array $credentials, string $siteId, string $path = ''): array
    {
        try {
            $endpoint = $path
                ? "/sites/{$siteId}/drive/root:/{$path}:/children"
                : "/sites/{$siteId}/drive/root/children";

            error_log('Listing SharePoint files - SiteID: ' . $siteId . ', Path: ' . ($path ?: 'root'));

            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . $endpoint, [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$select' => 'id,name,size,lastModifiedDateTime,file,folder,webUrl',
                    '$top' => 100
                ]
            ]);

            $data = $response->toArray();
            error_log('SharePoint files listed successfully, count: ' . (isset($data['value']) ? count($data['value']) : 0));
            return $data;
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint List Files Client Error (' . $statusCode . '): ' . $errorContent);

            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to list SharePoint files (HTTP ' . $statusCode . ')');
        } catch (\Exception $e) {
            error_log('SharePoint List Files Error: ' . $e->getMessage());
            throw new \Exception('Failed to list files: ' . $e->getMessage());
        }
    }

    public function downloadFile(array $credentials, string $siteId, string $itemId): array
    {
        try {
            error_log('Downloading SharePoint file - SiteID: ' . $siteId . ', ItemID: ' . $itemId);

            // Get file metadata first
            $metadataResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}", [
                'auth_bearer' => $credentials['access_token'],
            ]);

            $metadata = $metadataResponse->toArray();

            // Get download URL
            $downloadResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}/content", [
                'auth_bearer' => $credentials['access_token'],
                'max_redirects' => 0,
            ]);

            // The download URL is in the Location header
            $downloadUrl = $downloadResponse->getHeaders(false)['location'][0] ?? null;

            error_log('SharePoint file download prepared successfully: ' . ($metadata['name'] ?? 'unknown'));

            return [
                'metadata' => $metadata,
                'downloadUrl' => $downloadUrl,
                'name' => $metadata['name'] ?? 'file',
                'size' => $metadata['size'] ?? 0,
                'mimeType' => $metadata['file']['mimeType'] ?? 'application/octet-stream'
            ];
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Download File Client Error (' . $statusCode . '): ' . $errorContent);

            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to download SharePoint file (HTTP ' . $statusCode . ')');
        } catch (\Exception $e) {
            error_log('SharePoint Download File Error: ' . $e->getMessage());
            throw new \Exception('Failed to download file: ' . $e->getMessage());
        }
    }

    public function getListItems(array $credentials, string $siteId, string $listId, array $filters = []): array
    {
        try {
            error_log('Getting SharePoint list items - SiteID: ' . $siteId . ', ListID: ' . $listId);

            $query = [
                '$expand' => 'fields',
                '$top' => 100
            ];

            if (!empty($filters['filter'])) {
                $query['$filter'] = $filters['filter'];
                error_log('Applying filter: ' . $filters['filter']);
            }

            if (!empty($filters['orderby'])) {
                $query['$orderby'] = $filters['orderby'];
            }

            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/lists/{$listId}/items", [
                'auth_bearer' => $credentials['access_token'],
                'query' => $query
            ]);

            $data = $response->toArray();
            error_log('SharePoint list items retrieved successfully, count: ' . (isset($data['value']) ? count($data['value']) : 0));
            return $data;
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Get List Items Client Error (' . $statusCode . '): ' . $errorContent);

            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to get SharePoint list items (HTTP ' . $statusCode . ')');
        } catch (\Exception $e) {
            error_log('SharePoint Get List Items Error: ' . $e->getMessage());
            throw new \Exception('Failed to get list items: ' . $e->getMessage());
        }
    }

    public function refreshToken(string $refreshToken, string $clientId, string $clientSecret, string $tenantId): array
    {
        // Use 'common' endpoint for multi-tenant apps, or specific tenant if stored
        // 'common' allows tokens from any tenant to be refreshed
        $endpoint = ($tenantId === 'common' || empty($tenantId))
            ? 'common'
            : $tenantId;

        $response = $this->httpClient->request('POST', "https://login.microsoftonline.com/{$endpoint}/oauth2/v2.0/token", [
            'body' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'https://graph.microsoft.com/.default offline_access'
            ]
        ]);

        $data = $response->toArray();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_in' => $data['expires_in'],
            'expires_at' => time() + $data['expires_in']
        ];
    }

    public function getSiteId(array $credentials, string $siteUrl): ?string
    {
        try {
            // Parse the site URL to get the hostname and path
            $parsedUrl = parse_url($siteUrl);
            $hostname = $parsedUrl['host'] ?? '';
            $path = trim($parsedUrl['path'] ?? '', '/');

            // Construct the API endpoint
            if ($path) {
                $endpoint = "/sites/{$hostname}:/sites/{$path}";
            } else {
                $endpoint = "/sites/{$hostname}";
            }

            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . $endpoint, [
                'auth_bearer' => $credentials['access_token'],
            ]);

            $data = $response->toArray();
            return $data['id'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get site information by site ID
     *
     * @param array $credentials The authentication credentials
     * @param string $siteId The site ID
     * @return array Site information
     */
    private function getSiteInfo(array $credentials, string $siteId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites/' . $siteId, [
                'auth_bearer' => $credentials['access_token'],
            ]);

            return $response->toArray();
        } catch (\Exception $e) {
            error_log('Failed to get site info for ' . $siteId . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Read document content from SharePoint
     * Extracts text from Office documents (Word, Excel, PowerPoint) and PDFs
     *
     * @param array $credentials Authentication credentials
     * @param string $siteId Site ID
     * @param string $itemId Document item ID
     * @param int $maxLength Maximum content length to return (default 5000)
     * @return array Document content and metadata
     */
    public function readDocument(array $credentials, string $siteId, string $itemId, int $maxLength = 5000, ?string $driveId = null): array
    {
        try {
            error_log('Reading SharePoint document content - SiteID: ' . $siteId . ', ItemID: ' . $itemId . ($driveId ? ', DriveID: ' . $driveId : ''));

            // Prioritize driveId-based access if provided (more reliable from search results)
            if (!empty($driveId)) {
                error_log('Using driveId-based access for better reliability');
                $driveEndpoint = "/drives/{$driveId}";
                $apiEndpoint = self::GRAPH_API_BASE . "{$driveEndpoint}/items/{$itemId}";
            } else {
                // Fallback to site-based access
                // Check if this is a personal OneDrive file (siteId is "me" or empty)
                $isPersonalDrive = empty($siteId) || $siteId === 'me' || $siteId === 'personal';

                if ($isPersonalDrive) {
                    error_log('Accessing personal OneDrive file');
                    $driveEndpoint = '/me/drive';
                } else {
                    // Check if siteId needs to be resolved (if it's a site name rather than ID)
                    // A proper site ID contains commas or periods (e.g., "contoso.sharepoint.com,guid,guid")
                    if (!str_contains($siteId, ',') && !str_contains($siteId, '.')) {
                        error_log('SiteID appears to be a site name, attempting to resolve to actual site ID');

                        // Try to resolve the site name to a proper site ID
                        try {
                            $sitesResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/sites', [
                                'auth_bearer' => $credentials['access_token'],
                                'query' => [
                                    'search' => $siteId,
                                    '$top' => 5
                                ]
                            ]);

                            $sites = $sitesResponse->toArray();

                            if (isset($sites['value']) && count($sites['value']) > 0) {
                                // Try to find exact match first
                                foreach ($sites['value'] as $site) {
                                    if (
                                        (isset($site['displayName']) && strcasecmp($site['displayName'], $siteId) === 0) ||
                                        (isset($site['name']) && strcasecmp($site['name'], $siteId) === 0)
                                    ) {
                                        $siteId = $site['id'];
                                        error_log('Resolved site name to ID: ' . $siteId);
                                        break;
                                    }
                                }

                                // If no exact match, use the first result as fallback
                                if (!str_contains($siteId, ',')) {
                                    $siteId = $sites['value'][0]['id'];
                                    error_log('Using first matching site ID: ' . $siteId);
                                }
                            } else {
                                error_log('Warning: Could not resolve site name "' . $siteId . '" to a site ID, proceeding anyway');
                            }
                        } catch (\Exception $e) {
                            error_log('Failed to resolve site name: ' . $e->getMessage());
                            // Continue with the original siteId
                        }
                    }

                    $driveEndpoint = "/sites/{$siteId}/drive";
                }

                $apiEndpoint = self::GRAPH_API_BASE . "{$driveEndpoint}/items/{$itemId}";
            }

            // First get file metadata to understand what we're dealing with
            $metadataResponse = $this->httpClient->request('GET', $apiEndpoint, [
                'auth_bearer' => $credentials['access_token'],
            ]);

            $metadata = $metadataResponse->toArray();
            $fileName = $metadata['name'] ?? 'unknown';
            $mimeType = $metadata['file']['mimeType'] ?? 'unknown';
            $size = $metadata['size'] ?? 0;

            error_log('Document details: ' . $fileName . ' (' . $mimeType . ', ' . $size . ' bytes)');

            // For very large files, provide metadata only with size warning
            if ($size > 10485760) { // 10MB
                return [
                    'warning' => 'Document is very large (' . round($size / 1048576, 1) . ' MB). Content extraction limited to first part of document.',
                    'metadata' => $metadata,
                    'content' => $this->extractFirstPartOfLargeDocument($credentials, $driveEndpoint, $itemId, $maxLength),
                    'contentLength' => $maxLength,
                    'truncated' => true,
                    'fullSize' => $size
                ];
            }

            // Determine if we can extract text based on mime type
            $supportedTypes = [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
                'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
                'application/pdf', // .pdf
                'text/plain', // .txt
                'text/csv', // .csv
                'application/msword', // .doc (legacy)
                'application/vnd.ms-excel', // .xls (legacy)
                'application/vnd.ms-powerpoint', // .ppt (legacy)
            ];

            $canExtractText = false;
            foreach ($supportedTypes as $type) {
                if (str_contains($mimeType, $type) || str_contains($mimeType, 'text/')) {
                    $canExtractText = true;
                    break;
                }
            }

            if (!$canExtractText) {
                return [
                    'error' => 'File type does not support text extraction',
                    'metadata' => $metadata,
                    'mimeType' => $mimeType,
                    'content' => null,
                    'contentLength' => 0,
                    'truncated' => false
                ];
            }

            // For text files, we can get content directly
            if (str_contains($mimeType, 'text/')) {
                $contentResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "{$driveEndpoint}/items/{$itemId}/content", [
                    'auth_bearer' => $credentials['access_token'],
                ]);

                $content = $contentResponse->getContent();

                // Truncate if needed
                $truncated = false;
                if (strlen($content) > $maxLength) {
                    $content = substr($content, 0, $maxLength);
                    $truncated = true;
                }

                return [
                    'metadata' => $metadata,
                    'content' => $content,
                    'contentLength' => strlen($content),
                    'truncated' => $truncated,
                    'mimeType' => $mimeType
                ];
            }

            // For Office documents and PDFs, we need to download and process locally
            // since Microsoft Graph doesn't support direct text extraction for all formats
            try {
                // Download the file content
                $contentResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "{$driveEndpoint}/items/{$itemId}/content", [
                    'auth_bearer' => $credentials['access_token'],
                ]);

                $binaryContent = $contentResponse->getContent();
                $content = null;

                // Process based on mime type
                if (str_contains($mimeType, 'application/pdf')) {
                    // Extract text from PDF
                    $content = $this->extractTextFromPdf($binaryContent, $maxLength);
                } elseif (str_contains($mimeType, 'spreadsheetml.sheet') || str_contains($mimeType, 'application/vnd.ms-excel')) {
                    // Extract text from XLSX/XLS
                    $content = $this->extractTextFromSpreadsheet($binaryContent, $maxLength);
                } elseif (str_contains($mimeType, 'wordprocessingml.document') || str_contains($mimeType, 'application/msword')) {
                    // Extract text from DOCX/DOC
                    $content = $this->extractTextFromWord($binaryContent, $maxLength);
                } else {
                    // Try the old format=text approach for other Office formats
                    try {
                        $contentResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . "/sites/{$siteId}/drive/items/{$itemId}/content", [
                            'auth_bearer' => $credentials['access_token'],
                            'query' => [
                                'format' => 'text'
                            ],
                            'headers' => [
                                'Accept' => 'text/plain'
                            ]
                        ]);
                        $content = $contentResponse->getContent();

                        // Clean up if HTML
                        if (str_contains($content, '<html') || str_contains($content, '<body')) {
                            $content = strip_tags($content);
                            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        }
                    } catch (\Exception $e) {
                        error_log('Format conversion not supported for mime type: ' . $mimeType);
                        $content = null;
                    }
                }

                if ($content === null) {
                    return [
                        'error' => 'Could not extract text from document. Document type may not be supported for text extraction.',
                        'metadata' => $metadata,
                        'mimeType' => $mimeType,
                        'content' => null,
                        'contentLength' => 0,
                        'truncated' => false
                    ];
                }

                // Clean up the content
                $content = preg_replace('/\s+/', ' ', trim($content));

                // Truncate if needed
                $truncated = false;
                if (strlen($content) > $maxLength) {
                    $content = substr($content, 0, $maxLength);
                    $truncated = true;
                }

                error_log('Document content extracted successfully (length: ' . strlen($content) . ')');

                return [
                    'metadata' => $metadata,
                    'content' => $content,
                    'contentLength' => strlen($content),
                    'truncated' => $truncated,
                    'mimeType' => $mimeType
                ];
            } catch (\Exception $e) {
                error_log('Document extraction failed: ' . $e->getMessage());

                return [
                    'error' => 'Could not extract text from document. Document may be encrypted, corrupted, or in an unsupported format.',
                    'metadata' => $metadata,
                    'mimeType' => $mimeType,
                    'content' => null,
                    'contentLength' => 0,
                    'truncated' => false,
                    'exception' => $e->getMessage()
                ];
            }
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorContent = $e->getResponse()->getContent(false);
            error_log('SharePoint Read Document Client Error (' . $statusCode . '): ' . $errorContent);

            $errorData = json_decode($errorContent, true);
            if (isset($errorData['error']['message'])) {
                throw new \Exception('SharePoint API Error: ' . $errorData['error']['message']);
            }
            throw new \Exception('Failed to read SharePoint document (HTTP ' . $statusCode . ')');
        } catch (\Exception $e) {
            error_log('SharePoint Read Document Error: ' . $e->getMessage());
            throw new \Exception('Failed to read document: ' . $e->getMessage());
        }
    }

    /**
     * Extract readable content from SharePoint page canvasLayout
     *
     * @param array $pageData The page data with expanded canvasLayout
     * @param int $maxLength Maximum length of content to return (default 1000)
     * @return string|null The extracted content or null if no content found
     */
    private function extractPageContent(array $pageData, int $maxLength = 1000): ?string
    {
        $content = '';

        // Check if canvasLayout exists
        if (!isset($pageData['canvasLayout'])) {
            return null;
        }

        $canvasLayout = $pageData['canvasLayout'];

        // Process horizontal sections
        if (isset($canvasLayout['horizontalSections']) && is_array($canvasLayout['horizontalSections'])) {
            foreach ($canvasLayout['horizontalSections'] as $section) {
                if (isset($section['columns']) && is_array($section['columns'])) {
                    foreach ($section['columns'] as $column) {
                        if (isset($column['webparts']) && is_array($column['webparts'])) {
                            foreach ($column['webparts'] as $webpart) {
                                // Extract content from text web parts
                                if (isset($webpart['innerHtml'])) {
                                    // Strip HTML tags to get plain text
                                    $text = strip_tags($webpart['innerHtml']);
                                    // Normalize whitespace
                                    $text = preg_replace('/\s+/', ' ', trim($text));
                                    if (!empty($text)) {
                                        $content .= $text . ' ';
                                    }
                                }

                                // Also check for other content types in data field
                                if (isset($webpart['data']['properties']['title'])) {
                                    $content .= $webpart['data']['properties']['title'] . ' ';
                                }
                                if (isset($webpart['data']['description'])) {
                                    $content .= $webpart['data']['description'] . ' ';
                                }
                            }
                        }
                    }
                }
            }
        }

        // Process vertical section if exists
        if (isset($canvasLayout['verticalSection']['webparts']) && is_array($canvasLayout['verticalSection']['webparts'])) {
            foreach ($canvasLayout['verticalSection']['webparts'] as $webpart) {
                if (isset($webpart['innerHtml'])) {
                    $text = strip_tags($webpart['innerHtml']);
                    $text = preg_replace('/\s+/', ' ', trim($text));
                    if (!empty($text)) {
                        $content .= $text . ' ';
                    }
                }
            }
        }

        // Trim and limit content length
        $content = trim($content);

        if (empty($content)) {
            return null;
        }

        // Truncate to maxLength if necessary
        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength) . '...';
        }

        return $content;
    }

    /**
     * Extract first part of content from large documents
     * Uses byte range requests to avoid downloading entire file
     *
     * @param array $credentials Authentication credentials
     * @param string $driveEndpoint Drive endpoint URL
     * @param string $itemId Document item ID
     * @param int $maxLength Maximum characters to extract
     * @return string Extracted content
     */
    private function extractFirstPartOfLargeDocument(array $credentials, string $driveEndpoint, string $itemId, int $maxLength): string
    {
        try {
            // For large files, we can try to get just the first part using Range headers
            // This is a best-effort approach - not all file types support partial content extraction
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . "{$driveEndpoint}/items/{$itemId}/content", [
                'auth_bearer' => $credentials['access_token'],
                'headers' => [
                    'Range' => 'bytes=0-524288', // Get first 512KB only
                    'Accept' => 'text/plain'
                ],
                'timeout' => 30 // Limit timeout for large files
            ]);

            $partialContent = $response->getContent();

            // Try to extract text from the partial content
            if (str_contains($partialContent, '<html') || str_contains($partialContent, '<body')) {
                $partialContent = strip_tags($partialContent);
                $partialContent = html_entity_decode($partialContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            // Clean and truncate
            $partialContent = preg_replace('/\s+/', ' ', trim($partialContent));
            if (strlen($partialContent) > $maxLength) {
                $partialContent = substr($partialContent, 0, $maxLength);
            }

            return $partialContent ?: 'Unable to extract content from large document';
        } catch (\Exception $e) {
            error_log('Failed to extract content from large document: ' . $e->getMessage());
            return 'Unable to extract content from large document (size limit exceeded)';
        }
    }

    /**
     * Extract text from PDF binary content
     *
     * @param string $binaryContent Binary PDF content
     * @param int $maxLength Maximum length of text to extract
     * @return string|null Extracted text or null on failure
     */
    private function extractTextFromPdf(string $binaryContent, int $maxLength): ?string
    {
        try {
            error_log('Extracting text from PDF (size: ' . strlen($binaryContent) . ' bytes)');

            $parser = new PdfParser();
            $pdf = $parser->parseContent($binaryContent);

            // Extract text from all pages
            $text = $pdf->getText();

            if (empty($text)) {
                error_log('No text extracted from PDF - might be a scanned document');
                return null;
            }

            // Clean up the text
            $text = preg_replace('/\s+/', ' ', trim($text));

            error_log('Successfully extracted ' . strlen($text) . ' characters from PDF');

            return $text;
        } catch (\Exception $e) {
            error_log('PDF extraction failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract text from Excel/spreadsheet binary content
     *
     * @param string $binaryContent Binary spreadsheet content
     * @param int $maxLength Maximum length of text to extract
     * @return string|null Extracted text or null on failure
     */
    private function extractTextFromSpreadsheet(string $binaryContent, int $maxLength): ?string
    {
        try {
            error_log('Extracting text from spreadsheet (size: ' . strlen($binaryContent) . ' bytes)');

            // Save to temporary file (PHPSpreadsheet requires file path)
            $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
            file_put_contents($tempFile, $binaryContent);

            try {
                // Load the spreadsheet
                $reader = SpreadsheetIOFactory::createReaderForFile($tempFile);
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                $spreadsheet = $reader->load($tempFile);

                $text = '';
                $charCount = 0;

                // Extract text from all worksheets
                foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                    if ($charCount >= $maxLength) {
                        break;
                    }

                    $worksheetTitle = $worksheet->getTitle();
                    $text .= "Sheet: $worksheetTitle\n";

                    $highestRow = $worksheet->getHighestRow();
                    $highestColumn = $worksheet->getHighestColumn();

                    for ($row = 1; $row <= $highestRow; $row++) {
                        if ($charCount >= $maxLength) {
                            break;
                        }

                        $rowData = $worksheet->rangeToArray(
                            "A$row:$highestColumn$row",
                            null,
                            true,
                            false
                        )[0];

                        // Filter out empty cells and join with tabs
                        $rowText = implode("\t", array_filter($rowData, function ($cell) {
                            return $cell !== null && $cell !== '';
                        }));

                        if (!empty($rowText)) {
                            $text .= $rowText . "\n";
                            $charCount = strlen($text);
                        }
                    }

                    $text .= "\n";
                }

                error_log('Successfully extracted ' . strlen($text) . ' characters from spreadsheet');

                return $text;
            } finally {
                // Clean up temp file
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        } catch (\Exception $e) {
            error_log('Spreadsheet extraction failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract text from Word document binary content
     *
     * @param string $binaryContent Binary Word document content
     * @param int $maxLength Maximum length of text to extract
     * @return string|null Extracted text or null on failure
     */
    private function extractTextFromWord(string $binaryContent, int $maxLength): ?string
    {
        try {
            error_log('Extracting text from Word document (size: ' . strlen($binaryContent) . ' bytes)');

            // Save to temporary file (PHPWord requires file path)
            $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
            file_put_contents($tempFile, $binaryContent);

            try {
                // Load the Word document
                $phpWord = WordIOFactory::load($tempFile, 'Word2007');

                $text = '';

                // Extract text from all sections
                foreach ($phpWord->getSections() as $section) {
                    // Extract text from elements
                    foreach ($section->getElements() as $element) {
                        $text .= $this->extractTextFromWordElement($element) . "\n";

                        if (strlen($text) >= $maxLength) {
                            break 2; // Break both loops
                        }
                    }
                }

                if (empty($text)) {
                    error_log('No text extracted from Word document');
                    return null;
                }

                error_log('Successfully extracted ' . strlen($text) . ' characters from Word document');

                return $text;
            } finally {
                // Clean up temp file
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        } catch (\Exception $e) {
            error_log('Word document extraction failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Helper method to extract text from Word document elements
     *
     * @param mixed $element The Word document element
     * @return string Extracted text
     */
    private function extractTextFromWordElement($element): string
    {
        $text = '';

        // Handle different element types
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
        } elseif (method_exists($element, 'getElements')) {
            // For containers, recursively extract from child elements
            foreach ($element->getElements() as $childElement) {
                $text .= $this->extractTextFromWordElement($childElement) . ' ';
            }
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
            $text = $element->getText();
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            foreach ($element->getElements() as $textElement) {
                if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                    $text .= $textElement->getText() . ' ';
                }
            }
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            // Extract text from table cells
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $text .= $this->extractTextFromWordElement($cellElement) . ' ';
                    }
                }
                $text .= "\n";
            }
        }

        return trim($text);
    }

    /**
     * Score and sort search results by relevance to the user's original query
     *
     * @param array $results Search results to score
     * @param string $userQuery The user's original search query
     * @return array Scored and sorted results (highest relevance first)
     */
    private function scoreResults(array $results, string $userQuery): array
    {
        $terms = $this->extractQueryTerms($userQuery);

        if (empty($terms)) {
            return $results;
        }

        $termCount = count($terms);

        foreach ($results as &$result) {
            $score = 0;

            $title = strtolower($result['title'] ?? '');
            $name = strtolower($result['name'] ?? '');
            $summary = strtolower($result['summary'] ?? '');
            $description = strtolower($result['description'] ?? '');

            // Title match: up to 40 points (weighted by term count)
            $titleMatches = 0;
            foreach ($terms as $term) {
                if (str_contains($title, $term)) {
                    $titleMatches++;
                }
            }
            if ($termCount > 0) {
                $score += (int) round(40 * ($titleMatches / $termCount));
            }

            // Filename match: up to 30 points
            $nameMatches = 0;
            foreach ($terms as $term) {
                if (str_contains($name, $term)) {
                    $nameMatches++;
                }
            }
            if ($termCount > 0) {
                $score += (int) round(30 * ($nameMatches / $termCount));
            }

            // Snippet/description relevance: up to 20 points
            $snippetText = $summary . ' ' . $description;
            $snippetMatches = 0;
            foreach ($terms as $term) {
                if (str_contains($snippetText, $term)) {
                    $snippetMatches++;
                }
            }
            if ($termCount > 0) {
                $score += (int) round(20 * ($snippetMatches / $termCount));
            }

            // Recency bonus: up to 10 points (decays over 365 days)
            $lastModified = $result['lastModifiedDateTime'] ?? '';
            if (!empty($lastModified)) {
                try {
                    $modifiedTime = new \DateTimeImmutable($lastModified);
                    $now = new \DateTimeImmutable();
                    $daysDiff = (int) $now->diff($modifiedTime)->days;
                    $recencyScore = max(0, 10 - (int) round(10 * ($daysDiff / 365)));
                    $score += $recencyScore;
                } catch (\Exception $e) {
                    // Ignore date parsing errors
                }
            }

            // Type bonus: 5 points for files and pages (most useful result types)
            $type = $result['type'] ?? '';
            if ($type === 'file' || $type === 'page') {
                $score += 5;
            }

            // Policy/guideline indicator boost: up to 15 points
            // Documents whose title or filename contains policy-indicator words
            // are more likely to be the authoritative source users are looking for
            $policyIndicators = [
                'richtlinie', 'leitlinie', 'policy', 'guideline', 'regelung',
                'handbuch', 'manual', 'guide', 'procedure', 'prozess',
                'checkliste', 'checklist', 'faq', 'overview', 'übersicht',
            ];
            $titleAndName = $title . ' ' . $name;
            $policyBoost = 0;
            foreach ($policyIndicators as $indicator) {
                if (str_contains($titleAndName, $indicator)) {
                    $policyBoost = 15;
                    break;
                }
            }
            $score += $policyBoost;

            $result['relevanceScore'] = $score;
        }
        unset($result);

        // Sort by relevance score descending
        usort($results, function (array $a, array $b): int {
            return ($b['relevanceScore'] ?? 0) <=> ($a['relevanceScore'] ?? 0);
        });

        return $results;
    }

    /**
     * Extract meaningful query terms from a user query, stripping common stop words
     *
     * @param string $query The user's search query
     * @return array Lowercase query terms
     */
    private function extractQueryTerms(string $query): array
    {
        $stopWords = [
            // English
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'is', 'it', 'this', 'that', 'are', 'was',
            'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'can', 'not', 'no', 'all', 'any', 'my', 'your', 'our',
            'find', 'search', 'show', 'get', 'me', 'about', 'what', 'where', 'how',
            'please', 'i', 'we', 'you', 'they', 'he', 'she',
            // German
            'der', 'die', 'das', 'ein', 'eine', 'und', 'oder', 'aber', 'in', 'auf',
            'an', 'zu', 'für', 'von', 'mit', 'aus', 'ist', 'es', 'ich', 'wir',
            'sie', 'er', 'den', 'dem', 'des', 'im', 'am', 'um', 'nach', 'über',
            'alle', 'mein', 'dein', 'unser', 'bitte', 'finde', 'suche', 'zeige',
            'mir', 'mich', 'dir', 'was', 'wo', 'wie', 'welche', 'welcher',
            // SharePoint context
            'sharepoint', 'document', 'file', 'page', 'site', 'dokument', 'datei', 'seite',
        ];

        // Split on whitespace and common punctuation
        $words = preg_split('/[\s,;:!?.()\[\]{}"\'+]+/', strtolower(trim($query)));
        $words = array_filter($words, function (string $word) use ($stopWords): bool {
            return strlen($word) >= 2 && !in_array($word, $stopWords, true);
        });

        return array_values($words);
    }

    /**
     * Detect search terms that likely appear as boilerplate (company address, footers, signatures)
     * rather than as topical content.
     *
     * A term is considered boilerplate if it appears in the summary/description of 60%+ of results
     * AND co-occurs with address-pattern indicators (street names, postal codes, company suffixes).
     *
     * @param array<int, array<string, mixed>> $results Search results to analyze
     * @param array<int, string> $queryTerms The search terms to check
     * @return array<int, string> Terms detected as likely boilerplate
     */
    public function detectBoilerplateTerms(array $results, array $queryTerms): array
    {
        if (count($results) < 3) {
            return [];
        }

        $addressIndicators = [
            'straße', 'str.', 'weg', 'platz', 'allee', 'gasse',
            'gmbh', 'ag', 'kg', 'ohg', 'mbh', 'inc', 'ltd', 'corp',
        ];

        $postalCodePattern = '/\b\d{5}\b/';

        $boilerplateTerms = [];
        $resultCount = count($results);
        $threshold = 0.6;

        foreach ($queryTerms as $term) {
            $termLower = strtolower($term);
            $addressContextCount = 0;

            foreach ($results as $result) {
                $snippet = strtolower(($result['summary'] ?? '') . ' ' . ($result['description'] ?? ''));

                if (!str_contains($snippet, $termLower)) {
                    continue;
                }

                $hasAddressIndicator = false;
                foreach ($addressIndicators as $indicator) {
                    if (str_contains($snippet, $indicator)) {
                        $hasAddressIndicator = true;
                        break;
                    }
                }

                if (!$hasAddressIndicator) {
                    $hasAddressIndicator = (bool) preg_match($postalCodePattern, $snippet);
                }

                if ($hasAddressIndicator) {
                    $addressContextCount++;
                }
            }

            if (($addressContextCount / $resultCount) >= $threshold) {
                $boilerplateTerms[] = $termLower;
            }
        }

        return $boilerplateTerms;
    }

    /**
     * Process hitsContainers from Microsoft Graph Search API response
     *
     * @param array $hitsContainers The hitsContainers array from the search response
     * @return array Processed search results
     */
    private function processHitsContainers(array $hitsContainers): array
    {
        $results = [];

        foreach ($hitsContainers as $container) {
            error_log('Processing container with entityType: ' . ($container['@odata.type'] ?? 'unknown'));
            error_log('Container has ' . (isset($container['hits']) ? count($container['hits']) : 0) . ' hits');

            if (!isset($container['hits'])) {
                continue;
            }

            foreach ($container['hits'] as $hit) {
                if (!isset($hit['resource'])) {
                    continue;
                }

                $resource = $hit['resource'];
                error_log('Hit resource type: ' . ($resource['@odata.type'] ?? 'unknown'));
                error_log('Resource fields: ' . json_encode(array_keys($resource)));

                // Determine the type based on @odata.type for proper grouping
                $odataType = $resource['@odata.type'] ?? 'unknown';
                $resultType = 'page'; // default

                if (str_contains($odataType, 'driveItem')) {
                    $resultType = 'file';
                } elseif (str_contains($odataType, 'site')) {
                    $resultType = 'site';
                } elseif (str_contains($odataType, 'listItem')) {
                    $resultType = 'page';
                } elseif (str_contains($odataType, 'list')) {
                    $resultType = 'list';
                } elseif (str_contains($odataType, 'drive')) {
                    $resultType = 'drive';
                }

                // Extract siteId properly based on the resource type
                $extractedSiteId = $resource['siteId'] ?? '';

                if (empty($extractedSiteId) && isset($resource['parentReference']['siteId'])) {
                    $extractedSiteId = $resource['parentReference']['siteId'];
                }

                if (empty($extractedSiteId) && !empty($resource['webUrl'])) {
                    if (preg_match('/https:\/\/[^\/]+\.sharepoint\.com\/sites\/([^\/]+)/', $resource['webUrl'], $matches)) {
                        $extractedSiteId = $matches[1];
                        error_log('Warning: Using site name "' . $extractedSiteId . '" extracted from URL as siteId placeholder');
                    }
                }

                $driveId = $resource['parentReference']['driveId'] ?? '';

                $results[] = [
                    'type' => $resultType,
                    'id' => $resource['id'] ?? '',
                    'driveId' => $driveId,
                    'title' => $resource['title'] ?? $resource['name'] ?? $hit['summary'] ?? '',
                    'name' => $resource['name'] ?? '',
                    'webUrl' => $resource['webUrl'] ?? '',
                    'description' => $resource['description'] ?? $hit['summary'] ?? '',
                    'createdDateTime' => $resource['createdDateTime'] ?? '',
                    'lastModifiedDateTime' => $resource['lastModifiedDateTime'] ?? '',
                    'siteId' => $extractedSiteId,
                    'siteName' => $resource['displayName'] ?? '',
                    'siteUrl' => $resource['webUrl'] ?? '',
                    'summary' => $hit['summary'] ?? '',
                    'resourceType' => $odataType,
                    'parentReference' => $resource['parentReference'] ?? null
                ];
            }
        }

        return $results;
    }

    /**
     * Group search results by type (Files, Sites, Pages, Lists, Drives)
     *
     * @param array $results Flat array of search results
     * @return array Grouped results by type
     */
    private function groupResultsByType(array $results): array
    {
        $grouped = [
            'files' => [],
            'sites' => [],
            'pages' => [],
            'lists' => [],
            'drives' => []
        ];

        foreach ($results as $result) {
            $type = $result['type'] ?? 'unknown';

            switch ($type) {
                case 'file':
                case 'document':  // Backward compatibility
                    $grouped['files'][] = $result;
                    break;
                case 'site':
                    $grouped['sites'][] = $result;
                    break;
                case 'page':
                    $grouped['pages'][] = $result;
                    break;
                case 'list':
                    $grouped['lists'][] = $result;
                    break;
                case 'drive':
                    $grouped['drives'][] = $result;
                    break;
                default:
                    // Unknown types go to pages as fallback
                    $grouped['pages'][] = $result;
            }
        }

        // Remove empty groups
        return array_filter($grouped, fn($group) => !empty($group));
    }

    /**
     * Create a human-readable summary of grouped results
     *
     * @param array $groupedResults Results grouped by type
     * @return string Summary like "Files: 8, Sites: 2, Pages: 5"
     */
    private function createGroupedSummary(array $groupedResults): string
    {
        $summaryParts = [];

        $typeLabels = [
            'files' => 'Files',
            'sites' => 'Sites',
            'pages' => 'Pages',
            'lists' => 'Lists',
            'drives' => 'Drives'
        ];

        foreach ($groupedResults as $type => $items) {
            if (!empty($items)) {
                $label = $typeLabels[$type] ?? ucfirst($type);
                $count = count($items);
                $summaryParts[] = "$label: $count";
            }
        }

        return implode(', ', $summaryParts);
    }
}
