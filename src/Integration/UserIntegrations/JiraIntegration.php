<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\JiraService;
use Twig\Environment;

class JiraIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private JiraService $jiraService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'jira';
    }

    public function getName(): string
    {
        return 'Jira';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'jira_search',
                'Search for Jira issues using JQL (Jira Query Language). Returns: Object with startAt, maxResults, total, and issues array. Each issue is a flat object with: key, summary, status, statusCategory, priority, issueType, projectKey, assignee (name), assigneeId, reporter, created (YYYY-MM-DD), updated (YYYY-MM-DD), dueDate, labels, parentKey, sprint, sprintId. Use additionalFields to include custom fields in _rawFields.',
                [
                    [
                        'name' => 'jql',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'JQL query string'
                    ],
                    [
                        'name' => 'maxResults',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50)'
                    ],
                    [
                        'name' => 'additionalFields',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Custom field IDs to include in raw format (e.g., ["customfield_10030", "customfield_10031"]). These are returned unprocessed in _rawFields alongside the standard flat fields.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_issue',
                'Get detailed information about a specific Jira issue. Returns: Flat object with: key, summary, status, statusCategory, priority, issueType, projectKey, assignee, assigneeId, assigneeEmail, reporter, created (YYYY-MM-DD), updated (YYYY-MM-DD), dueDate, labels, description (plain text, max 5000 chars), components, fixVersions, resolution, subtasks (array of {key, summary, status}), linkedIssues (array of {type, key, summary}), comments (last 10 as {author, created, body}), attachments (array of {filename, size, mimeType}), webUrl. Use additionalFields to include custom fields in _rawFields.',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'additionalFields',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Custom field IDs to include in raw format (e.g., ["customfield_10030", "customfield_10031"]). These are returned unprocessed in _rawFields alongside the standard flat fields.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_board',
                'Get board details including type (scrum/kanban), name, and project information. Use this to detect board type before using sprint-specific tools. Returns: Object with id, self, name, type (scrum/kanban), and location (containing projectId, projectKey, projectName, displayName, projectTypeKey)',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Board ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_sprints_from_board',
                'Get all sprints from a Scrum board (Scrum boards only - does NOT work with Kanban boards). Use jira_get_board first to verify board type. For Kanban boards, use jira_get_kanban_issues instead. Returns: Object with maxResults, startAt, total, isLast, and values array. Each sprint contains: id, self, state (future/active/closed), name, startDate, endDate, completeDate (for closed sprints), originBoardId',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Board ID (must be a Scrum board)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_sprint_issues',
                'Get issues in a specific sprint with optional filtering. Use assignee parameter with accountId from jira_get_myself to filter to your assigned issues (e.g., for daily standup). Use jql for advanced filtering like status changes or date ranges. Returns: Object with expand, startAt, maxResults, total, and issues array. Each issue is a flat object with: key, summary, status, statusCategory, priority, issueType, projectKey, assignee, assigneeId, reporter, created (YYYY-MM-DD), updated (YYYY-MM-DD), dueDate, labels, parentKey, sprint, sprintId. Use additionalFields to include custom fields in _rawFields.',
                [
                    [
                        'name' => 'sprintId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Sprint ID'
                    ],
                    [
                        'name' => 'maxResults',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 100)'
                    ],
                    [
                        'name' => 'assignee',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by assignee accountId (from jira_get_myself). Use this to get only your assigned issues in the sprint.'
                    ],
                    [
                        'name' => 'jql',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Additional JQL filter to apply. Combined with assignee filter using AND. Examples: "status = \'In Progress\'" or "status changed to Done AFTER -24h" for recent completions.'
                    ],
                    [
                        'name' => 'additionalFields',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Custom field IDs to include in raw format (e.g., ["customfield_10030", "customfield_10031"]). These are returned unprocessed in _rawFields alongside the standard flat fields.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_add_comment',
                'Add a comment to a Jira issue. Returns: Comment object with self, id, author (accountId, displayName, emailAddress, active), body (document format), created, updated, updateAuthor, and visibility settings',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'The comment text to add to the issue'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_available_transitions',
                'Get available status transitions for a Jira issue. Use this to find out which status changes are possible for an issue based on the workflow. Returns: Object with expand and transitions array. Each transition contains: id, name, description, hasScreen, isAvailable, isConditional, isGlobal, isInitial, to (status object with id, name, description, iconUrl, statusCategory), and fields (required/optional fields for the transition)',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_transition_issue',
                'Change the status of a Jira issue by executing a workflow transition. Always call jira_get_available_transitions first to get valid transition IDs. Note: Some transitions require additional fields (e.g., Resolution when closing). If you get a "required field" error, check the transition screen configuration. Returns: HTTP 204 on success, or error details',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'transitionId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Transition ID from jira_get_available_transitions'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional comment to add when transitioning the issue'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_transition_issue_to_status',
                'Intelligently transition a Jira issue to a target status by automatically navigating through the workflow. Use this when the user asks to "set status to Done" or similar - the system will automatically find and execute the required workflow path. Returns: Success message with the new status name and transition details, or error message if the target status cannot be reached',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'targetStatusName',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Target status name (e.g., "Done", "In Progress", "Closed")'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional comment to add during the transition'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_board_issues',
                'Universal tool to get issues from any board type with optional filtering. Use assignee parameter with accountId from jira_get_myself to filter to your assigned issues (e.g., for daily standup). Automatically detects board type: for Scrum boards, returns issues from the active sprint; for Kanban boards, returns all board issues. Returns: Object with expand, startAt, maxResults, total, boardType, and issues array. Each issue is a flat object with: key, summary, status, statusCategory, priority, issueType, projectKey, assignee, assigneeId, reporter, created (YYYY-MM-DD), updated (YYYY-MM-DD), dueDate, labels, parentKey, sprint, sprintId. Use additionalFields to include custom fields in _rawFields.',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Board ID (works with both Scrum and Kanban boards)'
                    ],
                    [
                        'name' => 'maxResults',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50)'
                    ],
                    [
                        'name' => 'assignee',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by assignee accountId (from jira_get_myself). Use this to get only your assigned issues.'
                    ],
                    [
                        'name' => 'jql',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Additional JQL filter to apply. Combined with assignee filter using AND. Examples: "status = \'In Progress\'" or "status changed to Done AFTER -24h".'
                    ],
                    [
                        'name' => 'additionalFields',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Custom field IDs to include in raw format (e.g., ["customfield_10030", "customfield_10031"]). These are returned unprocessed in _rawFields alongside the standard flat fields.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_kanban_issues',
                'Get issues from a Kanban board with optional filtering. Use assignee parameter with accountId from jira_get_myself to filter to your assigned issues. Specifically designed for Kanban boards which do not have sprints. Returns: Object with expand, startAt, maxResults, total, and issues array. Each issue is a flat object with: key, summary, status, statusCategory, priority, issueType, projectKey, assignee, assigneeId, reporter, created (YYYY-MM-DD), updated (YYYY-MM-DD), dueDate, labels, parentKey. Use additionalFields to include custom fields in _rawFields.',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Kanban Board ID'
                    ],
                    [
                        'name' => 'maxResults',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of results (default: 50)'
                    ],
                    [
                        'name' => 'assignee',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by assignee accountId (from jira_get_myself). Use this to get only your assigned issues.'
                    ],
                    [
                        'name' => 'jql',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Additional JQL filter to apply. Combined with assignee filter using AND. Examples: "status = \'In Progress\'" or "status changed to Done AFTER -24h".'
                    ],
                    [
                        'name' => 'additionalFields',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Custom field IDs to include in raw format (e.g., ["customfield_10030", "customfield_10031"]). These are returned unprocessed in _rawFields alongside the standard flat fields.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_edit_metadata',
                'Get all editable fields for an existing issue. Use this before updating an issue to know what fields can be modified and their allowed values. Returns: Object with fields property containing field definitions, including field types, required status, allowed values, and schema information for each editable field',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_delete_issue',
                'Delete a Jira issue permanently. WARNING: This action cannot be undone. The issue and all its data will be permanently removed. Use with caution. Returns: Success confirmation message',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key to delete (e.g., PROJ-123)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_issue_link_types',
                'Get all available issue link types for the Jira instance. Link types define relationships between issues (e.g., "blocks"/"is blocked by", "relates to", "duplicates"/"is duplicated by"). Returns: Object with issueLinkTypes array containing id, name, inward (description from target issue perspective), outward (description from source issue perspective), and self URL for each link type',
                [
                ]
            ),
            new ToolDefinition(
                'jira_link_issues',
                'Link two Jira issues together with a specific relationship type. For "Blocks" link type: outwardIssue blocks inwardIssue (e.g., if PROJ-123 blocks PROJ-456, pass outwardIssue=PROJ-123, inwardIssue=PROJ-456). Returns: Success confirmation message',
                [
                    [
                        'name' => 'inwardIssue',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Inward issue key - receives the action (e.g., "is blocked by", "is duplicated by")'
                    ],
                    [
                        'name' => 'outwardIssue',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Outward issue key - performs the action (e.g., "blocks", "duplicates")'
                    ],
                    [
                        'name' => 'linkTypeId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Link type ID from jira_get_issue_link_types (e.g., "10000")'
                    ],
                    [
                        'name' => 'comment',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional comment to add with the link'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_project_metadata',
                'Get project information including available issue types, components, versions, and project configuration. Use this before creating issues to discover what issue types and components are available. Returns: Object with id, key, name, description, projectTypeKey, issueTypes array (with id, name, description, subtask flag), components array (with id, name, description), and versions array (with id, name, released status)',
                [
                    [
                        'name' => 'projectKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project key (e.g., PROJ, TEST)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_create_field_metadata',
                'Get detailed field metadata for creating a specific issue type. Use this to discover required/optional fields including custom fields. Each field has: fieldId, name, required (true/false), schema (type info), allowedValues (for select fields). Check "required: true" to identify mandatory fields.',
                [
                    [
                        'name' => 'projectKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project key (e.g., PROJ, TEST)'
                    ],
                    [
                        'name' => 'issueTypeId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue type ID from jira_get_project_metadata'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_create_issue',
                'Create a new Jira issue with standard and custom fields. Use jira_get_project_metadata first to get project info and issue types, then jira_get_create_field_metadata to discover required fields. Returns: Object with id, key (issue key like PROJ-123), and self (URL)',
                [
                    [
                        'name' => 'projectKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project key (e.g., PROJ, TEST)'
                    ],
                    [
                        'name' => 'issueTypeId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue type ID from jira_get_project_metadata'
                    ],
                    [
                        'name' => 'summary',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue summary/title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Issue description (plain text, will be converted to Jira format)'
                    ],
                    [
                        'name' => 'priorityId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Priority ID from jira_get_priorities'
                    ],
                    [
                        'name' => 'assigneeId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Assignee account ID'
                    ],
                    [
                        'name' => 'labels',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Array of label strings'
                    ],
                    [
                        'name' => 'componentIds',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Array of component IDs from project metadata'
                    ],
                    [
                        'name' => 'dueDate',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Due date in YYYY-MM-DD format (e.g., 2025-12-25). Must be exact format.'
                    ],
                    [
                        'name' => 'reporterId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Reporter account ID (from jira_get_myself or jira_search_users). Get your own accountId using jira_get_myself first.'
                    ],
                    [
                        'name' => 'customFields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'Object with custom field IDs as keys (e.g., {"customfield_10000": "value"}). Plain string values are auto-converted to proper Jira format (ADF for textarea fields, {"id": value} for option fields).'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_priorities',
                'Get all available priorities in Jira. Use this to find priority IDs for creating or updating issues. Returns: Array of priority objects, each with id, self, iconUrl, name, description, statusColor, and isDefault flag',
                [
                    // No parameters required
                ]
            ),
            new ToolDefinition(
                'jira_update_issue',
                'Update fields on an existing Jira issue. Can update summary, description, priority, assignee, labels, components, and custom fields.',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'summary',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New summary (optional)'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New description in plain text (optional)'
                    ],
                    [
                        'name' => 'priorityId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New priority ID (optional)'
                    ],
                    [
                        'name' => 'assigneeId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New assignee account ID (optional, null to unassign)'
                    ],
                    [
                        'name' => 'labels',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'New labels array - replaces existing labels (optional)'
                    ],
                    [
                        'name' => 'componentIds',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'New component IDs array - replaces existing components (optional)'
                    ],
                    [
                        'name' => 'customFields',
                        'type' => 'object',
                        'required' => false,
                        'description' => 'Custom field updates as key-value pairs (optional). Plain string values are auto-converted to proper Jira format.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_bulk_create_issues',
                'Create multiple Jira issues at once (up to 50). Each issue requires projectKey, issueTypeId, and summary. Returns both successful creations and any errors.',
                [
                    [
                        'name' => 'issues',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'Array of issue data objects. Each object has same structure as jira_create_issue parameters.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_assign_issue',
                'Assign a Jira issue to a user or unassign it. Quick shortcut for changing assignee without full update.',
                [
                    [
                        'name' => 'issueKey',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue key (e.g., PROJ-123)'
                    ],
                    [
                        'name' => 'accountId',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'User account ID to assign to. Use null or omit to unassign.'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_search_users',
                'Search for Jira users by name or email. Returns array of user objects with accountId (use for assignee/reporter), displayName, emailAddress, and active status. Always use accountId field for issue assignments.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query (name or email)'
                    ]
                ]
            ),
            new ToolDefinition(
                'jira_get_myself',
                'Get the current authenticated user information. IMPORTANT: Call this first when creating issues to get your accountId for the reporter field. Returns: User object with accountId (unique identifier to use as reporter/assignee), displayName, emailAddress, active status, avatarUrls, and timeZone',
                []
            ),
            new ToolDefinition(
                'jira_get_fields',
                'Get all available fields in the Jira instance (system and custom). Use this to discover field IDs that can be passed to the additionalFields parameter of search/issue tools. Returns: Array of fields with id (use this in additionalFields), name, custom (true for custom fields), type (field data type), itemType (for array fields), customType (custom field type identifier)',
                []
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Jira integration requires credentials');
        }

        return match ($toolName) {
            'jira_search' => $this->jiraService->search(
                $credentials,
                $parameters['jql'],
                $parameters['maxResults'] ?? 50,
                $parameters['additionalFields'] ?? null
            ),
            'jira_get_issue' => $this->jiraService->getIssue(
                $credentials,
                $parameters['issueKey'],
                $parameters['additionalFields'] ?? null
            ),
            'jira_get_board' => $this->jiraService->getBoard(
                $credentials,
                $parameters['boardId']
            ),
            'jira_get_sprints_from_board' => $this->jiraService->getSprintsFromBoard(
                $credentials,
                $parameters['boardId']
            ),
            'jira_get_sprint_issues' => $this->jiraService->getSprintIssues(
                $credentials,
                $parameters['sprintId'],
                $parameters['maxResults'] ?? 100,
                $parameters['assignee'] ?? null,
                $parameters['jql'] ?? null,
                $parameters['additionalFields'] ?? null
            ),
            'jira_add_comment' => $this->jiraService->addComment(
                $credentials,
                $parameters['issueKey'],
                $parameters['comment']
            ),
            'jira_get_available_transitions' => $this->jiraService->getAvailableTransitions(
                $credentials,
                $parameters['issueKey']
            ),
            'jira_transition_issue' => $this->jiraService->transitionIssue(
                $credentials,
                $parameters['issueKey'],
                $parameters['transitionId'],
                $parameters['comment'] ?? null
            ),
            'jira_transition_issue_to_status' => $this->jiraService->transitionIssueToStatus(
                $credentials,
                $parameters['issueKey'],
                $parameters['targetStatusName'],
                $parameters['comment'] ?? null
            ),
            'jira_get_board_issues' => $this->jiraService->getBoardIssues(
                $credentials,
                $parameters['boardId'],
                $parameters['maxResults'] ?? 50,
                $parameters['assignee'] ?? null,
                $parameters['jql'] ?? null,
                $parameters['additionalFields'] ?? null
            ),
            'jira_get_kanban_issues' => $this->jiraService->getKanbanIssues(
                $credentials,
                $parameters['boardId'],
                $parameters['maxResults'] ?? 50,
                $parameters['assignee'] ?? null,
                $parameters['jql'] ?? null,
                $parameters['additionalFields'] ?? null
            ),
            'jira_get_edit_metadata' => $this->jiraService->getEditMetadata(
                $credentials,
                $parameters['issueKey']
            ),
            'jira_delete_issue' => $this->jiraService->deleteIssue(
                $credentials,
                $parameters['issueKey']
            ),
            'jira_get_issue_link_types' => $this->jiraService->getIssueLinkTypes(
                $credentials
            ),
            'jira_link_issues' => $this->jiraService->linkIssues(
                $credentials,
                $parameters['inwardIssue'],
                $parameters['outwardIssue'],
                $parameters['linkTypeId'],
                $parameters['comment'] ?? null
            ),
            'jira_get_project_metadata' => $this->jiraService->getProjectMetadata(
                $credentials,
                $parameters['projectKey']
            ),
            'jira_get_create_field_metadata' => $this->jiraService->getCreateFieldMetadata(
                $credentials,
                $parameters['projectKey'],
                $parameters['issueTypeId']
            ),
            'jira_create_issue' => $this->jiraService->createIssue(
                $credentials,
                $parameters
            ),
            'jira_get_priorities' => $this->jiraService->getPriorities(
                $credentials
            ),
            'jira_update_issue' => $this->jiraService->updateIssue(
                $credentials,
                $parameters['issueKey'],
                $parameters
            ),
            'jira_bulk_create_issues' => $this->jiraService->bulkCreateIssues(
                $credentials,
                $parameters['issues']
            ),
            'jira_assign_issue' => $this->jiraService->assignIssue(
                $credentials,
                $parameters['issueKey'],
                $parameters['accountId'] ?? null
            ),
            'jira_search_users' => $this->jiraService->searchUsers(
                $credentials,
                $parameters['query']
            ),
            'jira_get_myself' => $this->jiraService->getMyself($credentials),
            'jira_get_fields' => $this->jiraService->getFields($credentials),
            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        // First check if all required fields are present and not empty
        if (empty($credentials['url']) || empty($credentials['username']) || empty($credentials['api_token'])) {
            return false;
        }

        // Use JiraService to validate credentials
        try {
            return $this->jiraService->testConnection($credentials);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'url',
                'url',
                'Jira URL',
                'https://your-domain.atlassian.net',
                true,
                'Your Jira instance URL'
            ),
            new CredentialField(
                'username',
                'email',
                'Email',
                'your-email@example.com',
                true,
                'Email address associated with your Jira account'
            ),
            new CredentialField(
                'api_token',
                'password',
                'API Token',
                null,
                true,
                'Your Jira API token (not your password). <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" rel="noopener">Create API token</a>'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/jira_full.xml.twig', [
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
        return '/images/logos/jira-icon.svg';
    }
}
