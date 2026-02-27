<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Integration\ToolCategory;
use App\Service\Integration\ProjektronService;
use Twig\Environment;

class ProjektronIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private ProjektronService $projektronService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'projektron';
    }

    public function getName(): string
    {
        return 'BCS (Projektron)';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'projektron_get_all_tasks',
                'Get all bookable tasks from the user\'s personal Projektron task list. Returns an array of items, each containing: oid (unique task identifier like "1744315212023_JTask"), name (task display name), and booking_url (URL for time tracking). Use this to discover available tasks before booking time entries.',
                []
            ),
            new ToolDefinition(
                'projektron_get_worklog',
                'Get individual time entries (worklog) for the week containing the specified date. Returns a flat list of individual booking entries, each with: effort_oid, date, task_oid, task_name, project_oid, project_name, hours, minutes, total_minutes, description (booking comment), and status. Multiple entries on the same day/task are returned separately with their own descriptions.',
                [
                    [
                        'name' => 'day',
                        'type' => 'integer',
                        'description' => 'Day of month (1-31)',
                        'required' => true,
                    ],
                    [
                        'name' => 'month',
                        'type' => 'integer',
                        'description' => 'Month (1-12)',
                        'required' => true,
                    ],
                    [
                        'name' => 'year',
                        'type' => 'integer',
                        'description' => 'Year (e.g., 2025)',
                        'required' => true,
                    ],
                ]
            ),
            new ToolDefinition(
                'projektron_add_worklog',
                'Book a time entry (worklog) to a specific task. Use projektron_get_all_tasks first to get valid task OIDs. Returns success/error status with confirmation message.',
                [
                    [
                        'name' => 'task_oid',
                        'type' => 'string',
                        'description' => 'Task OID from get_all_tasks (e.g., "1744315212023_JTask")',
                        'required' => true,
                    ],
                    [
                        'name' => 'day',
                        'type' => 'integer',
                        'description' => 'Day of month (1-31)',
                        'required' => true,
                    ],
                    [
                        'name' => 'month',
                        'type' => 'integer',
                        'description' => 'Month (1-12)',
                        'required' => true,
                    ],
                    [
                        'name' => 'year',
                        'type' => 'integer',
                        'description' => 'Year (e.g., 2025)',
                        'required' => true,
                    ],
                    [
                        'name' => 'hours',
                        'type' => 'integer',
                        'description' => 'Hours to book (0-23)',
                        'required' => true,
                    ],
                    [
                        'name' => 'minutes',
                        'type' => 'integer',
                        'description' => 'Minutes to book (0-59)',
                        'required' => true,
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'description' => 'Work description (e.g., "Development", "Meeting", "Code Review")',
                        'required' => true,
                    ],
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'projektron_get_absences',
                'Get all absences (vacation, sickness, other leave) for a specific year. Returns list of absence entries with details like type (vacation/sickness), start_date, end_date, duration_days, status (approved/submitted/rejected/canceled), and summary statistics including total vacation days, used days, and remaining days.',
                [
                    [
                        'name' => 'day',
                        'type' => 'integer',
                        'description' => 'Day of month (1-31) - used as reference date',
                        'required' => true,
                    ],
                    [
                        'name' => 'month',
                        'type' => 'integer',
                        'description' => 'Month (1-12) - used as reference date',
                        'required' => true,
                    ],
                    [
                        'name' => 'year',
                        'type' => 'integer',
                        'description' => 'Year to fetch absences for (e.g., 2025)',
                        'required' => true,
                    ],
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if ($credentials === null) {
            throw new \InvalidArgumentException('Credentials are required for BCS (Projektron) integration');
        }

        return match ($toolName) {
            'projektron_get_all_tasks' => $this->getAllTasks($credentials),
            'projektron_get_worklog' => $this->getWorklog($credentials, $parameters),
            'projektron_add_worklog' => $this->addWorklog($credentials, $parameters),
            'projektron_get_absences' => $this->getAbsences($credentials, $parameters),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        $requiredFields = ['domain', 'username', 'jsessionid'];

        foreach ($requiredFields as $field) {
            if (empty($credentials[$field])) {
                return false;
            }
        }

        return $this->projektronService->testConnection($credentials);
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'domain',
                'url',
                'BCS Domain',
                'https://projektron.valantic.com',
                true,
                'Your Projektron BCS instance URL (e.g., https://projektron.company.com)'
            ),
            new CredentialField(
                'username',
                'text',
                'Username',
                'your.name@company.com',
                true,
                'Your Projektron BCS username or email address'
            ),
            new CredentialField(
                'jsessionid',
                'password',
                'JSESSIONID',
                null,
                true,
                'Open Browser DevTools (F12) → Application → Cookies → Find JSESSIONID value'
            ),
            new CredentialField(
                'csrf_token',
                'password',
                'CSRF Token',
                null,
                true,
                'Open Browser DevTools (F12) → Application → Cookies → Find CSRF_Token value'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/projektron_full.xml.twig', [
            'integration_id' => $config?->getId() ?? 'ID',
            'tool_count' => count($this->getTools()),
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
        return '/images/logos/Projektron_Logo.png';
    }

    private function getAllTasks(array $credentials): array
    {
        $tasks = $this->projektronService->getAllTasks($credentials);

        return [
            'success' => true,
            'count' => count($tasks),
            'tasks' => $tasks
        ];
    }

    /**
     * Get worklog (time entries) for a specific date
     *
     * @param array $credentials Projektron credentials
     * @param array $parameters Tool parameters (day, month, year)
     * @return array Worklog data with success flag
     */
    private function getWorklog(array $credentials, array $parameters): array
    {
        $day = (int) ($parameters['day'] ?? 0);
        $month = (int) ($parameters['month'] ?? 0);
        $year = (int) ($parameters['year'] ?? 0);

        if ($day < 1 || $day > 31) {
            throw new \InvalidArgumentException('Invalid day: must be between 1 and 31');
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Invalid month: must be between 1 and 12');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Invalid year: must be between 2000 and 2100');
        }

        $worklog = $this->projektronService->getWorklog($credentials, $day, $month, $year);

        return [
            'success' => true,
            'date' => $worklog['date'],
            'week_dates' => $worklog['week_dates'],
            'total_week_hours' => $worklog['total_week_hours'],
            'count' => $worklog['count'],
            'entries' => $worklog['entries'],
        ];
    }

    /**
     * Add a worklog entry to a Projektron task
     *
     * @param array $credentials Projektron credentials
     * @param array $parameters Tool parameters (task_oid, day, month, year, hours, minutes, description)
     * @return array Result with success flag and message
     */
    private function addWorklog(array $credentials, array $parameters): array
    {
        $taskOid = $parameters['task_oid'] ?? '';
        $day = (int) ($parameters['day'] ?? 0);
        $month = (int) ($parameters['month'] ?? 0);
        $year = (int) ($parameters['year'] ?? 0);
        $hours = (int) ($parameters['hours'] ?? 0);
        $minutes = (int) ($parameters['minutes'] ?? 0);
        $description = trim($parameters['description'] ?? '');

        // Validate task OID
        if (empty($taskOid)) {
            throw new \InvalidArgumentException('task_oid is required');
        }
        if (strpos($taskOid, '_JTask') === false) {
            throw new \InvalidArgumentException(
                'Invalid task_oid format. Expected format like "1744315212023_JTask". ' .
                'Use projektron_get_all_tasks to get valid task OIDs.'
            );
        }

        // Validate date parameters
        if ($day < 1 || $day > 31) {
            throw new \InvalidArgumentException('Invalid day: must be between 1 and 31');
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Invalid month: must be between 1 and 12');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Invalid year: must be between 2000 and 2100');
        }

        // Validate time parameters
        if ($hours < 0 || $hours > 23) {
            throw new \InvalidArgumentException('Invalid hours: must be between 0 and 23');
        }
        if ($minutes < 0 || $minutes > 59) {
            throw new \InvalidArgumentException('Invalid minutes: must be between 0 and 59');
        }
        if ($hours === 0 && $minutes === 0) {
            throw new \InvalidArgumentException('Cannot book zero time. Hours and/or minutes must be greater than 0.');
        }

        // Validate description
        if (empty($description)) {
            throw new \InvalidArgumentException('description is required and cannot be empty');
        }

        return $this->projektronService->addWorklog(
            $credentials,
            $taskOid,
            $day,
            $month,
            $year,
            $hours,
            $minutes,
            $description
        );
    }

    /**
     * Get absences (vacation, sickness, other leave) for a specific year
     *
     * @param array $credentials Projektron credentials
     * @param array $parameters Tool parameters (day, month, year)
     * @return array Absences data with success flag
     */
    private function getAbsences(array $credentials, array $parameters): array
    {
        $day = (int) ($parameters['day'] ?? 0);
        $month = (int) ($parameters['month'] ?? 0);
        $year = (int) ($parameters['year'] ?? 0);

        if ($day < 1 || $day > 31) {
            throw new \InvalidArgumentException('Invalid day: must be between 1 and 31');
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Invalid month: must be between 1 and 12');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Invalid year: must be between 2000 and 2100');
        }

        $absences = $this->projektronService->getAbsences($credentials, $day, $month, $year);

        return [
            'success' => true,
            'date' => $absences['date'],
            'count' => $absences['count'],
            'absences' => $absences['absences'],
            'summary' => $absences['summary'],
        ];
    }
}
