<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\OutlookCalendarService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

class OutlookCalendarIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private OutlookCalendarService $outlookCalendarService,
        private EncryptionService $encryptionService,
        private EntityManagerInterface $entityManager,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'outlook_calendar';
    }

    public function getName(): string
    {
        return 'Outlook Calendar';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'outlook_calendar_list_events',
                'List calendar events within a date range. Expands recurring events into individual occurrences. Returns subject, start/end times, location, organizer, attendees count, and online meeting status.',
                [
                    [
                        'name' => 'startDateTime',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Start of date range in ISO 8601 format (e.g., "2026-02-10T00:00:00Z" or "2026-02-10T00:00:00+01:00")'
                    ],
                    [
                        'name' => 'endDateTime',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'End of date range in ISO 8601 format (e.g., "2026-02-14T23:59:59Z")'
                    ],
                    [
                        'name' => 'calendarId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Specific calendar ID (from outlook_calendar_list_calendars). If omitted, uses the default calendar.'
                    ]
                ]
            ),
            new ToolDefinition(
                'outlook_calendar_get_event',
                'Get full details of a specific calendar event by ID. Returns body text, all attendees with response status, recurrence pattern, online meeting join URL, and categories.',
                [
                    [
                        'name' => 'eventId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The event ID (from list or search results)'
                    ]
                ]
            ),
            new ToolDefinition(
                'outlook_calendar_search',
                'Search calendar events by subject text. Optionally filter by date range. Returns matching events sorted by date.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search text to match against event subjects (e.g., "standup", "team meeting", "review")'
                    ],
                    [
                        'name' => 'startDateTime',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional: Only return events starting after this ISO 8601 datetime'
                    ],
                    [
                        'name' => 'endDateTime',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional: Only return events ending before this ISO 8601 datetime'
                    ]
                ]
            ),
            new ToolDefinition(
                'outlook_calendar_check_availability',
                'Check free/busy availability for one or more people during a time range. Returns availability status (free, busy, tentative, out of office) per person. Useful for scheduling meetings.',
                [
                    [
                        'name' => 'emailAddresses',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'Array of email addresses to check availability for (e.g., ["john@company.com", "sarah@company.com"])'
                    ],
                    [
                        'name' => 'startDateTime',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Start of time range in ISO 8601 format'
                    ],
                    [
                        'name' => 'endDateTime',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'End of time range in ISO 8601 format'
                    ]
                ]
            ),
            new ToolDefinition(
                'outlook_calendar_list_calendars',
                'List all calendars the user has access to. Returns calendar name, color, whether it\'s the default calendar, edit permissions, and owner information.',
                []
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Outlook Calendar integration requires credentials');
        }

        if (isset($credentials['expires_at']) && time() >= $credentials['expires_at']) {
            $credentials = $this->refreshTokenIfNeeded($credentials);
            if (isset($parameters['configId'])) {
                $this->persistRefreshedCredentials((int) $parameters['configId'], $credentials);
            }
        }

        return match ($toolName) {
            'outlook_calendar_list_events' => $this->outlookCalendarService->listEvents(
                $credentials,
                $parameters['startDateTime'],
                $parameters['endDateTime'],
                $parameters['calendarId'] ?? null
            ),
            'outlook_calendar_get_event' => $this->outlookCalendarService->getEvent(
                $credentials,
                $parameters['eventId']
            ),
            'outlook_calendar_search' => $this->outlookCalendarService->searchEvents(
                $credentials,
                $parameters['query'],
                $parameters['startDateTime'] ?? null,
                $parameters['endDateTime'] ?? null
            ),
            'outlook_calendar_check_availability' => $this->outlookCalendarService->checkAvailability(
                $credentials,
                $parameters['emailAddresses'],
                $parameters['startDateTime'],
                $parameters['endDateTime']
            ),
            'outlook_calendar_list_calendars' => $this->outlookCalendarService->listCalendars($credentials),
            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        return isset($credentials['access_token']);
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'oauth',
                'oauth',
                'Connect with Microsoft',
                null,
                true,
                'Authenticate with your Microsoft account to access Outlook Calendar'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/outlook_calendar_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }

    public function isExperimental(): bool
    {
        return true;
    }

    public function getSetupInstructions(): ?string
    {
        return null;
    }

    public function getLogoPath(): string
    {
        return '/images/logos/outlook-calendar-icon.svg';
    }

    private function persistRefreshedCredentials(int $configId, array $credentials): void
    {
        try {
            $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
            if ($config) {
                $config->setEncryptedCredentials(
                    $this->encryptionService->encrypt(json_encode($credentials))
                );
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            error_log('Failed to persist refreshed Outlook Calendar credentials for config ' . $configId . ': ' . $e->getMessage());
        }
    }

    private function refreshTokenIfNeeded(array $credentials): array
    {
        if (!isset($credentials['refresh_token'])) {
            throw new \Exception('Outlook Calendar token expired and no refresh token available');
        }

        $newTokens = $this->outlookCalendarService->refreshToken(
            $credentials['refresh_token'],
            $_ENV['AZURE_CLIENT_ID'] ?? $credentials['client_id'],
            $_ENV['AZURE_CLIENT_SECRET'] ?? $credentials['client_secret'],
            $credentials['tenant_id']
        );

        return array_merge($credentials, [
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'],
            'expires_at' => $newTokens['expires_at']
        ]);
    }
}
