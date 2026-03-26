# Jira Ticket Creation - Claude Code Helper

Reference for creating tickets in the **Genesis Horizon (GH)** project on `nexus-netsoft.atlassian.net` via the Atlassian MCP tools.

## Cloud ID

```
nexus-netsoft.atlassian.net
```

Internal UUID: `c06c1dc5-e199-4a2d-ae21-66b018eb9f3a`

## Board

- Board ID: **1136**
- Backlog URL: `https://nexus-netsoft.atlassian.net/jira/software/c/projects/GH/boards/1136/backlog`

## Issue Types

| Name | ID | Notes |
|------|----|-------|
| Task | 3 | Use `issueTypeName: "Task"` |
| Bug | 1 | |
| Story | 7 | |
| Epic | 8 | |
| Aufgabe | 10000 | German "Task" variant |

## Required Fields for Task Creation

When creating a Task in GH, the following fields are **required** beyond summary:

### 1. Testable (`customfield_13211`) - REQUIRED, no default

Radio button field. Must be set via `additional_fields`:

| Value | ID |
|-------|----|
| Testable | 11701 |
| Not Testable | 11702 |

```json
"additional_fields": {"customfield_13211": {"id": "11702"}}
```

### 2. Textarea Custom Fields - REQUIRED, have defaults (but defaults are placeholder templates!)

These fields have default values that are **placeholder templates** with `{curly brace}` instructions (e.g., "As a {user}, I want {function description}..."). If you don't explicitly set them, the ticket will display ugly template text.

**Always set these fields explicitly** using ADF (Atlassian Document Format):

| Field | Custom Field ID | Purpose |
|-------|----------------|---------|
| User Story | `customfield_12700` | User story description |
| Offene Fragen | `customfield_12901` | Open questions |
| Offene Zulieferungen | `customfield_12902` | Pending deliveries |
| Akzeptanzkriterien | `customfield_12903` | Acceptance criteria |
| Technische Akzeptanzkriterien | `customfield_12904` | Technical acceptance criteria |
| Details/Diskussion | `customfield_12905` | Details and discussion |

**These fields require ADF format** (Atlassian Document Format). Plain strings will cause a `Bad Request` error.

ADF template for a simple text value:
```json
{
  "type": "doc",
  "version": 1,
  "content": [
    {
      "type": "paragraph",
      "content": [
        {"type": "text", "text": "Your content here"}
      ]
    }
  ]
}
```

For non-development tickets (blockers, tracking, etc.), set irrelevant fields to `"-"`.

## Sprint Field

- Sprint field: `customfield_10007`
- Format: requires numeric sprint ID, e.g. `{"customfield_10007": {"id": 12345}}`
- **The Atlassian MCP tools cannot list sprints from a board.** You cannot discover sprint IDs programmatically if the sprint is empty.
- Known sprints: `WIP` (id: 6528, active), `Patricks Challenges` (id: 7385, closed)
- Workaround: Ask the user for the sprint ID, or have them drag tickets from the backlog UI.

## Priority Values

| Name | ID |
|------|----|
| Blocker | 1 |
| Kritisch | 2 |
| Schwerwiegend | 3 |
| Highest | 10000 |
| High | 10002 |
| Medium | 10001 |
| Normal | 6 (default) |
| Low | 10003 |
| Lowest | 10104 |

## Complete Example: Creating a Task

```
Tool: mcp__claude_ai_Atlassian__createJiraIssue
  cloudId: "nexus-netsoft.atlassian.net"
  projectKey: "GH"
  issueTypeName: "Task"
  summary: "My Task Title"
  description: "Task description in markdown"
  additional_fields: {
    "customfield_13211": {"id": "11702"}
  }
```

Then immediately follow up with `editJiraIssue` to set the textarea fields (ADF format):

```
Tool: mcp__claude_ai_Atlassian__editJiraIssue
  cloudId: "nexus-netsoft.atlassian.net"
  issueIdOrKey: "GH-XXX"
  fields: {
    "customfield_12700": {"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"-"}]}]},
    "customfield_12901": {"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"-"}]}]},
    "customfield_12902": {"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"-"}]}]},
    "customfield_12903": {"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"-"}]}]},
    "customfield_12904": {"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"-"}]}]},
    "customfield_12905": {"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"Relevant details here"}]}]}
  }
```

## Gotchas

1. **Testable field is required** - creation fails without `customfield_13211`
2. **Textarea fields need ADF format** - plain strings cause `Bad Request` on edit
3. **Textarea defaults are ugly placeholders** - always overwrite them explicitly after creation
4. **Sprint assignment needs numeric ID** - MCP tools can't list board sprints; ask user or use backlog UI
5. **Labels field** accepts plain array of strings: `{"labels": ["BLOCKER", "another-label"]}`
6. **Description field** on `createJiraIssue` accepts markdown strings (not ADF), but `\n` renders as literal `\\n` - keep descriptions as single-line or use ADF via edit after creation
