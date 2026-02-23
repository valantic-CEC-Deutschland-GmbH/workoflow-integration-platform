# Activity Overview — KPI Definitions

All KPIs on the **My Agent** dashboard are scoped to the current user and cover the **last 30 days**. Values are cached for 5 minutes.

---

## Agent Sessions

**What it shows:** How many times your AI agent was invoked.

**Calculation:** Count of distinct `execution_id` values in the audit log. Each `execution_id` represents one end-to-end agent invocation (a single prompt → tool calls → response cycle).

```
COUNT(DISTINCT execution_id)
WHERE created_at >= NOW() - 30 days
```

---

## Tool Executions

**What it shows:** How many individual tools your agent ran.

**Calculation:** Count of audit log entries with action `tool_execution.started`. Each entry represents one tool being called by the agent (e.g. a Jira search, a Confluence page fetch).

```
COUNT(*)
WHERE action = 'tool_execution.started'
  AND created_at >= NOW() - 30 days
```

---

## API Calls

**What it shows:** How many times external systems queried your agent's available tools or prompts, even if no tool was actually executed.

**Calculation:** Count of audit log entries matching any of these actions:

| Action | Trigger |
|--------|---------|
| `api.get_tools` | REST API tool listing |
| `api.get_skills` | REST API skill listing |
| `api.mcp.get_tools` | MCP protocol tool listing |
| `api.prompts.list` | Prompt Vault API listing |

```
COUNT(*)
WHERE action IN ('api.get_tools', 'api.get_skills', 'api.mcp.get_tools', 'api.prompts.list')
  AND created_at >= NOW() - 30 days
```

---

## Prompt Vault Activity

**What it shows:** How actively the Prompt Vault was used — creating, editing, voting, and commenting on prompts.

**Calculation:** Count of audit log entries matching any of these actions:

| Action | Meaning |
|--------|---------|
| `prompt.created` | New prompt created |
| `prompt.updated` | Existing prompt edited |
| `prompt.upvote.added` | Upvote given to a prompt |
| `prompt.upvote.removed` | Upvote withdrawn |
| `prompt.comment.added` | Comment posted on a prompt |
| `prompt.comment.deleted` | Comment removed |

```
COUNT(*)
WHERE action IN ('prompt.created', 'prompt.updated', 'prompt.upvote.added',
                 'prompt.upvote.removed', 'prompt.comment.added', 'prompt.comment.deleted')
  AND created_at >= NOW() - 30 days
```

---

## Active Skills

**What it shows:** How many integration configurations (skills) you currently have set up.

**Calculation:** Count of `IntegrationConfig` records belonging to your organisation and workflow user. This is a **current snapshot**, not a 30-day aggregate — it reflects how many skills are configured right now.

```
COUNT(integration_configs)
WHERE organisation = current_organisation
  AND workflow_user_id = current_user_workflow_id
```

---

## Tool Types Used

**What it shows:** How many different types of integrations your agent actually used (e.g. Jira, Confluence, SharePoint).

**Calculation:** Count of distinct `integration_type` values extracted from the JSON `data` field of tool execution audit entries.

```
COUNT(DISTINCT JSON_EXTRACT(data, '$.integration_type'))
WHERE action LIKE 'tool_execution.%'
  AND created_at >= NOW() - 30 days
```

---

## Most Used Tools (Top 3)

**What it shows:** The three individual tools your agent called most frequently.

**Calculation:** Group all `tool_execution.started` entries by the `tool_name` field from the JSON `data` column, then return the top 3 by count.

```
SELECT JSON_EXTRACT(data, '$.tool_name') AS tool_name, COUNT(*) AS count
WHERE action = 'tool_execution.started'
  AND created_at >= NOW() - 30 days
GROUP BY tool_name
ORDER BY count DESC
LIMIT 3
```

The display format is `#1 tool_name — 42x` where `42x` is the execution count.

---

## User Scoping

All queries filter to the current user's activity using a dual-match strategy:

- **Web-originated entries** (prompts, organisation actions): matched by the `user` foreign key on the audit log
- **API-originated entries** (tool executions, API calls): the `user` FK may be null, so the query also matches on `workflow_user_id` stored in the JSON `data` field

This ensures you only see your own activity, never other users' data.
