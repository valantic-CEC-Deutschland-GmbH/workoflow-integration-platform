<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OutlookCalendarService
{
    private const GRAPH_API_BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me', [
                'auth_bearer' => $credentials['access_token'],
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            error_log('Outlook Calendar test connection failed: ' . $e->getMessage());
            return false;
        }
    }

    public function listEvents(array $credentials, string $startDateTime, string $endDateTime, ?string $calendarId = null): array
    {
        try {
            $endpoint = $calendarId
                ? self::GRAPH_API_BASE . '/me/calendars/' . urlencode($calendarId) . '/calendarView'
                : self::GRAPH_API_BASE . '/me/calendarView';

            $response = $this->httpClient->request('GET', $endpoint, [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    'startDateTime' => $startDateTime,
                    'endDateTime' => $endDateTime,
                    '$top' => 50,
                    '$select' => 'id,subject,start,end,location,organizer,attendees,isOnlineMeeting,onlineMeetingUrl,isAllDay,isCancelled,showAs',
                    '$orderby' => 'start/dateTime',
                ],
            ]);

            $data = $response->toArray();
            $events = [];

            foreach ($data['value'] ?? [] as $event) {
                $events[] = $this->formatEvent($event);
            }

            return [
                'events' => $events,
                'count' => count($events),
                'startDateTime' => $startDateTime,
                'endDateTime' => $endDateTime,
            ];
        } catch (\Exception $e) {
            error_log('Outlook Calendar list events failed: ' . $e->getMessage());
            return ['error' => 'Failed to list events: ' . $e->getMessage(), 'events' => [], 'count' => 0];
        }
    }

    public function getEvent(array $credentials, string $eventId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/events/' . urlencode($eventId), [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$select' => 'id,subject,body,start,end,location,organizer,attendees,isOnlineMeeting,onlineMeetingUrl,onlineMeeting,isAllDay,isCancelled,showAs,recurrence,categories',
                ],
            ]);

            $event = $response->toArray();

            $bodyContent = $event['body']['content'] ?? '';
            if (($event['body']['contentType'] ?? '') === 'html') {
                $bodyContent = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyContent));
                $bodyContent = preg_replace('/\n{3,}/', "\n\n", trim($bodyContent));
            }

            $result = $this->formatEvent($event);
            $result['body'] = $bodyContent;
            $result['attendees'] = array_map(fn($a) => [
                'name' => $a['emailAddress']['name'] ?? '',
                'email' => $a['emailAddress']['address'] ?? '',
                'type' => $a['type'] ?? 'required',
                'responseStatus' => $a['status']['response'] ?? 'none',
            ], $event['attendees'] ?? []);
            $result['recurrence'] = $event['recurrence'] ?? null;
            $result['categories'] = $event['categories'] ?? [];

            if (isset($event['onlineMeeting']['joinUrl'])) {
                $result['onlineMeetingJoinUrl'] = $event['onlineMeeting']['joinUrl'];
            }

            return $result;
        } catch (\Exception $e) {
            error_log('Outlook Calendar get event failed: ' . $e->getMessage());
            return ['error' => 'Failed to get event: ' . $e->getMessage()];
        }
    }

    public function searchEvents(array $credentials, string $query, ?string $startDateTime = null, ?string $endDateTime = null): array
    {
        try {
            $filterParts = ["contains(subject,'" . addslashes($query) . "')"];

            if ($startDateTime) {
                $filterParts[] = "start/dateTime ge '{$startDateTime}'";
            }
            if ($endDateTime) {
                $filterParts[] = "end/dateTime le '{$endDateTime}'";
            }

            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/events', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$filter' => implode(' and ', $filterParts),
                    '$top' => 25,
                    '$select' => 'id,subject,start,end,location,organizer,isOnlineMeeting,isAllDay,isCancelled,showAs',
                    '$orderby' => 'start/dateTime desc',
                ],
            ]);

            $data = $response->toArray();
            $events = [];

            foreach ($data['value'] ?? [] as $event) {
                $events[] = $this->formatEvent($event);
            }

            return [
                'events' => $events,
                'count' => count($events),
                'query' => $query,
            ];
        } catch (\Exception $e) {
            error_log('Outlook Calendar search events failed: ' . $e->getMessage());
            return ['error' => 'Search failed: ' . $e->getMessage(), 'events' => [], 'count' => 0];
        }
    }

    public function checkAvailability(array $credentials, array $emailAddresses, string $startDateTime, string $endDateTime): array
    {
        try {
            $schedules = array_map(fn($email) => $email, $emailAddresses);

            $response = $this->httpClient->request('POST', self::GRAPH_API_BASE . '/me/calendar/getSchedule', [
                'auth_bearer' => $credentials['access_token'],
                'json' => [
                    'schedules' => $schedules,
                    'startTime' => [
                        'dateTime' => $startDateTime,
                        'timeZone' => 'UTC',
                    ],
                    'endTime' => [
                        'dateTime' => $endDateTime,
                        'timeZone' => 'UTC',
                    ],
                    'availabilityViewInterval' => 30,
                ],
            ]);

            $data = $response->toArray();
            $results = [];

            foreach ($data['value'] ?? [] as $schedule) {
                $items = [];
                foreach ($schedule['scheduleItems'] ?? [] as $item) {
                    $items[] = [
                        'status' => $item['status'] ?? 'unknown',
                        'subject' => $item['subject'] ?? '',
                        'start' => $item['start']['dateTime'] ?? '',
                        'end' => $item['end']['dateTime'] ?? '',
                    ];
                }

                $results[] = [
                    'email' => $schedule['scheduleId'] ?? '',
                    'availabilityView' => $schedule['availabilityView'] ?? '',
                    'scheduleItems' => $items,
                ];
            }

            return [
                'schedules' => $results,
                'startDateTime' => $startDateTime,
                'endDateTime' => $endDateTime,
            ];
        } catch (\Exception $e) {
            error_log('Outlook Calendar check availability failed: ' . $e->getMessage());
            return ['error' => 'Availability check failed: ' . $e->getMessage(), 'schedules' => []];
        }
    }

    public function listCalendars(array $credentials): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/calendars', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$select' => 'id,name,color,isDefaultCalendar,canEdit,owner',
                    '$top' => 50,
                ],
            ]);

            $data = $response->toArray();
            $calendars = [];

            foreach ($data['value'] ?? [] as $cal) {
                $calendars[] = [
                    'id' => $cal['id'],
                    'name' => $cal['name'] ?? '',
                    'color' => $cal['color'] ?? '',
                    'isDefault' => $cal['isDefaultCalendar'] ?? false,
                    'canEdit' => $cal['canEdit'] ?? false,
                    'owner' => [
                        'name' => $cal['owner']['name'] ?? '',
                        'email' => $cal['owner']['address'] ?? '',
                    ],
                ];
            }

            return ['calendars' => $calendars, 'count' => count($calendars)];
        } catch (\Exception $e) {
            error_log('Outlook Calendar list calendars failed: ' . $e->getMessage());
            return ['error' => 'Failed to list calendars: ' . $e->getMessage(), 'calendars' => []];
        }
    }

    public function refreshToken(string $refreshToken, string $clientId, string $clientSecret, string $tenantId): array
    {
        $response = $this->httpClient->request('POST', "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'body' => [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'scope' => 'openid profile email offline_access https://graph.microsoft.com/Calendars.Read https://graph.microsoft.com/User.Read',
            ],
        ]);

        $data = $response->toArray();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at' => time() + ($data['expires_in'] ?? 3600),
        ];
    }

    private function formatEvent(array $event): array
    {
        return [
            'id' => $event['id'],
            'subject' => $event['subject'] ?? '(No Subject)',
            'start' => $event['start']['dateTime'] ?? '',
            'startTimeZone' => $event['start']['timeZone'] ?? 'UTC',
            'end' => $event['end']['dateTime'] ?? '',
            'endTimeZone' => $event['end']['timeZone'] ?? 'UTC',
            'location' => $event['location']['displayName'] ?? '',
            'organizer' => [
                'name' => $event['organizer']['emailAddress']['name'] ?? '',
                'email' => $event['organizer']['emailAddress']['address'] ?? '',
            ],
            'isOnlineMeeting' => $event['isOnlineMeeting'] ?? false,
            'onlineMeetingUrl' => $event['onlineMeetingUrl'] ?? null,
            'isAllDay' => $event['isAllDay'] ?? false,
            'isCancelled' => $event['isCancelled'] ?? false,
            'showAs' => $event['showAs'] ?? 'busy',
        ];
    }
}
