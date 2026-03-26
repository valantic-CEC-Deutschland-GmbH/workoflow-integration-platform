<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use InvalidArgumentException;
use App\Service\UrlNormalizer;
use Psr\Log\LoggerInterface;

class ProjektronService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private UrlNormalizer $urlNormalizer
    ) {
    }

    private function validateAndNormalizeUrl(string $url): string
    {
        return $this->urlNormalizer->normalize($url, requireHttps: true);
    }

    private function buildCookieHeader(string $jsessionid, ?string $csrfToken = null): string
    {
        if ($csrfToken !== null) {
            return sprintf('JSESSIONID=%s; CSRF_Token=%s', $jsessionid, $csrfToken);
        }
        return sprintf('JSESSIONID=%s', $jsessionid);
    }

    /**
     * Extract CSRF token from Projektron page
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @return string CSRF token
     * @throws InvalidArgumentException If CSRF token cannot be found
     */
    public function getCsrfToken(array $credentials): string
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

        $taskListUrl = $url . '/bcs/mybcs/tasklist';

        try {
            $response = $this->httpClient->request('GET', $taskListUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);

            $html = $response->getContent();

            // Try to extract CSRF token from hidden input field
            if (preg_match('/name="CSRF_Token"[^>]*value="([^"]+)"/', $html, $matches)) {
                return $matches[1];
            }

            // Try alternative pattern - value before name
            if (preg_match('/value="([^"]+)"[^>]*name="CSRF_Token"/', $html, $matches)) {
                return $matches[1];
            }

            // Try to extract from JavaScript variable
            if (preg_match('/CSRF_Token["\']?\s*[:=]\s*["\']([^"\']+)["\']/', $html, $matches)) {
                return $matches[1];
            }

            // Check response headers for Set-Cookie with CSRF_Token
            $responseHeaders = $response->getHeaders();
            if (isset($responseHeaders['set-cookie'])) {
                foreach ($responseHeaders['set-cookie'] as $cookie) {
                    if (preg_match('/CSRF_Token=([^;]+)/', $cookie, $matches)) {
                        return $matches[1];
                    }
                }
            }

            throw new InvalidArgumentException(
                'Could not find CSRF token in Projektron response. Your session may have expired or the page structure has changed.'
            );
        } catch (\Exception $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException(
                'Failed to retrieve CSRF token: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extract the user's OID from Projektron
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @return string User OID (e.g., "1611701511349_JUser")
     * @throws InvalidArgumentException If user OID cannot be found
     */
    public function getUserOid(array $credentials): string
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

        $taskListUrl = $url . '/bcs/mybcs/tasklist';

        try {
            $response = $this->httpClient->request('GET', $taskListUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);

            $html = $response->getContent();

            // Extract user OID from the HTML (pattern: "1234567890_JUser")
            if (preg_match('/"(\d+_JUser)"/', $html, $matches)) {
                return $matches[1];
            }

            throw new InvalidArgumentException(
                'Could not find user OID in Projektron response. Your session may have expired.'
            );
        } catch (\Exception $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException(
                'Failed to retrieve user OID: ' . $e->getMessage()
            );
        }
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $url = $this->validateAndNormalizeUrl($credentials['domain']);
            $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

            $taskListUrl = $url . '/bcs/mybcs/tasklist';

            $response = $this->httpClient->request('GET', $taskListUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 10,
                'max_redirects' => 5,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->warning('Projektron connection test failed', [
                    'status_code' => $statusCode,
                    'url' => $taskListUrl
                ]);
                return false;
            }

            $html = $response->getContent();

            // Check if we got a valid Projektron task list page (not a login page)
            if (strpos($html, 'tasklist') === false && strpos($html, 'login') !== false) {
                $this->logger->warning('Projektron returned login page - authentication failed');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Projektron connection test exception', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all bookable tasks from the user's personal Projektron task list
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @return array Array of tasks with oid, name, and booking_url
     * @throws InvalidArgumentException If credentials are invalid or request fails
     */
    public function getAllTasks(array $credentials): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

        // Fetch user's task list - follows redirect to get user-specific task list
        $taskListUrl = $url . '/bcs/mybcs/tasklist';

        try {
            $response = $this->httpClient->request('GET', $taskListUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);

            $html = $response->getContent();

            // Check if we got redirected to login
            if (strpos($html, 'tasklist') === false && strpos($html, 'login') !== false) {
                throw new InvalidArgumentException(
                    'Authentication failed. Your session may have expired. Please update your JSESSIONID cookie.'
                );
            }

            return $this->parseTaskList($html, $url);
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                throw new InvalidArgumentException(
                    'Authentication failed. Please verify your JSESSIONID is correct and not expired.'
                );
            }

            throw new InvalidArgumentException(
                'Failed to fetch Projektron tasks: HTTP ' . $statusCode . ' - ' . $e->getMessage()
            );
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                'Failed to fetch Projektron tasks: ' . $e->getMessage()
            );
        }
    }

    /**
     * Parse task list HTML to extract bookable tasks using PHP 8.4 DOM Selector
     *
     * Extracts tasks from tr.row elements containing task cells.
     * Builds hierarchical name: "Project > Task"
     *
     * @param string $html HTML content from Projektron task list
     * @param string $baseUrl Base URL for building booking URLs
     * @return array Array of tasks with oid, name, and booking_url
     */
    private function parseTaskList(string $html, string $baseUrl): array
    {
        $tasks = [];

        try {
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Find all data rows (tr.row class)
            $rows = $dom->querySelectorAll('tr.row');

            foreach ($rows as $row) {
                // Get task cell and extract OID + name
                $taskCell = $this->findCellByName($row, 'effortTargetOid');
                if ($taskCell === null) {
                    continue;
                }

                $taskLink = $taskCell->querySelector('a[data-tt*="_JTask"]');
                if ($taskLink === null) {
                    continue;
                }

                $oid = $taskLink->getAttribute('data-tt');
                if (empty($oid)) {
                    continue;
                }

                $taskNameSpan = $taskLink->querySelector('span.hover');
                $taskName = $taskNameSpan !== null ? trim($taskNameSpan->textContent) : '';

                // Get project name from grandParentOid cell
                $projectName = '';
                $projectCell = $this->findCellByName($row, 'effortTargetOid.grandParentOid');
                if ($projectCell !== null) {
                    $projectNameSpan = $projectCell->querySelector('span.hover');
                    if ($projectNameSpan !== null) {
                        $projectName = trim($projectNameSpan->textContent);
                    }
                }

                // Build name: "Project > Task" (skip project if same as task)
                $fullName = $taskName;
                if (!empty($projectName) && $projectName !== $taskName) {
                    $fullName = $projectName . ' > ' . $taskName;
                }

                $tasks[$oid] = [
                    'oid' => $oid,
                    'name' => $fullName,
                    'booking_url' => $baseUrl . '/bcs/taskdetail/effortrecording/edit?oid=' . $oid,
                ];
            }

            return array_values($tasks);
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse Projektron task list HTML', [
                'error' => $e->getMessage()
            ]);

            throw new InvalidArgumentException(
                'Failed to parse Projektron task list: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get worklog (time entries) for a specific date
     *
     * Fetches individual booking entries for each day of the week containing the specified date.
     * Each entry includes the booking description/comment.
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @param int $day Day of month (1-31)
     * @param int $month Month (1-12)
     * @param int $year Year (e.g., 2025)
     * @return array Worklog data with individual entries including descriptions
     * @throws InvalidArgumentException If request fails or data cannot be parsed
     */
    public function getWorklog(array $credentials, int $day, int $month, int $year): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

        // Get user OID for the request
        $userOid = $this->getUserOid($credentials);

        // Calculate the week dates (Monday to Sunday) for the given date
        $targetDate = new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
        $weekDates = $this->getWeekDates($targetDate);

        $allEntries = [];
        $totalMinutes = 0;

        // Fetch each day's effort data individually
        // Each day's page shows only that day's bookings
        foreach ($weekDates as $dateStr) {
            $dateObj = new \DateTime($dateStr);
            $dayOfMonth = (int) $dateObj->format('d');
            $monthOfYear = (int) $dateObj->format('m');
            $yearNum = (int) $dateObj->format('Y');

            try {
                $dayUrl = $this->buildDayEffortUrl($url, $userOid, $dayOfMonth, $monthOfYear, $yearNum);

                $response = $this->httpClient->request('GET', $dayUrl, [
                    'headers' => [
                        'Cookie' => $cookieHeader,
                        'User-Agent' => 'Workoflow-Integration/1.0',
                    ],
                    'timeout' => 30,
                    'max_redirects' => 5,
                ]);

                $html = $response->getContent();

                // Check if we got redirected to login
                if (strpos($html, 'dayeffortrecording') === false && strpos($html, 'login') !== false) {
                    throw new InvalidArgumentException(
                        'Authentication failed. Your session may have expired. Please update your JSESSIONID cookie.'
                    );
                }

                $dayEntries = $this->parseDayEffortData($html, $dateStr);
                foreach ($dayEntries as $entry) {
                    $allEntries[] = $entry;
                    $totalMinutes += $entry['total_minutes'];
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to fetch worklog for day', [
                    'date' => $dateStr,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other days even if one fails
            }
        }

        // Sort entries by date
        usort($allEntries, fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return [
            'date' => [
                'day' => $day,
                'month' => $month,
                'year' => $year,
            ],
            'week_dates' => $weekDates,
            'total_week_hours' => round($totalMinutes / 60, 2),
            'count' => count($allEntries),
            'entries' => $allEntries,
        ];
    }

    /**
     * Get the dates for the week (Monday to Sunday) containing the given date
     *
     * @param \DateTime $date Reference date
     * @return array<string> Array of date strings in Y-m-d format
     */
    private function getWeekDates(\DateTime $date): array
    {
        $weekDates = [];
        $dayOfWeek = (int) $date->format('N'); // 1 = Monday, 7 = Sunday

        // Find Monday of this week
        $monday = clone $date;
        $monday->modify('-' . ($dayOfWeek - 1) . ' days');

        // Get Monday to Sunday (7 days)
        for ($i = 0; $i < 7; $i++) {
            $day = clone $monday;
            $day->modify('+' . $i . ' days');
            $weekDates[] = $day->format('Y-m-d');
        }

        return $weekDates;
    }

    /**
     * Build the day effort recording URL
     */
    private function buildDayEffortUrl(string $baseUrl, string $userOid, int $day, int $month, int $year): string
    {
        $timestamp = (int) (microtime(true) * 1000);

        return sprintf(
            '%s/bcs/mybcs/dayeffortrecording/display?oid=%s&%s&%s&%s&pagetimestamp=%d',
            $baseUrl,
            urlencode($userOid),
            'daytimerecording,Selections,effortRecordingDate,day=' . $day,
            'daytimerecording,Selections,effortRecordingDate,month=' . $month,
            'daytimerecording,Selections,effortRecordingDate,year=' . $year,
            $timestamp
        );
    }

    /**
     * Parse day effort recording HTML to extract individual time entries with descriptions
     *
     * Parses the dayeffortrecording page to get JEffort entries (actual bookings).
     * Each entry includes task, project, duration, and description.
     * Uses PHP 8.4 DOM selectors for cleaner extraction.
     *
     * @param string $html HTML content from Projektron dayeffortrecording page
     * @param string $date Date string in Y-m-d format
     * @return array<array> Array of effort entries
     */
    private function parseDayEffortData(string $html, string $date): array
    {
        $entries = [];

        try {
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Extract task and project names using existing DOM methods
            $taskNames = $this->extractTaskNamesFromDayEffort($html);
            $projectNames = $this->extractProjectNamesFromDayEffort($html);

            // Find all effort entries via textarea elements (each JEffort has one description textarea)
            $textareas = $dom->querySelectorAll('textarea[name*="_JEffort.description"]');

            foreach ($textareas as $textarea) {
                $name = $textarea->getAttribute('name');
                if ($name === null) {
                    continue;
                }

                // Extract effort OID from name pattern: "*listeditoid_1234567_JEffort.description"
                if (!preg_match('/(\d+_JEffort)/', $name, $oidMatch)) {
                    continue;
                }
                $effortOid = $oidMatch[1];
                $description = trim($textarea->textContent);

                // Use CSS attribute selectors to find related form fields
                $hourInput = $dom->querySelector('input[name*="' . $effortOid . '.effortExpense_hour"]');
                $minuteInput = $dom->querySelector('input[name*="' . $effortOid . '.effortExpense_minute"]');
                $taskInput = $dom->querySelector('input[name*="' . $effortOid . '.effortTargetOid"]');

                // Extract values with null safety
                $hourValue = $hourInput?->getAttribute('value') ?? '';
                $minuteValue = $minuteInput?->getAttribute('value') ?? '';
                $taskOid = $taskInput?->getAttribute('value');

                $hours = $hourValue !== '' ? (int) $hourValue : 0;
                $minutes = $minuteValue !== '' ? (int) $minuteValue : 0;
                $totalMinutes = ($hours * 60) + $minutes;

                // Skip entries with zero duration
                if ($totalMinutes === 0) {
                    continue;
                }

                $entries[] = [
                    'effort_oid' => $effortOid,
                    'date' => $date,
                    'task_oid' => $taskOid,
                    'task_name' => $taskOid ? ($taskNames[$taskOid] ?? '') : '',
                    'project_name' => $taskOid ? ($projectNames[$taskOid] ?? '') : '',
                    'hours' => $hours,
                    'minutes' => $minutes,
                    'total_minutes' => $totalMinutes,
                    'description' => $description,
                ];
            }

            return $entries;
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse Projektron day effort HTML', [
                'error' => $e->getMessage(),
                'date' => $date,
            ]);

            return [];
        }
    }

    /**
     * Extract task names from day effort recording HTML
     *
     * @param string $html HTML content
     * @return array<string, string> Map of task OID to task name
     */
    private function extractTaskNamesFromDayEffort(string $html): array
    {
        $taskNames = [];

        try {
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Find all task links with data-tt attribute containing _JTask
            $taskLinks = $dom->querySelectorAll('a[data-tt*="_JTask"]');

            foreach ($taskLinks as $link) {
                $oid = $link->getAttribute('data-tt');

                if (empty($oid)) {
                    continue;
                }

                // Extract task name from child span.hover element
                $nameSpan = $link->querySelector('span.hover');
                $name = $nameSpan ? trim($nameSpan->textContent) : '';

                if (!empty($name) && !isset($taskNames[$oid])) {
                    $taskNames[$oid] = $name;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not extract task names from HTML', [
                'error' => $e->getMessage()
            ]);
        }

        return $taskNames;
    }

    /**
     * Extract project names from day effort recording HTML
     *
     * @param string $html HTML content
     * @return array<string, string> Map of task OID to project name (task's parent project)
     */
    private function extractProjectNamesFromDayEffort(string $html): array
    {
        $projectNames = [];

        try {
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Find all rows with task data
            $rows = $dom->querySelectorAll('tr[data-taskoid]');

            foreach ($rows as $row) {
                $taskOid = $row->getAttribute('data-taskoid');
                if (empty($taskOid)) {
                    continue;
                }

                // Find project link in this row
                $projectCell = $this->findCellByName($row, 'effortTargetOid.grandParentOid');
                if ($projectCell !== null) {
                    $projectLink = $projectCell->querySelector('a[data-tt*="_JProject"]');
                    if ($projectLink !== null) {
                        $nameSpan = $projectLink->querySelector('span.hover');
                        $name = $nameSpan ? trim($nameSpan->textContent) : '';
                        if (!empty($name)) {
                            $projectNames[$taskOid] = $name;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not extract project names from HTML', [
                'error' => $e->getMessage()
            ]);
        }

        return $projectNames;
    }

    /**
     * Add a worklog entry to a Projektron task
     *
     * @param array $credentials Projektron credentials (domain, username, jsessionid)
     * @param string $taskOid Task OID (e.g., "1744315212023_JTask")
     * @param int $day Day of month (1-31)
     * @param int $month Month (1-12)
     * @param int $year Year (e.g., 2025)
     * @param int $hours Hours to book (0-23)
     * @param int $minutes Minutes to book (0-59)
     * @param string $description Work description
     * @return array Result with success flag and message
     * @throws InvalidArgumentException If request fails
     */
    public function addWorklog(
        array $credentials,
        string $taskOid,
        int $day,
        int $month,
        int $year,
        int $hours,
        int $minutes,
        string $description
    ): array {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);

        // Use provided CSRF token from credentials
        if (empty($credentials['csrf_token'])) {
            throw new InvalidArgumentException(
                'CSRF token is required for worklog booking. Please update your Projektron credentials with the CSRF_Token from your browser cookies.'
            );
        }
        $csrfToken = $credentials['csrf_token'];

        // Build cookie header with both JSESSIONID and CSRF_Token
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid'], $csrfToken);

        // Build date timestamp for the target booking date
        // Use Europe/Berlin timezone as Projektron expects timestamps in German local time
        $date = new \DateTime(
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            new \DateTimeZone('Europe/Berlin')
        );
        $date->setTime(0, 0, 0);
        $timestamp = (string) ($date->getTimestamp() * 1000);

        // Build form payload - matches working browser request
        $payload = [
            'daytimerecording,formsubmitted' => 'true',
            'daytimerecording,Content,singleeffort,TaskSelector,fixedtask,Data_CustomTitle' => 'Einzelbuchen',
            'daytimerecording,Content,singleeffort,TaskSelector,fixedtask,Data_FirstOnPage' => 'daytimerecording',
            'daytimerecording,Content,singleeffort,TaskSelector,fixedtask,task,task' => $taskOid,
            'daytimerecording,Content,singleeffort,TaskSelector,fixedtask,edit_form_data_submitted' => 'true',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortStart,effortStart_hour' => '',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortStart,effortStart_minute' => '',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortEnd,effortEnd_hour' => '',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortEnd,effortEnd_minute' => '',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortExpense,effortExpense_hour' => (string) $hours,
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortExpense,effortExpense_minute' => str_pad((string) $minutes, 2, '0', STR_PAD_LEFT),
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortChargeability,effortChargeability' => 'effortIsChargable_false+effortIsShown_false',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortActivity,effortActivity' => 'nicht_anrechenbar',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortActivity,effortActivity_widgetType' => 'option',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,effortWorkingTimeType,effortWorkingTimeType' => 'Standard',
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,description,description' => $description,
            'daytimerecording,Content,singleeffort,EffortEditor,effort1,edit_form_data_submitted' => 'true',
            'daytimerecording,Content,singleeffort,recordType' => 'neweffort',
            'daytimerecording,Content,singleeffort,recordOid' => '',
            'daytimerecording,Content,singleeffort,recordDate' => $timestamp,
            'daytimerecording,editableComponentNames' => 'daytimerecording,Content,singleeffort daytimerecording,Content,daytimerecordingAllBookingsOfTheDay',
            'oid' => $taskOid,
            'user' => $credentials['username'],
            'action,MultiComponentDayTimeRecordingAction,daytimerecording' => '0',
            'BCS.ConfirmDiscardChangesDialog,InitialApplyButtonsOnError' => 'daytimerecording,Apply',
            'CSRF_Token' => $csrfToken,
            'daytimerecording,Apply' => 'daytimerecording,Apply',
            'submitButtonPressed' => 'daytimerecording,Apply',
        ];

        $bookingUrl = $url . '/bcs/taskdetail/effortrecording/edit?oid=' . urlencode($taskOid);
        // Build query and restore + signs that http_build_query encodes to %2B
        // Projektron expects literal + in values like "effortIsChargable_false+effortIsShown_false"
        $encodedPayload = str_replace('%2B', '+', http_build_query($payload));

        // Build curl command for debugging
        $curlCommand = sprintf(
            "curl '%s' \\\n" .
            "  -H 'Content-Type: application/x-www-form-urlencoded' \\\n" .
            "  -H 'Cookie: %s' \\\n" .
            "  -H 'User-Agent: Workoflow-Integration/1.0' \\\n" .
            "  --data-raw '%s'",
            $bookingUrl,
            $cookieHeader,
            $encodedPayload
        );

        $this->logger->info('Projektron worklog booking - CURL command for debugging', [
            'curl_command' => $curlCommand,
            'url' => $bookingUrl,
            'task_oid' => $taskOid,
            'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'timestamp' => $timestamp,
            'hours' => $hours,
            'minutes' => $minutes,
            'payload_fields' => array_keys($payload),
        ]);

        try {
            $response = $this->httpClient->request('POST', $bookingUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $encodedPayload,
                'timeout' => 30,
                'max_redirects' => 5,
            ]);

            $html = $response->getContent();
            $statusCode = $response->getStatusCode();

            // Log response details for debugging
            $this->logger->info('Projektron worklog booking - Response received', [
                'status_code' => $statusCode,
                'response_length' => strlen($html),
                'has_affirmation' => strpos($html, '<div class="msg affirmation">') !== false,
                'has_error_msg' => strpos($html, '<div class="msg error">') !== false,
                'has_warning_msg' => strpos($html, '<div class="msg warning">') !== false,
                'response_snippet' => substr($html, 0, 2000),
            ]);

            // Check for success indicator
            if (strpos($html, '<div class="msg affirmation">') !== false) {
                $this->logger->info('Projektron worklog added successfully', [
                    'task_oid' => $taskOid,
                    'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                    'hours' => $hours,
                    'minutes' => $minutes,
                ]);

                return [
                    'success' => true,
                    'message' => sprintf(
                        'Successfully booked %dh %02dm to task on %04d-%02d-%02d',
                        $hours,
                        $minutes,
                        $year,
                        $month,
                        $day
                    ),
                    'task_oid' => $taskOid,
                    'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                    'hours' => $hours,
                    'minutes' => $minutes,
                    'description' => $description,
                ];
            }

            // Check if we got redirected to login
            if (strpos($html, 'login') !== false) {
                throw new InvalidArgumentException(
                    'Authentication failed. Your session may have expired. Please update your JSESSIONID cookie.'
                );
            }

            // Log the failure for debugging
            $this->logger->error('Projektron worklog booking failed - no success indicator', [
                'task_oid' => $taskOid,
                'response_length' => strlen($html),
            ]);

            throw new InvalidArgumentException(
                'Failed to book worklog entry. The request was processed but Projektron did not confirm success. ' .
                'Please verify the task OID is valid and you have permission to book time to this task.'
            );
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                throw new InvalidArgumentException(
                    'Authentication failed. Please verify your JSESSIONID is correct and not expired.'
                );
            }

            throw new InvalidArgumentException(
                'Failed to book Projektron worklog: HTTP ' . $statusCode . ' - ' . $e->getMessage()
            );
        } catch (\Exception $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException(
                'Failed to book Projektron worklog: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get absences (vacation, sickness, other leave) for a specific year
     *
     * @param array $credentials Projektron credentials (domain, jsessionid)
     * @param int $day Day of month (1-31) - used as reference date
     * @param int $month Month (1-12) - used as reference date
     * @param int $year Year to fetch absences for (e.g., 2025)
     * @return array Absences data with entries and summary
     * @throws InvalidArgumentException If request fails or data cannot be parsed
     */
    public function getAbsences(array $credentials, int $day, int $month, int $year): array
    {
        $url = $this->validateAndNormalizeUrl($credentials['domain']);
        $cookieHeader = $this->buildCookieHeader($credentials['jsessionid']);

        // Get user OID for the request
        $userOid = $this->getUserOid($credentials);

        // Build vacation URL with date parameters
        $absencesUrl = $this->buildAbsencesUrl($url, $userOid, $day, $month, $year);

        try {
            $response = $this->httpClient->request('GET', $absencesUrl, [
                'headers' => [
                    'Cookie' => $cookieHeader,
                    'User-Agent' => 'Workoflow-Integration/1.0',
                ],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);

            $html = $response->getContent();

            // Check if we got redirected to login
            if (strpos($html, 'vacation') === false && strpos($html, 'login') !== false) {
                throw new InvalidArgumentException(
                    'Authentication failed. Your session may have expired. Please update your JSESSIONID cookie.'
                );
            }

            return $this->parseAbsencesData($html, $day, $month, $year);
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                throw new InvalidArgumentException(
                    'Authentication failed. Please verify your JSESSIONID is correct and not expired.'
                );
            }

            throw new InvalidArgumentException(
                'Failed to fetch Projektron absences: HTTP ' . $statusCode . ' - ' . $e->getMessage()
            );
        } catch (\Exception $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException(
                'Failed to fetch Projektron absences: ' . $e->getMessage()
            );
        }
    }

    /**
     * Build the absences/vacation URL with date parameters
     */
    private function buildAbsencesUrl(string $baseUrl, string $userOid, int $day, int $month, int $year): string
    {
        $timestamp = (int) (microtime(true) * 1000);

        return sprintf(
            '%s/bcs/mybcs/vacation/display?oid=%s&%s&%s&%s&pagetimestamp=%d',
            $baseUrl,
            urlencode($userOid),
            'group,Choices,vacationlist,Selections,dateRange,day=' . $day,
            'group,Choices,vacationlist,Selections,dateRange,month=' . $month,
            'group,Choices,vacationlist,Selections,dateRange,year=' . $year,
            $timestamp
        );
    }

    /**
     * Parse absences HTML to extract vacation/sickness entries and summary
     *
     * @param string $html HTML content from Projektron vacation page
     * @param int $day Requested day
     * @param int $month Requested month
     * @param int $year Requested year
     * @return array Parsed absences data
     */
    private function parseAbsencesData(string $html, int $day, int $month, int $year): array
    {
        $absences = [];
        $summary = [
            'vacation_days_total' => null,
            'vacation_days_used' => null,
            'vacation_days_remaining' => null,
            'vacation_days_planned' => null,
            'vacation_days_submitted' => null,
            'sick_days' => null,
        ];

        try {
            // Extract absence entries using regex patterns
            // Pattern: href="/bcs/event(vacation|sickness)detail?oid=OID&eventStartDate=DD.MM.YYYY"
            preg_match_all(
                '/href="\/bcs\/event(vacation|sickness)detail\?oid=(\d+_JAppointment)&amp;eventStartDate=(\d{2}\.\d{2}\.\d{4})/',
                $html,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $type = $match[1]; // 'vacation' or 'sickness'
                $oid = $match[2];
                $startDateStr = $match[3];

                // Convert date from DD.MM.YYYY to YYYY-MM-DD
                $startDate = $this->convertGermanDate($startDateStr);

                // Skip if we already have this absence (by OID)
                if (isset($absences[$oid])) {
                    continue;
                }

                $absences[$oid] = [
                    'oid' => $oid,
                    'type' => $type,
                    'type_label' => $type === 'vacation' ? 'Urlaub' : 'Krankheit',
                    'start_date' => $startDate,
                    'end_date' => null, // Will be extracted from table if available
                    'duration_days' => null,
                    'status' => null,
                    'status_label' => null,
                    'approver' => null,
                    'created_at' => null,
                ];
            }

            // Try to extract additional details using DOM parsing
            $this->enrichAbsencesFromDom($html, $absences);

            // Extract summary statistics from budget table
            $this->extractSummaryFromHtml($html, $summary);

            // Count sick days from absences
            $sickDays = 0;
            foreach ($absences as $absence) {
                if ($absence['type'] === 'sickness' && $absence['duration_days'] !== null) {
                    $sickDays += $absence['duration_days'];
                }
            }
            if ($sickDays > 0) {
                $summary['sick_days'] = $sickDays;
            }

            return [
                'date' => [
                    'day' => $day,
                    'month' => $month,
                    'year' => $year,
                ],
                'count' => count($absences),
                'absences' => array_values($absences),
                'summary' => $summary,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse Projektron absences HTML', [
                'error' => $e->getMessage()
            ]);

            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }

            throw new InvalidArgumentException(
                'Failed to parse Projektron absences: ' . $e->getMessage()
            );
        }
    }

    /**
     * Convert German date format (DD.MM.YYYY) to ISO format (YYYY-MM-DD)
     */
    private function convertGermanDate(string $germanDate): string
    {
        $parts = explode('.', $germanDate);
        if (count($parts) !== 3) {
            return $germanDate;
        }
        return sprintf('%s-%s-%s', $parts[2], $parts[1], $parts[0]);
    }

    /**
     * Enrich absence data with additional details from DOM parsing
     */
    private function enrichAbsencesFromDom(string $html, array &$absences): void
    {
        try {
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Status mapping including all German status values found in Projektron
            $statusMap = [
                'Genehmigt' => ['status' => 'approved', 'label' => 'Genehmigt'],
                'Approved' => ['status' => 'approved', 'label' => 'Genehmigt'],
                'Beantragt' => ['status' => 'submitted', 'label' => 'Beantragt'],
                'Submitted' => ['status' => 'submitted', 'label' => 'Beantragt'],
                'Abgelehnt' => ['status' => 'rejected', 'label' => 'Abgelehnt'],
                'Rejected' => ['status' => 'rejected', 'label' => 'Abgelehnt'],
                'Abgesagt' => ['status' => 'canceled', 'label' => 'Abgesagt'],
                'Canceled' => ['status' => 'canceled', 'label' => 'Abgesagt'],
                'Genommen' => ['status' => 'taken', 'label' => 'Genommen'],
                'Taken' => ['status' => 'taken', 'label' => 'Genommen'],
                'Beendet' => ['status' => 'completed', 'label' => 'Beendet'],
                'Completed' => ['status' => 'completed', 'label' => 'Beendet'],
                'Ended' => ['status' => 'completed', 'label' => 'Beendet'],
                'Geplant' => ['status' => 'planned', 'label' => 'Geplant'],
                'Planned' => ['status' => 'planned', 'label' => 'Geplant'],
            ];

            $enrichedCount = 0;
            foreach ($absences as $oid => &$absence) {
                // Find the row using data-row-id attribute with fallback
                $row = $this->findRowByDataRowId($dom, $oid);
                if (!$row) {
                    $this->logger->debug('Absence row not found', ['oid' => $oid]);
                    continue;
                }

                // Extract start date from eventStartDate column
                // Format: "Do 20.02.25" (weekday + DD.MM.YY) or "20.02.2025"
                $startDateCell = $this->findCellByName($row, 'eventStartDate');
                if ($startDateCell !== null) {
                    $startDate = $this->extractDateFromCell($this->cleanCellText($startDateCell->textContent));
                    if ($startDate !== null) {
                        $absence['start_date'] = $startDate;
                    }
                }

                // Extract end date from eventEndDate column
                $endDateCell = $this->findCellByName($row, 'eventEndDate');
                if ($endDateCell !== null) {
                    $endDate = $this->extractDateFromCell($this->cleanCellText($endDateCell->textContent));
                    if ($endDate !== null) {
                        $absence['end_date'] = $endDate;
                    }
                }

                // Extract duration from vacationDurationInPeriod column
                // Format: "1,00t " (number + 't' suffix for "Tage" = days)
                $durationCell = $this->findCellByName($row, 'vacationDurationInPeriod');
                if ($durationCell !== null) {
                    $durationText = $this->cleanCellText($durationCell->textContent);
                    // Handle format: "1", "1,00", "1,00t", "1,00t " (with nbsp)
                    if (preg_match('/^(\d+(?:,\d+)?)\s*t?\s*$/u', $durationText, $durationMatch)) {
                        $duration = str_replace(',', '.', $durationMatch[1]);
                        $absence['duration_days'] = (float) $duration;
                    }
                }

                // Extract status from state column
                $stateCell = $this->findCellByName($row, 'state');
                if ($stateCell !== null) {
                    $stateText = $this->cleanCellText($stateCell->textContent);
                    foreach ($statusMap as $keyword => $statusInfo) {
                        if (stripos($stateText, $keyword) !== false) {
                            $absence['status'] = $statusInfo['status'];
                            $absence['status_label'] = $statusInfo['label'];
                            break;
                        }
                    }
                }

                // Extract created date from insDate column
                // Format: "27.11.24 10:33"
                $createdCell = $this->findCellByName($row, 'insDate');
                if ($createdCell !== null) {
                    $createdText = $this->cleanCellText($createdCell->textContent);
                    $createdDate = $this->extractDateTimeFromCell($createdText);
                    if ($createdDate !== null) {
                        $absence['created_at'] = $createdDate;
                    }
                }

                // If end_date is still null, set it to start_date (single day absence)
                if ($absence['end_date'] === null) {
                    $absence['end_date'] = $absence['start_date'];
                }

                $enrichedCount++;
            }

            $this->logger->debug('Absences DOM enrichment completed', [
                'total_absences' => count($absences),
                'enriched_count' => $enrichedCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Could not enrich absences from DOM', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Find a table row by data-row-id attribute with fallback iteration
     */
    private function findRowByDataRowId(\Dom\HTMLDocument $dom, string $oid): ?\Dom\Element
    {
        // Try direct CSS selector first
        $row = $dom->querySelector('tr[data-row-id="' . $oid . '"]');
        if ($row !== null) {
            return $row;
        }

        // Fallback: iterate all tr elements and match by attribute
        $allRows = $dom->querySelectorAll('tr');
        foreach ($allRows as $tr) {
            if ($tr->getAttribute('data-row-id') === $oid) {
                return $tr;
            }
        }

        return null;
    }

    /**
     * Find a td cell by name attribute with fallback iteration
     */
    private function findCellByName(\Dom\Element $row, string $name): ?\Dom\Element
    {
        // Try direct CSS selector first
        $cell = $row->querySelector('td[name="' . $name . '"]');
        if ($cell !== null) {
            return $cell;
        }

        // Fallback: iterate all td elements and match by name attribute
        $allCells = $row->querySelectorAll('td');
        foreach ($allCells as $td) {
            if ($td->getAttribute('name') === $name) {
                return $td;
            }
        }

        return null;
    }

    /**
     * Clean cell text content by decoding entities and normalizing whitespace
     */
    private function cleanCellText(string $text): string
    {
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize whitespace including non-breaking space (\u00A0)
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Extract date from cell text, handling various German formats
     * Supports: "Do 20.02.25", "20.02.2025", "20.02.25"
     */
    private function extractDateFromCell(string $text): ?string
    {
        $text = trim($text);
        // Match DD.MM.YY or DD.MM.YYYY (optionally with weekday prefix)
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{2,4})/', $text, $match)) {
            $day = $match[1];
            $month = $match[2];
            $year = $match[3];
            // Handle 2-digit year
            if (strlen($year) === 2) {
                $year = '20' . $year;
            }
            return sprintf('%s-%s-%s', $year, $month, $day);
        }
        return null;
    }

    /**
     * Extract datetime from cell text
     * Supports: "27.11.24 10:33", "27.11.2024 10:33"
     */
    private function extractDateTimeFromCell(string $text): ?string
    {
        $text = trim($text);
        // Match DD.MM.YY HH:MM or DD.MM.YYYY HH:MM
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{2,4})\s+(\d{2}):(\d{2})/', $text, $match)) {
            $day = $match[1];
            $month = $match[2];
            $year = $match[3];
            $hour = $match[4];
            $minute = $match[5];
            // Handle 2-digit year
            if (strlen($year) === 2) {
                $year = '20' . $year;
            }
            return sprintf('%s-%s-%s %s:%s', $year, $month, $day, $hour, $minute);
        }
        return null;
    }

    /**
     * Extract vacation summary statistics from HTML
     */
    private function extractSummaryFromHtml(string $html, array &$summary): void
    {
        try {
            $dom = \Dom\HTMLDocument::createFromString($html);

            // Map of summary fields to their cell name attributes
            // The budget table contains cells with these name attributes
            $summaryFields = [
                'vacation_days_total' => 'vacationIndicatorTotalBudget',
                'vacation_days_remaining' => 'appointmentIndicatorRemainingVacationToday',
                'vacation_days_planned' => 'appointmentIndicatorSumVacationDurationPlanned',
                'vacation_days_submitted' => 'appointmentIndicatorSumVacationDurationSubmitted',
                'vacation_days_used' => 'appointmentIndicatorVacationDurationApprovedAndTaken',
            ];

            foreach ($summaryFields as $summaryKey => $cellName) {
                // Find the content cell (not header) by name attribute
                // Content cells have class "content" while headers have class "listheader"
                $cell = $dom->querySelector('td.content[name="' . $cellName . '"]');
                if ($cell !== null) {
                    $value = $this->extractNumericValue($cell->textContent);
                    if ($value !== null) {
                        $summary[$summaryKey] = $value;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not extract vacation summary', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extract numeric value from text, handling German format
     * Supports: "31,0", "31,00", "0", "31,00t"
     */
    private function extractNumericValue(string $text): ?float
    {
        $text = trim($text);
        // Match number with optional decimal (German format with comma)
        // Also handles optional 't' suffix for "Tage" (days)
        if (preg_match('/^(\d+(?:,\d+)?)\s*t?\s*$/u', $text, $match)) {
            return (float) str_replace(',', '.', $match[1]);
        }
        return null;
    }
}
