# Workoflow Integration Platform - Available Tools

This document lists all available tools provided by the Workoflow Integration Platform, organized by integration type.

---

## Table of Contents

- [System Tools (Platform-Internal)](#system-tools-platform-internal)
- [User Integrations (External Services)](#user-integrations-external-services)
  - [Jira](#jira)
  - [Confluence](#confluence)
  - [GitLab](#gitlab)
  - [Trello](#trello)
  - [SharePoint](#sharepoint)
  - [Projektron](#projektron)
  - [SAP Cloud for Customer (C4C)](#sap-cloud-for-customer-c4c)

---

## System Tools (Platform-Internal)

System tools are platform-internal functionalities that don't require external service credentials. They are protected by API Basic Auth authentication.

### File & Content Management

| Tool | Description |
|------|-------------|
| `share_file` | Upload and share a file. Returns a signed URL that can be used to access the file. Parameters: `binaryData` (base64 encoded), `fileName` |
| `read_file_tool` | Read content from a file when user asks questions related to a file. Parameter: `url` |
| `content_learn` | Upload a file to vector database for future querying. Parameters: `url`, `collection` |
| `content_query` | Retrieve content from vector database. Returns content with source for validation. Parameters: `query`, `collection` |

### Document Generation

| Tool | Description |
|------|-------------|
| `generate_pptx` | Create PowerPoint presentations from text content. Parameters: `input` (content), `name` (filename) |
| `generate_and_upload_pdf` | Generate PDF files. Returns URL to uploaded file. Parameters: `output_content` (HTML/Markdown), `title` |

### Web & Search

| Tool | Description |
|------|-------------|
| `read_page_tool` | Extract text content from webpages. Not for private pages. Parameter: `url` |
| `SearXNG_tool` | Web search using Google and DuckDuckGo engines. Parameter: `query` |

### Employee Directory

| Tool | Description |
|------|-------------|
| `employees_query_v2` | Query employee/colleague information from the directory |
| `employee_profile` | Load detailed information and contact details for a specific employee. Parameter: `employee_id` |

### System Utilities

| Tool | Description |
|------|-------------|
| `clear_memory_tool` | Clear conversation memory when requested by user |
| `report_issue_tool` | Report issues or problems encountered by the user. Parameters: `title`, `description` |
| `fetch_valantic_events` | Retrieve upcoming company events |

---

## User Integrations (External Services)

User integrations connect to external services and require user-specific credentials (API keys, tokens) stored encrypted in the database.

---

### Jira

**Integration Type:** `jira`

Issue tracking and project management integration with full CRUD operations.

#### Search & Read

| Tool | Description |
|------|-------------|
| `jira_search` | Search issues using JQL (Jira Query Language). Returns issues with summary, status, assignee, priority, project |
| `jira_get_issue` | Get detailed issue information including comments, attachments, links, subtasks, watchers |
| `jira_get_board` | Get board details including type (scrum/kanban), name, and project information |
| `jira_get_sprints_from_board` | Get all sprints from a Scrum board (Scrum only). Use `jira_get_board` first to verify type |
| `jira_get_sprint_issues` | Get all issues in a specific sprint with full field details |
| `jira_get_board_issues` | Universal tool for any board type - auto-detects scrum/kanban and retrieves issues |
| `jira_get_kanban_issues` | Get all issues from a Kanban board (no sprints) |
| `jira_get_available_transitions` | Get available status transitions for workflow navigation |
| `jira_get_edit_metadata` | Get editable fields and allowed values before updating |
| `jira_get_issue_link_types` | Get all available issue link types (blocks, relates to, duplicates, etc.) |
| `jira_get_project_metadata` | Get project info including issue types, components, versions |
| `jira_get_create_field_metadata` | Get detailed field metadata for creating specific issue types |
| `jira_get_priorities` | Get all available priorities with IDs for issue creation |
| `jira_search_users` | Search users by name/email. Returns accountId for assignments |
| `jira_get_myself` | Get current authenticated user's accountId for reporter field |

#### Create & Update

| Tool | Description |
|------|-------------|
| `jira_create_issue` | Create new issue with standard and custom fields. Use metadata tools first |
| `jira_bulk_create_issues` | Create multiple issues at once (up to 50) |
| `jira_update_issue` | Update issue fields: summary, description, priority, assignee, labels, components |
| `jira_assign_issue` | Quick shortcut to assign/unassign issue to user |
| `jira_add_comment` | Add comment to an issue |
| `jira_transition_issue` | Execute workflow transition by ID |
| `jira_transition_issue_to_status` | Intelligently navigate workflow to target status automatically |
| `jira_link_issues` | Link two issues with relationship type (blocks, relates to, etc.) |
| `jira_delete_issue` | Permanently delete an issue (WARNING: cannot be undone) |

**Total Jira Tools: 24**

---

### Confluence

**Integration Type:** `confluence`

Wiki and documentation platform integration.

| Tool | Description |
|------|-------------|
| `confluence_search` | Search pages using CQL (Confluence Query Language). Returns pages with content and links |
| `confluence_get_page` | Get detailed page content including body, version, space, and metadata |
| `confluence_get_comments` | Get all comments from a specific page with author and timestamps |
| `confluence_create_page` | Create new page with markdown, plain text, or HTML content |
| `confluence_update_page` | Update existing page with automatic version conflict handling |

**Total Confluence Tools: 5**

---

### GitLab

**Integration Type:** `gitlab`

Complete GitLab integration for repository, CI/CD, issues, and merge request management.

#### Projects & Files

| Tool | Description |
|------|-------------|
| `gitlab_get_project` | Get project details including visibility, statistics, and settings |
| `gitlab_list_projects` | List accessible projects with filtering and pagination |
| `gitlab_get_file_content` | Get file content from repository at specific ref |
| `gitlab_list_repository_tree` | List files and directories in repository |
| `gitlab_create_file` | Create new file in repository. Returns file_path, branch, commit_id |
| `gitlab_update_file` | Update existing file in repository |
| `gitlab_delete_file` | Delete file from repository |

#### Branches & Tags

| Tool | Description |
|------|-------------|
| `gitlab_list_branches` | List all branches with commit info and protection status |
| `gitlab_create_branch` | Create new branch from ref |
| `gitlab_delete_branch` | Delete a branch |
| `gitlab_protect_branch` | Set branch protection rules |
| `gitlab_compare_branches` | Compare two branches showing commits and diffs |
| `gitlab_list_tags` | List all repository tags |
| `gitlab_create_tag` | Create new tag with optional message |
| `gitlab_delete_tag` | Delete a tag |

#### Commits

| Tool | Description |
|------|-------------|
| `gitlab_list_commits` | List commits with filtering by ref, date range |
| `gitlab_get_commit` | Get detailed commit info with stats (additions, deletions) |

#### Merge Requests

| Tool | Description |
|------|-------------|
| `gitlab_search_merge_requests` | Search MRs in project with state/author filters |
| `gitlab_list_my_merge_requests` | List MRs authored by or assigned to current user |
| `gitlab_get_merge_request` | Get detailed MR info with assignees, reviewers, pipeline status |
| `gitlab_get_merge_request_changes` | Get diff/changes for MR showing file modifications |
| `gitlab_get_merge_request_discussions` | Get all discussions/comments on MR |
| `gitlab_get_merge_request_commits` | Get all commits in MR |
| `gitlab_get_merge_request_approvals` | Get approval status and required approvals |
| `gitlab_list_merge_request_pipelines` | List all pipelines triggered for MR |
| `gitlab_create_merge_request` | Create new MR with title, description, source/target branches |
| `gitlab_update_merge_request` | Update MR title, description, labels, milestone |
| `gitlab_add_merge_request_note` | Add comment to MR |
| `gitlab_merge_merge_request` | Execute merge operation |
| `gitlab_approve_merge_request` | Add approval to MR |
| `gitlab_unapprove_merge_request` | Remove approval from MR |
| `gitlab_create_merge_request_pipeline` | Trigger new pipeline for MR |
| `gitlab_update_merge_request_assignees` | Update MR assignees and reviewers |

#### Issues

| Tool | Description |
|------|-------------|
| `gitlab_search_issues` | Search issues in project with filters |
| `gitlab_list_all_issues` | List all issues across projects |
| `gitlab_get_issue` | Get detailed issue information |
| `gitlab_create_issue` | Create new issue with title, description, labels, assignees |
| `gitlab_update_issue` | Update issue fields |
| `gitlab_add_issue_note` | Add comment to issue |
| `gitlab_list_issue_notes` | List all comments on issue |
| `gitlab_update_issue_assignees` | Update issue assignees |

#### Discussions

| Tool | Description |
|------|-------------|
| `gitlab_create_discussion` | Create new discussion thread on MR |
| `gitlab_resolve_discussion` | Resolve discussion thread |
| `gitlab_add_discussion_note` | Reply to existing discussion |

#### CI/CD Pipelines

| Tool | Description |
|------|-------------|
| `gitlab_list_pipelines` | List project pipelines with status, source, dates |
| `gitlab_get_pipeline` | Get detailed pipeline info with coverage and status |
| `gitlab_get_pipeline_jobs` | Get all jobs in a pipeline |
| `gitlab_create_pipeline` | Trigger new pipeline on branch/tag |
| `gitlab_retry_pipeline` | Retry failed pipeline |
| `gitlab_cancel_pipeline` | Cancel running pipeline |
| `gitlab_get_job` | Get detailed job information |
| `gitlab_get_job_trace` | Get job console log output (last N lines for LLM optimization) |
| `gitlab_retry_job` | Retry a failed job |
| `gitlab_cancel_job` | Cancel a running job |
| `gitlab_download_job_artifacts` | Download job artifacts |

#### Project Management

| Tool | Description |
|------|-------------|
| `gitlab_list_labels` | List project labels |
| `gitlab_list_milestones` | List project milestones |
| `gitlab_get_milestone_issues` | Get issues in specific milestone |
| `gitlab_list_project_members` | List project members with roles |
| `gitlab_search_users` | Search GitLab users |
| `gitlab_search_packages` | Search project packages |
| `gitlab_get_package` | Get package details |

**Total GitLab Tools: 56**

---

### Trello

**Integration Type:** `trello`

Kanban-style board and card management.

#### Read Operations

| Tool | Description |
|------|-------------|
| `trello_search` | Search across all boards, cards, and lists |
| `trello_get_boards` | Get all accessible boards |
| `trello_get_board` | Get detailed board information |
| `trello_get_board_lists` | Get all lists (columns) on a board |
| `trello_get_board_cards` | Get all cards on a board across all lists |
| `trello_get_list_cards` | Get cards in a specific list |
| `trello_get_card` | Get detailed card info with members, labels, attachments |
| `trello_get_card_comments` | Get all comments on a card |
| `trello_get_card_checklists` | Get checklists and checklist items on a card |

#### Write Operations

| Tool | Description |
|------|-------------|
| `trello_create_card` | Create new card in a list |
| `trello_update_card` | Update card name, description, position, list |
| `trello_add_comment` | Add comment to a card |

**Total Trello Tools: 12**

---

### SharePoint

**Integration Type:** `sharepoint`

Microsoft SharePoint document and content management.

| Tool | Description |
|------|-------------|
| `sharepoint_search` | Search ALL SharePoint content (Files, Sites, Pages, Lists, Drives) using KQL |
| `sharepoint_read_document` | Extract text from documents (Word, Excel, PowerPoint, PDF). Returns content with metadata |
| `sharepoint_read_page` | Read SharePoint page content including web parts and sections |
| `sharepoint_list_files` | List files in a directory with metadata (size, dates, creator) |
| `sharepoint_download_file` | Download file content or get pre-authenticated download URL |
| `sharepoint_get_list_items` | Get items from SharePoint list with custom column values |

**Total SharePoint Tools: 6**

---

### Projektron

**Integration Type:** `projektron`

Time tracking and project management integration.

| Tool | Description |
|------|-------------|
| `projektron_get_all_tasks` | Get all bookable tasks from personal task list. Returns oid, name, booking_url |
| `projektron_get_worklog` | Get time entries for a specific date. Shows booked hours per task for the week |
| `projektron_add_worklog` | Book time entry to a task. Use `projektron_get_all_tasks` first to get valid OIDs |

**Total Projektron Tools: 3**

---

### SAP Cloud for Customer (C4C)

**Integration Type:** `sap_c4c`

SAP CRM integration for leads, opportunities, accounts, and contacts.

#### Lead Management

| Tool | Description |
|------|-------------|
| `c4c_create_lead` | Create new lead with name, contact info, company, qualification level |
| `c4c_get_lead` | Get lead details by ObjectID |
| `c4c_search_leads` | Search leads using OData filter syntax (e.g., "StatusCode eq '2'") |
| `c4c_update_lead` | Update lead fields (name, status, qualification, contact info) |
| `c4c_list_leads` | Get paginated list of all leads |

#### Opportunity Management

| Tool | Description |
|------|-------------|
| `c4c_create_opportunity` | Create sales opportunity with account, contact, revenue, sales phase |
| `c4c_get_opportunity` | Get opportunity details by ObjectID |
| `c4c_search_opportunities` | Search opportunities using OData filters |
| `c4c_update_opportunity` | Update opportunity fields (name, revenue, phase, probability) |
| `c4c_list_opportunities` | Get paginated list of all opportunities |

#### Account Management

| Tool | Description |
|------|-------------|
| `c4c_create_account` | Create corporate account with address, phone, email |
| `c4c_get_account` | Get account details, optionally expand linked contacts |
| `c4c_search_accounts` | Search accounts using OData filters |
| `c4c_update_account` | Update account fields (name, address, contact info) |
| `c4c_list_accounts` | Get paginated list of all accounts |

#### Contact Management

| Tool | Description |
|------|-------------|
| `c4c_create_contact` | Create contact with name, account link, email, phone, job title |
| `c4c_get_contact` | Get contact details by ObjectID |
| `c4c_search_contacts` | Search contacts using OData filters |
| `c4c_update_contact` | Update contact fields |
| `c4c_list_contacts` | Get paginated list of all contacts |

**Total SAP C4C Tools: 20**

---

## Summary

| Integration | Tool Count | Type |
|-------------|------------|------|
| System Tools | 13 | Platform-Internal |
| Jira | 24 | User Integration |
| Confluence | 5 | User Integration |
| GitLab | 56 | User Integration |
| Trello | 12 | User Integration |
| SharePoint | 6 | User Integration |
| Projektron | 3 | User Integration |
| SAP C4C | 20 | User Integration |
| **Total** | **139** | |

---

## API Access

Tools are accessed via the REST API endpoint:

```
GET /api/integrations/{org-uuid}?workflow_user_id={workflow-user-id}
Authorization: Basic xxxx==
```

- **System Tools**: Included with `tool_type=system` parameter
- **User Integrations**: Dynamically provided based on activated user integrations

---

*Last updated: 2025-12-03*
