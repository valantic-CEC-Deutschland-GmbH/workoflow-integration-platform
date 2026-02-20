<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\GitLabService;
use Twig\Environment;

class GitLabIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private GitLabService $gitLabService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'gitlab';
    }

    public function getName(): string
    {
        return 'GitLab';
    }

    public function getTools(): array
    {
        return [
            // Repository & Metadata
            new ToolDefinition(
                'gitlab_get_project',
                'Get detailed information about a GitLab project. Returns: Object with id, name, path, path_with_namespace, description, web_url, default_branch, visibility, created_at, namespace, owner, and other project metadata',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path (e.g., "42" or "namespace/project")'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_projects',
                'List GitLab projects accessible to the authenticated user. Returns: Array of projects with id, name, path, path_with_namespace, description, web_url, default_branch, visibility, created_at, last_activity_at, namespace',
                [
                    [
                        'name' => 'membership',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Limit to projects the user is a member of (default: all accessible projects)'
                    ],
                    [
                        'name' => 'per_page',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results per page (default: 20)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_file_content',
                'Get the content of a file from the repository. Returns: Object with file_name, file_path, size, encoding, content (base64 or plain text), content_sha256, ref, blob_id, commit_id, last_commit_id',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'file_path',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Path to the file in the repository'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Branch, tag, or commit SHA (default: repository default branch)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_repository_tree',
                'List files and directories in the repository. Returns: Array of tree items with id, name, type (blob/tree), path, mode',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'path',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Path inside repository (default: root)'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Branch, tag, or commit SHA (default: repository default branch)'
                    ],
                    [
                        'name' => 'recursive',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Get tree recursively (default: false)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_compare_branches',
                'Compare two branches, tags, or commits to see differences. Returns: Object with commit (latest commit), commits array (all commits in diff), diffs array (file changes with old_path, new_path, diff, new_file, renamed_file, deleted_file), compare_timeout (boolean), compare_same_ref (boolean)',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'from',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Source branch, tag, or commit SHA'
                    ],
                    [
                        'name' => 'to',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Target branch, tag, or commit SHA'
                    ],
                    [
                        'name' => 'straight',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Use straight comparison (true) or merge base comparison (false, default)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_create_file',
                'Create a new file in the repository. Returns: Object with file_path, branch, commit_id',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'file_path',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Full path to the file in repository'
                    ],
                    [
                        'name' => 'branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch name to create file in'
                    ],
                    [
                        'name' => 'content',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'File content'
                    ],
                    [
                        'name' => 'commit_message',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Commit message'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_update_file',
                'Update an existing file in the repository. Returns: Object with file_path, branch, commit_id',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'file_path',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Full path to the file in repository'
                    ],
                    [
                        'name' => 'branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch name to update file in'
                    ],
                    [
                        'name' => 'content',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'New file content'
                    ],
                    [
                        'name' => 'commit_message',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Commit message'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_delete_file',
                'Delete a file from the repository. Returns: Empty response on success',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'file_path',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Full path to the file in repository'
                    ],
                    [
                        'name' => 'branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch name to delete file from'
                    ],
                    [
                        'name' => 'commit_message',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Commit message'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_protect_branch',
                'Protect a branch with access control rules. Returns: Object with name, push_access_levels, merge_access_levels, allow_force_push, code_owner_approval_required',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch name to protect'
                    ],
                    [
                        'name' => 'push_access_level',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Access level for push (0=no access, 30=developer, 40=maintainer, 60=admin)'
                    ],
                    [
                        'name' => 'merge_access_level',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Access level for merge (0=no access, 30=developer, 40=maintainer, 60=admin)'
                    ]
                ]
            ),

            // Tags, Branches & Commits
            new ToolDefinition(
                'gitlab_list_tags',
                'List all tags in the repository. Returns: Array of tags with name, message, target (commit SHA), commit (id, short_id, title, author_name, created_at), release, protected',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_create_tag',
                'Create a new tag in the repository. Returns: Object with name, message, target (commit SHA), commit (id, short_id, title, author_name, created_at), release, protected',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'tag_name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Tag name (e.g., "v1.0.0", "release-2024-01")'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch name, commit SHA, or another tag to create tag from'
                    ],
                    [
                        'name' => 'message',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Tag annotation message'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_delete_tag',
                'Delete a tag from the repository. Returns: Empty response on success',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'tag_name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Tag name to delete'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_branches',
                'List all branches in the repository. Returns: Array of branches with name, commit (id, short_id, title, author_name, created_at), merged, protected, default, can_push, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_create_branch',
                'Create a new branch in the repository. Returns: Object with name, commit (id, short_id, title), merged, protected, default, can_push, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'New branch name'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch name or commit SHA to create branch from'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_delete_branch',
                'Delete a branch from the repository. Returns: Empty response on success',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch name to delete'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_commit',
                'Get detailed information about a specific commit. Returns: Object with id, short_id, title, message, author_name, author_email, authored_date, committer_name, committer_email, committed_date, created_at, parent_ids, web_url, stats (additions, deletions, total)',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'sha',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Commit SHA'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_commits',
                'List commits in the repository. Returns: Array of commits with id, short_id, title, message, author_name, author_email, authored_date, committer_name, created_at, parent_ids, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'ref_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Branch, tag, or commit SHA (default: repository default branch)'
                    ],
                    [
                        'name' => 'per_page',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results per page (default: 20)'
                    ]
                ]
            ),

            // Merge Requests (Read)
            new ToolDefinition(
                'gitlab_search_merge_requests',
                'Search merge requests in a project. Returns: Array of MRs with id, iid, project_id, title, description, state, web_url, author, assignee, source_branch, target_branch, created_at, updated_at, merge_status',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by state: opened, closed, merged, or all (default: all)'
                    ],
                    [
                        'name' => 'scope',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by scope: created_by_me, assigned_to_me, or all (default: all)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request',
                'Get detailed information about a merge request. Returns: Object with id, iid, project_id, title, description, state, web_url, author, assignee, assignees, reviewers, source_branch, target_branch, sha, merge_commit_sha, merge_status, created_at, updated_at, merged_at, closed_at, milestone, labels, pipeline, user_notes_count',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request_changes',
                'Get changes (diff) for a merge request. Returns: MR object with changes array containing old_path, new_path, a_mode, b_mode, diff, new_file, renamed_file, deleted_file for each changed file',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request_discussions',
                'Get all discussions (comments) for a merge request. Returns: Array of discussions with id, individual_note (boolean), notes array (each note has id, body, author, created_at, updated_at, resolved, resolvable)',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request_commits',
                'Get all commits in a merge request. Returns: Array of commits with id, short_id, title, message, author_name, author_email, authored_date, committer_name, committer_email, committed_date, created_at, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),

            // Merge Requests (Write)
            new ToolDefinition(
                'gitlab_create_merge_request',
                'Create a new merge request. Returns: MR object with id, iid, project_id, title, description, state, web_url, author, source_branch, target_branch, created_at, updated_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'source_branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Source branch name'
                    ],
                    [
                        'name' => 'target_branch',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Target branch name'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Merge request title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Merge request description'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_update_merge_request',
                'Update an existing merge request. Returns: Updated MR object with id, iid, title, description, state, web_url, updated_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New description'
                    ],
                    [
                        'name' => 'state_event',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'State event: close or reopen'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_add_merge_request_note',
                'Add a comment to a merge request. Returns: Note object with id, body, author (id, name, username, state, avatar_url, web_url), created_at, updated_at, system, noteable_id, noteable_type',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'body',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Comment text'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_merge_merge_request',
                'Merge a merge request. Returns: MR object with merge status, merged_by, merged_at, and other merge details',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'merge_commit_message',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Custom merge commit message'
                    ],
                    [
                        'name' => 'squash',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Squash commits on merge (default: false)'
                    ],
                    [
                        'name' => 'should_remove_source_branch',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Remove source branch after merge (default: false)'
                    ],
                    [
                        'name' => 'merge_when_pipeline_succeeds',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Merge automatically when pipeline succeeds (default: false)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_approve_merge_request',
                'Approve a merge request. Returns: Object with id, iid, project_id, title, description, approved_by array',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_unapprove_merge_request',
                'Remove approval from a merge request. Returns: Empty response on success',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_merge_request_approvals',
                'Get approval status and configuration for a merge request. Returns: Object with approved (boolean), approved_by array, approvals_required, approvals_left, suggested_approvers',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_merge_request_pipelines',
                'List all pipelines for a merge request. Returns: Array of pipelines with id, sha, ref, status, source, web_url, created_at, updated_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_create_merge_request_pipeline',
                'Trigger a new pipeline for a merge request. Returns: Pipeline object with id, sha, ref, status, source, web_url, created_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_update_merge_request_assignees',
                'Update assignees and reviewers for a merge request. Returns: Updated MR object with assignees and reviewers arrays',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'assignee_ids',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Array of user IDs to assign'
                    ],
                    [
                        'name' => 'reviewer_ids',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Array of user IDs to set as reviewers'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_my_merge_requests',
                'List merge requests across all accessible projects. Defaults to merge requests assigned to you in opened state. Returns: Array of MRs with id, iid, project_id, title, description, state, web_url, author, assignee, source_branch, target_branch, created_at, updated_at, merge_status',
                [
                    [
                        'name' => 'scope',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by scope: assigned_to_me (default), created_by_me, or all'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by state: opened (default), closed, merged, or all'
                    ],
                    [
                        'name' => 'search',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Search in title and description'
                    ],
                    [
                        'name' => 'labels',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated list of label names'
                    ],
                    [
                        'name' => 'order_by',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Order by: created_at, updated_at, or title (default: created_at)'
                    ],
                    [
                        'name' => 'sort',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Sort order: asc or desc (default: desc)'
                    ],
                    [
                        'name' => 'per_page',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results per page (default: 20, max: 100)'
                    ]
                ]
            ),

            // Issues
            new ToolDefinition(
                'gitlab_search_issues',
                'Search issues in a project. Returns: Array of issues with id, iid, project_id, title, description, state, web_url, author, assignee, assignees, labels, created_at, updated_at, closed_at, milestone, user_notes_count',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by state: opened, closed, or all (default: all)'
                    ],
                    [
                        'name' => 'scope',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by scope: created_by_me, assigned_to_me, or all (default: all)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_issue',
                'Get detailed information about an issue. Returns: Object with id, iid, project_id, title, description, state, web_url, author, assignee, assignees, labels, created_at, updated_at, closed_at, closed_by, milestone, user_notes_count, due_date, confidential, issue_type',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'issue_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Issue internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_create_issue',
                'Create a new issue. Returns: Issue object with id, iid, project_id, title, description, state, web_url, author, created_at, updated_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Issue title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Issue description'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_update_issue',
                'Update an existing issue. Returns: Updated issue object with id, iid, title, description, state, web_url, updated_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'issue_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Issue internal ID'
                    ],
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New title'
                    ],
                    [
                        'name' => 'description',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New description'
                    ],
                    [
                        'name' => 'state_event',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'State event: close or reopen'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_add_issue_note',
                'Add a comment to an issue. Returns: Note object with id, body, author (id, name, username, state, avatar_url, web_url), created_at, updated_at, system, noteable_id, noteable_type',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'issue_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Issue internal ID'
                    ],
                    [
                        'name' => 'body',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Comment text'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_issue_notes',
                'List all comments on an issue. Returns: Array of notes with id, body, author (id, name, username, avatar_url), created_at, updated_at, system, noteable_id, noteable_type',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'issue_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Issue internal ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_update_issue_assignees',
                'Update assignees for an issue. Returns: Updated issue object with assignees array',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'issue_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Issue internal ID'
                    ],
                    [
                        'name' => 'assignee_ids',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'Array of user IDs to assign'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_all_issues',
                'List issues across all accessible projects. Returns: Array of issues with id, iid, project_id, title, description, state, web_url, author, assignee, labels, created_at, updated_at',
                [
                    [
                        'name' => 'scope',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by scope: assigned_to_me (default), created_by_me, or all'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by state: opened (default), closed, or all'
                    ],
                    [
                        'name' => 'search',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Search in title and description'
                    ],
                    [
                        'name' => 'per_page',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results per page (default: 20, max: 100)'
                    ]
                ]
            ),

            // Discussion Threads
            new ToolDefinition(
                'gitlab_create_discussion',
                'Create a new discussion thread on a merge request. Returns: Discussion object with id, individual_note, notes array',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'body',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Discussion comment text'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_resolve_discussion',
                'Resolve a discussion thread on a merge request. Returns: Discussion object with resolved status',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'discussion_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Discussion ID'
                    ],
                    [
                        'name' => 'resolved',
                        'type' => 'boolean',
                        'required' => true,
                        'description' => 'True to resolve, false to unresolve'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_add_discussion_note',
                'Add a reply to an existing discussion thread. Returns: Note object with id, body, author, created_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'merge_request_iid',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Merge request internal ID'
                    ],
                    [
                        'name' => 'discussion_id',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Discussion ID'
                    ],
                    [
                        'name' => 'body',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Reply text'
                    ]
                ]
            ),

            // Labels & Milestones
            new ToolDefinition(
                'gitlab_list_labels',
                'List all labels in a project. Returns: Array of labels with id, name, color, description, open_issues_count, closed_issues_count, open_merge_requests_count',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_list_milestones',
                'List all milestones in a project. Returns: Array of milestones with id, iid, title, description, state, due_date, start_date, created_at, updated_at, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'state',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by state: active, closed, or all (default: all)'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_milestone_issues',
                'Get all issues in a milestone. Returns: Array of issues with id, iid, title, description, state, web_url, labels, assignees',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'milestone_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Milestone ID'
                    ]
                ]
            ),

            // Users & Project Members
            new ToolDefinition(
                'gitlab_list_project_members',
                'List all members of a project with their access levels. Returns: Array of members with id, username, name, state, avatar_url, access_level, expires_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_search_users',
                'Search for users by name or username. Returns: Array of users with id, username, name, state, avatar_url, web_url',
                [
                    [
                        'name' => 'search',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query (name or username)'
                    ],
                    [
                        'name' => 'per_page',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of results per page (default: 20)'
                    ]
                ]
            ),

            // Packages
            new ToolDefinition(
                'gitlab_search_packages',
                'Search packages in a project. Returns: Array of packages with id, name, version, package_type, created_at, pipelines (array with id, status, ref, sha, web_url, created_at, user)',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'package_name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by package name'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_package',
                'Get detailed information about a package. Returns: Object with id, name, version, package_type, created_at, project_id, pipelines, _links (web_path, delete_api_path)',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'package_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Package ID'
                    ]
                ]
            ),

            // CI/CD
            new ToolDefinition(
                'gitlab_list_pipelines',
                'List CI/CD pipelines in a project. Returns: Array of pipelines with id, project_id, ref, sha, status, source, web_url, created_at, updated_at, started_at, finished_at, duration, user',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by branch or tag name'
                    ],
                    [
                        'name' => 'status',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter by status: running, pending, success, failed, canceled, skipped, created, manual'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_pipeline',
                'Get detailed information about a pipeline. Returns: Object with id, project_id, ref, sha, status, source, web_url, created_at, updated_at, started_at, finished_at, duration, coverage, user, detailed_status (icon, text, label, group)',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'pipeline_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Pipeline ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_pipeline_jobs',
                'Get all jobs in a pipeline. Returns: Array of jobs with id, status, stage, name, ref, tag, coverage, allow_failure, created_at, started_at, finished_at, erased_at, duration, queued_duration, user, commit, pipeline, failure_reason, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'pipeline_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Pipeline ID'
                    ],
                    [
                        'name' => 'scope',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Filter by job scope: created, pending, running, failed, success, canceled, skipped, manual'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_job_trace',
                'Get the trace log output from a job. Returns last N lines only (configurable via GITLAB_JOB_TRACE_MAX_LINES, default: 500) to optimize for LLM processing. Response includes: trace (string with last N lines of console output, errors, and stack traces), metadata (object with total_lines, returned_lines, truncated boolean)',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'job_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Job ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_get_job',
                'Get detailed information about a specific job. Returns: Object with id, status, stage, name, ref, tag, coverage, allow_failure, created_at, started_at, finished_at, erased_at, duration, queued_duration, user, commit, pipeline, failure_reason, web_url, runner, artifacts_expire_at, artifacts',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'job_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Job ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_retry_pipeline',
                'Retry a failed pipeline. Returns: Pipeline object with id, status, ref, sha, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'pipeline_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Pipeline ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_cancel_pipeline',
                'Cancel a running pipeline. Returns: Pipeline object with id, status, ref, sha, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'pipeline_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Pipeline ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_create_pipeline',
                'Trigger a new pipeline on a branch or tag. Returns: Pipeline object with id, status, ref, sha, web_url, created_at',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'ref',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Branch or tag name to run pipeline on'
                    ],
                    [
                        'name' => 'variables',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Array of variables for the pipeline, each with key and value'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_retry_job',
                'Retry a failed or canceled job. Returns: Job object with id, status, stage, name, ref, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'job_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Job ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_cancel_job',
                'Cancel a running or pending job. Returns: Job object with id, status, stage, name, ref, web_url',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'job_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Job ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'gitlab_download_job_artifacts',
                'Download artifacts from a job. Returns: Object with artifacts_file (base64 encoded), filename, size',
                [
                    [
                        'name' => 'project',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Project ID or URL-encoded path'
                    ],
                    [
                        'name' => 'job_id',
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Job ID'
                    ]
                ]
            ),
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('GitLab integration requires credentials');
        }

        return match ($toolName) {
            // Repository & Metadata
            'gitlab_get_project' => $this->gitLabService->getProject(
                $credentials,
                $parameters['project']
            ),
            'gitlab_list_projects' => $this->gitLabService->listProjects(
                $credentials,
                $parameters['membership'] ?? null,
                $parameters['per_page'] ?? 20
            ),
            'gitlab_get_file_content' => $this->gitLabService->getFileContent(
                $credentials,
                $parameters['project'],
                $parameters['file_path'],
                $parameters['ref'] ?? null
            ),
            'gitlab_list_repository_tree' => $this->gitLabService->listRepositoryTree(
                $credentials,
                $parameters['project'],
                $parameters['path'] ?? null,
                $parameters['ref'] ?? null,
                $parameters['recursive'] ?? false
            ),
            'gitlab_compare_branches' => $this->gitLabService->compareBranches(
                $credentials,
                $parameters['project'],
                $parameters['from'],
                $parameters['to'],
                $parameters['straight'] ?? null
            ),
            'gitlab_create_file' => $this->gitLabService->createFile(
                $credentials,
                $parameters['project'],
                $parameters['file_path'],
                $parameters['branch'],
                $parameters['content'],
                $parameters['commit_message']
            ),
            'gitlab_update_file' => $this->gitLabService->updateFile(
                $credentials,
                $parameters['project'],
                $parameters['file_path'],
                $parameters['branch'],
                $parameters['content'],
                $parameters['commit_message']
            ),
            'gitlab_delete_file' => $this->gitLabService->deleteFile(
                $credentials,
                $parameters['project'],
                $parameters['file_path'],
                $parameters['branch'],
                $parameters['commit_message']
            ),
            'gitlab_protect_branch' => $this->gitLabService->protectBranch(
                $credentials,
                $parameters['project'],
                $parameters['branch'],
                $parameters['push_access_level'] ?? null,
                $parameters['merge_access_level'] ?? null
            ),

            // Tags, Branches & Commits
            'gitlab_list_tags' => $this->gitLabService->listTags(
                $credentials,
                $parameters['project']
            ),
            'gitlab_create_tag' => $this->gitLabService->createTag(
                $credentials,
                $parameters['project'],
                $parameters['tag_name'],
                $parameters['ref'],
                $parameters['message'] ?? null
            ),
            'gitlab_delete_tag' => $this->gitLabService->deleteTag(
                $credentials,
                $parameters['project'],
                $parameters['tag_name']
            ),
            'gitlab_list_branches' => $this->gitLabService->listBranches(
                $credentials,
                $parameters['project']
            ),
            'gitlab_create_branch' => $this->gitLabService->createBranch(
                $credentials,
                $parameters['project'],
                $parameters['branch'],
                $parameters['ref']
            ),
            'gitlab_delete_branch' => $this->gitLabService->deleteBranch(
                $credentials,
                $parameters['project'],
                $parameters['branch']
            ),
            'gitlab_get_commit' => $this->gitLabService->getCommit(
                $credentials,
                $parameters['project'],
                $parameters['sha']
            ),
            'gitlab_list_commits' => $this->gitLabService->listCommits(
                $credentials,
                $parameters['project'],
                $parameters['ref_name'] ?? null,
                $parameters['per_page'] ?? 20
            ),

            // Merge Requests (Read)
            'gitlab_search_merge_requests' => $this->gitLabService->searchMergeRequests(
                $credentials,
                $parameters['project'],
                $parameters['state'] ?? null,
                $parameters['scope'] ?? null
            ),
            'gitlab_get_merge_request' => $this->gitLabService->getMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_get_merge_request_changes' => $this->gitLabService->getMergeRequestChanges(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_get_merge_request_discussions' => $this->gitLabService->getMergeRequestDiscussions(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_get_merge_request_commits' => $this->gitLabService->getMergeRequestCommits(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),

            // Merge Requests (Write)
            'gitlab_create_merge_request' => $this->gitLabService->createMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['source_branch'],
                $parameters['target_branch'],
                $parameters['title'],
                $parameters['description'] ?? null
            ),
            'gitlab_update_merge_request' => $this->gitLabService->updateMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['title'] ?? null,
                $parameters['description'] ?? null,
                $parameters['state_event'] ?? null
            ),
            'gitlab_add_merge_request_note' => $this->gitLabService->addMergeRequestNote(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['body']
            ),
            'gitlab_merge_merge_request' => $this->gitLabService->mergeMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['merge_commit_message'] ?? null,
                $parameters['squash'] ?? null,
                $parameters['should_remove_source_branch'] ?? null,
                $parameters['merge_when_pipeline_succeeds'] ?? null
            ),
            'gitlab_approve_merge_request' => $this->gitLabService->approveMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_unapprove_merge_request' => $this->gitLabService->unapproveMergeRequest(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_get_merge_request_approvals' => $this->gitLabService->getMergeRequestApprovals(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_list_merge_request_pipelines' => $this->gitLabService->listMergeRequestPipelines(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_create_merge_request_pipeline' => $this->gitLabService->createMergeRequestPipeline(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid']
            ),
            'gitlab_update_merge_request_assignees' => $this->gitLabService->updateMergeRequestAssignees(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['assignee_ids'] ?? null,
                $parameters['reviewer_ids'] ?? null
            ),
            'gitlab_list_my_merge_requests' => $this->gitLabService->listMyMergeRequests(
                $credentials,
                $parameters['scope'] ?? 'assigned_to_me',
                $parameters['state'] ?? 'opened',
                $parameters['search'] ?? null,
                $parameters['labels'] ?? null,
                $parameters['order_by'] ?? null,
                $parameters['sort'] ?? null,
                $parameters['per_page'] ?? 20
            ),

            // Issues
            'gitlab_search_issues' => $this->gitLabService->searchIssues(
                $credentials,
                $parameters['project'],
                $parameters['state'] ?? null,
                $parameters['scope'] ?? null
            ),
            'gitlab_get_issue' => $this->gitLabService->getIssue(
                $credentials,
                $parameters['project'],
                $parameters['issue_iid']
            ),
            'gitlab_create_issue' => $this->gitLabService->createIssue(
                $credentials,
                $parameters['project'],
                $parameters['title'],
                $parameters['description'] ?? null
            ),
            'gitlab_update_issue' => $this->gitLabService->updateIssue(
                $credentials,
                $parameters['project'],
                $parameters['issue_iid'],
                $parameters['title'] ?? null,
                $parameters['description'] ?? null,
                $parameters['state_event'] ?? null
            ),
            'gitlab_add_issue_note' => $this->gitLabService->addIssueNote(
                $credentials,
                $parameters['project'],
                $parameters['issue_iid'],
                $parameters['body']
            ),
            'gitlab_list_issue_notes' => $this->gitLabService->listIssueNotes(
                $credentials,
                $parameters['project'],
                $parameters['issue_iid']
            ),
            'gitlab_update_issue_assignees' => $this->gitLabService->updateIssueAssignees(
                $credentials,
                $parameters['project'],
                $parameters['issue_iid'],
                $parameters['assignee_ids']
            ),
            'gitlab_list_all_issues' => $this->gitLabService->listAllIssues(
                $credentials,
                $parameters['scope'] ?? 'assigned_to_me',
                $parameters['state'] ?? 'opened',
                $parameters['search'] ?? null,
                $parameters['per_page'] ?? 20
            ),
            'gitlab_create_discussion' => $this->gitLabService->createDiscussion(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['body']
            ),
            'gitlab_resolve_discussion' => $this->gitLabService->resolveDiscussion(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['discussion_id'],
                $parameters['resolved']
            ),
            'gitlab_add_discussion_note' => $this->gitLabService->addDiscussionNote(
                $credentials,
                $parameters['project'],
                $parameters['merge_request_iid'],
                $parameters['discussion_id'],
                $parameters['body']
            ),
            'gitlab_list_labels' => $this->gitLabService->listLabels(
                $credentials,
                $parameters['project']
            ),
            'gitlab_list_milestones' => $this->gitLabService->listMilestones(
                $credentials,
                $parameters['project'],
                $parameters['state'] ?? null
            ),
            'gitlab_get_milestone_issues' => $this->gitLabService->getMilestoneIssues(
                $credentials,
                $parameters['project'],
                $parameters['milestone_id']
            ),
            'gitlab_list_project_members' => $this->gitLabService->listProjectMembers(
                $credentials,
                $parameters['project']
            ),
            'gitlab_search_users' => $this->gitLabService->searchUsers(
                $credentials,
                $parameters['search'],
                $parameters['per_page'] ?? 20
            ),

            // Packages
            'gitlab_search_packages' => $this->gitLabService->searchPackages(
                $credentials,
                $parameters['project'],
                $parameters['package_name'] ?? null
            ),
            'gitlab_get_package' => $this->gitLabService->getPackage(
                $credentials,
                $parameters['project'],
                $parameters['package_id']
            ),

            // CI/CD
            'gitlab_list_pipelines' => $this->gitLabService->listPipelines(
                $credentials,
                $parameters['project'],
                $parameters['ref'] ?? null,
                $parameters['status'] ?? null
            ),
            'gitlab_get_pipeline' => $this->gitLabService->getPipeline(
                $credentials,
                $parameters['project'],
                $parameters['pipeline_id']
            ),
            'gitlab_get_pipeline_jobs' => $this->gitLabService->getPipelineJobs(
                $credentials,
                $parameters['project'],
                $parameters['pipeline_id'],
                $parameters['scope'] ?? null
            ),
            'gitlab_get_job_trace' => $this->gitLabService->getJobTrace(
                $credentials,
                $parameters['project'],
                $parameters['job_id']
            ),
            'gitlab_get_job' => $this->gitLabService->getJob(
                $credentials,
                $parameters['project'],
                $parameters['job_id']
            ),
            'gitlab_retry_pipeline' => $this->gitLabService->retryPipeline(
                $credentials,
                $parameters['project'],
                $parameters['pipeline_id']
            ),
            'gitlab_cancel_pipeline' => $this->gitLabService->cancelPipeline(
                $credentials,
                $parameters['project'],
                $parameters['pipeline_id']
            ),
            'gitlab_create_pipeline' => $this->gitLabService->createPipeline(
                $credentials,
                $parameters['project'],
                $parameters['ref'],
                $parameters['variables'] ?? null
            ),
            'gitlab_retry_job' => $this->gitLabService->retryJob(
                $credentials,
                $parameters['project'],
                $parameters['job_id']
            ),
            'gitlab_cancel_job' => $this->gitLabService->cancelJob(
                $credentials,
                $parameters['project'],
                $parameters['job_id']
            ),
            'gitlab_download_job_artifacts' => $this->gitLabService->downloadJobArtifacts(
                $credentials,
                $parameters['project'],
                $parameters['job_id']
            ),

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
        if (empty($credentials['gitlab_url']) || empty($credentials['api_token'])) {
            return false;
        }

        // Use GitLabService to validate credentials
        try {
            return $this->gitLabService->testConnection($credentials);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'gitlab_url',
                'url',
                'GitLab URL',
                'https://gitlab.com',
                true,
                'Your GitLab instance URL (e.g., https://gitlab.com or https://gitlab.example.com)'
            ),
            new CredentialField(
                'api_token',
                'password',
                'API Token',
                null,
                true,
                'Your GitLab Personal Access Token with api, read_api, read_repository, and write_repository scopes. <a href="https://docs.gitlab.com/ee/user/profile/personal_access_tokens.html" target="_blank" rel="noopener">Create API token</a>'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/gitlab_full.xml.twig', [
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
        return '/images/logos/gitlab-icon.svg';
    }
}
