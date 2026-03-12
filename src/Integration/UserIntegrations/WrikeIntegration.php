<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\CredentialField;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolCategory;
use App\Integration\ToolDefinition;
use App\Service\Integration\WrikeService;
use Twig\Environment;

class WrikeIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private WrikeService $wrikeService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'wrike';
    }

    public function getName(): string
    {
        return 'Wrike Project Management';
    }

    public function getTools(): array
    {
        return [
            // ========================================
            // TASK MANAGEMENT TOOLS (6)
            // ========================================
            new ToolDefinition(
                'wrike_search_tasks',
                'Search for tasks in Wrike. Filter by title, status, or folder. Returns tasks with title, status, dates, and assignees. Use this to find tasks or get an overview of work items.',
                [
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter tasks by title (contains match)'
                    ],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by status: Active, Completed, Deferred, or Cancelled'
                    ],
                    [
                        'name' => 'folderId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by folder/project ID'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 100, max: 1000)'
                    ]
                ]
            ),
            new ToolDefinition(
                'wrike_get_task',
                'Get detailed information about a specific Wrike task by ID. Returns full task details including title, description, status, dates, assignees, and parent folders.',
                [
                    [
                        'name' => 'taskId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike task ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'wrike_create_task',
                'Create a new task in Wrike. Requires a folder/project ID and title. Optionally set description, status, assignees, and dates.',
                [
                    [
                        'name' => 'folderId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Folder or project ID to create the task in'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Task title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Task description (supports HTML)'
                    ],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Task status: Active (default), Completed, Deferred, or Cancelled'
                    ],
                    [
                        'name' => 'assignees',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Array of user IDs to assign (use wrike_list_contacts to get IDs)'
                    ],
                    [
                        'name' => 'startDate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Start date in YYYY-MM-DD format'
                    ],
                    [
                        'name' => 'dueDate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Due date in YYYY-MM-DD format'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'wrike_update_task',
                'Update an existing Wrike task. Only provide the fields you want to change. Use this to modify title, description, status, dates, or assignees.',
                [
                    [
                        'name' => 'taskId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike task ID to update'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New task title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New task description'
                    ],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New status: Active, Completed, Deferred, or Cancelled'
                    ],
                    [
                        'name' => 'assignees',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'New array of assignee user IDs (replaces existing)'
                    ],
                    [
                        'name' => 'startDate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New start date (YYYY-MM-DD)'
                    ],
                    [
                        'name' => 'dueDate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New due date (YYYY-MM-DD)'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'wrike_get_task_comments',
                'Get all comments on a Wrike task. Returns comment text, author, and timestamp.',
                [
                    [
                        'name' => 'taskId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike task ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'wrike_add_comment',
                'Add a comment to a Wrike task. Supports plain text or HTML formatting.',
                [
                    [
                        'name' => 'taskId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike task ID to comment on'
                    ],
                    [
                        'name' => 'text',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Comment text (supports HTML)'
                    ]
                ],
                ToolCategory::WRITE
            ),

            // ========================================
            // FOLDER/PROJECT TOOLS (3)
            // ========================================
            new ToolDefinition(
                'wrike_list_folders',
                'List all folders and projects in Wrike. Returns folder hierarchy with IDs, titles, and parent relationships. Use this to find folder IDs for creating tasks.',
                []
            ),
            new ToolDefinition(
                'wrike_get_folder',
                'Get detailed information about a specific Wrike folder or project by ID. Returns folder details including title, description, and metadata.',
                [
                    [
                        'name' => 'folderId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike folder or project ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'wrike_get_folder_tasks',
                'Get all tasks within a specific Wrike folder or project. Returns tasks with their details.',
                [
                    [
                        'name' => 'folderId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike folder or project ID'
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 100, max: 1000)'
                    ]
                ]
            ),

            // ========================================
            // USER/CONTACT TOOLS (2)
            // ========================================
            new ToolDefinition(
                'wrike_list_contacts',
                'List all users (contacts) in the Wrike workspace. Returns user IDs, names, and emails. Use this to find user IDs for task assignment.',
                []
            ),
            new ToolDefinition(
                'wrike_get_contact',
                'Get detailed information about a specific Wrike user by ID. Returns name, email, and profile details.',
                [
                    [
                        'name' => 'contactId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike contact/user ID'
                    ]
                ]
            ),

            // ========================================
            // TIME TRACKING TOOLS (2)
            // ========================================
            new ToolDefinition(
                'wrike_log_time',
                'Log time spent on a Wrike task. Record hours and minutes with optional comment.',
                [
                    [
                        'name' => 'taskId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike task ID to log time to'
                    ],
                    [
                        'name' => 'hours',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Number of hours to log'
                    ],
                    [
                        'name' => 'minutes',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of additional minutes (0-59, default: 0)'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Description of work done'
                    ],
                    [
                        'name' => 'trackedDate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Date to log time for (YYYY-MM-DD, defaults to today)'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'wrike_get_timelogs',
                'Get time tracking entries for a Wrike task. Returns all timelogs with hours, dates, and comments.',
                [
                    [
                        'name' => 'taskId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Wrike task ID'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Wrike integration requires credentials');
        }

        return match ($toolName) {
            // Task Management
            'wrike_search_tasks' => $this->wrikeService->searchTasks(
                $credentials,
                $parameters['title'] ?? null,
                $parameters['status'] ?? null,
                $parameters['folderId'] ?? null,
                (int)($parameters['limit'] ?? 100)
            ),
            'wrike_get_task' => $this->wrikeService->getTask(
                $credentials,
                $parameters['taskId']
            ),
            'wrike_create_task' => $this->wrikeService->createTask(
                $credentials,
                $parameters['folderId'],
                $parameters['title'],
                $parameters['description'] ?? null,
                $parameters['status'] ?? null,
                $parameters['assignees'] ?? [],
                $parameters['startDate'] ?? null,
                $parameters['dueDate'] ?? null
            ),
            'wrike_update_task' => $this->wrikeService->updateTask(
                $credentials,
                $parameters['taskId'],
                $this->buildTaskUpdateArray($parameters)
            ),
            'wrike_get_task_comments' => $this->wrikeService->getTaskComments(
                $credentials,
                $parameters['taskId']
            ),
            'wrike_add_comment' => $this->wrikeService->addComment(
                $credentials,
                $parameters['taskId'],
                $parameters['text']
            ),

            // Folder/Project Management
            'wrike_list_folders' => $this->wrikeService->listFolders($credentials),
            'wrike_get_folder' => $this->wrikeService->getFolder(
                $credentials,
                $parameters['folderId']
            ),
            'wrike_get_folder_tasks' => $this->wrikeService->getFolderTasks(
                $credentials,
                $parameters['folderId'],
                (int)($parameters['limit'] ?? 100)
            ),

            // User/Contact Management
            'wrike_list_contacts' => $this->wrikeService->listContacts($credentials),
            'wrike_get_contact' => $this->wrikeService->getContact(
                $credentials,
                $parameters['contactId']
            ),

            // Time Tracking
            'wrike_log_time' => $this->wrikeService->logTime(
                $credentials,
                $parameters['taskId'],
                (int)$parameters['hours'],
                (int)($parameters['minutes'] ?? 0),
                $parameters['comment'] ?? null,
                $parameters['trackedDate'] ?? null
            ),
            'wrike_get_timelogs' => $this->wrikeService->getTimelogs(
                $credentials,
                $parameters['taskId']
            ),

            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    /**
     * Build task update array from parameters
     *
     * @param array $parameters Input parameters
     * @return array Update array for Wrike API
     */
    private function buildTaskUpdateArray(array $parameters): array
    {
        $updates = [];
        $updateFields = ['title', 'description', 'status', 'assignees', 'startDate', 'dueDate'];

        foreach ($updateFields as $field) {
            if (isset($parameters[$field]) && $parameters[$field] !== '') {
                $updates[$field] = $parameters[$field];
            }
        }

        return $updates;
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        // Check if OAuth tokens are present
        if (empty($credentials['access_token'])) {
            return false;
        }

        // Test connection with Wrike API
        try {
            $result = $this->wrikeService->testConnectionDetailed($credentials);
            return $result['success'];
        } catch (\Exception) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        // Wrike uses OAuth2, so we return an oauth field type
        return [
            new CredentialField(
                'oauth',
                'oauth',
                'Wrike OAuth',
                null,
                true,
                'Connect to Wrike using OAuth2. Click the button to authorize access to your Wrike workspace.'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/wrike_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }

    public function isExperimental(): bool
    {
        return false;
    }

    public function getSetupInstructions(): ?string
    {
        return null;
    }

    public function getLogoPath(): string
    {
        return '/images/logos/wrike-icon.svg';
    }
}
