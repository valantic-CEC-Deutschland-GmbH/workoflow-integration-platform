# Workoflow Global Architecture

## Overview

Workoflow is a **multi-agent AI integration platform** that enables users to connect business tools (Jira, Confluence, SharePoint, GitLab, etc.) and interact with them through AI agents via chat interfaces (Microsoft Teams, Slack, WhatsApp).

The platform follows a **multi-agent orchestration pattern**: a **Main Agent** (router/coordinator) delegates user requests to specialized **Sub-Agents** (one per integration type), which discover and execute tools at runtime via the Integration Platform's REST API.

This document describes the global architecture across all system components and serves as the architectural guideline for the AI agent orchestrator.

## System Components

The platform consists of four major systems:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           WORKOFLOW PLATFORM                               │
│                                                                             │
│  ┌──────────────┐   ┌──────────────────┐   ┌───────────────────────────┐   │
│  │  Channel      │   │  AI Agent        │   │  Integration Platform     │   │
│  │  Clients      │──▶│  Orchestrator    │──▶│  (workoflow-promopage-v2) │   │
│  │              │   │                  │   │                           │   │
│  │  • Teams Bot  │   │  Main Agent      │   │  • Tool Definitions       │   │
│  │  • Slack      │   │   └─▶ Sub-Agents │   │  • System Prompts         │   │
│  │  • WhatsApp   │   │       └─▶ Tools  │   │  • Tool Execution         │   │
│  │  • MCP Client │   │                  │   │  • Credential Management  │   │
│  └──────────────┘   └──────────────────┘   └───────────────────────────┘   │
│                              │                          │                   │
│                     ┌────────▼────────┐        ┌────────▼────────┐         │
│                     │  Infrastructure  │        │ External Services│         │
│                     │  (ai-setup)      │        │ (Jira, GitLab…) │         │
│                     │                  │        └─────────────────┘         │
│                     │  • LiteLLM Proxy │                                    │
│                     │  • Phoenix       │                                    │
│                     │  • Qdrant        │                                    │
│                     │  • Redis/PG      │                                    │
│                     └─────────────────┘                                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1. Integration Platform (`workoflow-promopage-v2`)

**Role**: Single source of truth for integrations, tools, credentials, and system prompts.

- Symfony 8.0 application with FrankenPHP
- Manages 14 user integrations + 13 system tools
- Exposes REST API + MCP Server for tool discovery and execution
- Stores encrypted credentials per user per organisation
- Renders sub-agent system prompts via Twig templates
- Handles multi-tenant organisation management

### 2. AI Agent Orchestrator (currently n8n — to be replaced)

**Role**: Receives user messages, orchestrates AI agents, coordinates tool execution.

- Implements the Main Agent → Sub-Agent → Tool Loop pattern
- Calls LLM via LiteLLM proxy for reasoning
- Consumes Integration Platform APIs for tool discovery/execution
- Returns structured responses to channel clients

### 3. Channel Clients (`workoflow-bot` and future clients)

**Role**: User-facing interfaces that forward messages to the orchestrator.

- Microsoft Teams Bot (Node.js + Bot Framework)
- Enriches messages with user/tenant context
- Resolves per-tenant webhook URLs
- Displays AI responses with attachments and feedback collection

### 4. Infrastructure (`workoflow-ai-setup`)

**Role**: Shared services for LLM routing, observability, search, and document processing.

- LiteLLM Proxy (model routing: Azure OpenAI, OpenAI, with failover)
- Phoenix (OpenTelemetry observability for LLM calls)
- Qdrant (vector database for embeddings)
- SearXNG (web search), Tika (document extraction), Gotenberg (PDF conversion)
- PostgreSQL, Redis, MinIO

## End-to-End Data Flow

```
User types message in Microsoft Teams
    │
    ▼
┌─────────────────────────────────────────────────────────┐
│ 1. CHANNEL CLIENT (workoflow-bot)                        │
│    • Receives Teams activity via Bot Framework            │
│    • Enriches payload: user info, tenant ID, session,    │
│      thread context, file detection                      │
│    • Resolves tenant webhook URL via                     │
│      GET /api/tenant/{tenantId}/settings                 │
│    • POSTs enriched payload to orchestrator webhook      │
│    • Shows typing indicator while waiting                │
└─────────────────────────┬───────────────────────────────┘
                          │ HTTP POST (webhook)
                          ▼
┌─────────────────────────────────────────────────────────┐
│ 2. ORCHESTRATOR (Main Agent)                             │
│    • Loads main agent system prompt                      │
│    • Creates LLM agent with sub-agent routing tools      │
│    • LLM analyzes user intent, selects sub-agent(s)      │
│    • Delegates to sub-agent(s) sequentially              │
│    • Aggregates results, responds in past tense          │
└─────────────────────────┬───────────────────────────────┘
                          │ Internal delegation
                          ▼
┌─────────────────────────────────────────────────────────┐
│ 3. ORCHESTRATOR (Sub-Agent, e.g., SharePoint Agent)      │
│    • Loads sub-agent system prompt via                    │
│      GET /api/skills/?tool_type=sharepoint                │
│    • Creates LLM agent with two tools:                   │
│      - CURRENT_USER_TOOLS (discover available tools)     │
│      - CURRENT_USER_EXECUTE_TOOL (execute a tool)        │
│    • LLM runs tool loop:                                 │
│      1. Call CURRENT_USER_TOOLS → get tool definitions   │
│      2. Select appropriate tool                          │
│      3. Call CURRENT_USER_EXECUTE_TOOL with params       │
│      4. Analyze result, iterate if needed                │
│    • Returns result to Main Agent                        │
└─────────────────────────┬───────────────────────────────┘
                          │ HTTP calls
                          ▼
┌─────────────────────────────────────────────────────────┐
│ 4. INTEGRATION PLATFORM (workoflow-promopage-v2)         │
│    • Tool Discovery:                                     │
│      GET /api/integrations/{org-uuid}/?tool_type=X       │
│      → Returns OpenAPI-style tool definitions            │
│    • Tool Execution:                                     │
│      POST /api/integrations/{org-uuid}/execute            │
│      → Decrypts credentials, calls external service      │
│      → Audit logs the execution                          │
│      → Returns result                                    │
└─────────────────────────┬───────────────────────────────┘
                          │ HTTP calls
                          ▼
┌─────────────────────────────────────────────────────────┐
│ 5. EXTERNAL SERVICES                                     │
│    • Jira, Confluence, SharePoint, GitLab, etc.          │
│    • Accessed with user's encrypted credentials          │
│    • Results flow back up the chain                      │
└─────────────────────────────────────────────────────────┘
```

## Integration Platform APIs

The orchestrator consumes these APIs from the Integration Platform. All endpoints use **Basic Auth** (`API_AUTH_USER:API_AUTH_PASSWORD`).

### Skills API — System Prompts

**Purpose**: Retrieve sub-agent system prompts and skill metadata.

```
GET /api/skills/?organisation_uuid={uuid}&workflow_user_id={id}&tool_type={type}&execution_id={id}
```

**Response**:
```json
{
  "skills": [
    {
      "type": "sharepoint",
      "name": "SharePoint",
      "instance_id": 42,
      "instance_name": "Company SharePoint",
      "system_prompt": "<?xml version=\"1.0\"?>...[full XML system prompt]..."
    }
  ]
}
```

**Key behaviors**:
- `tool_type` is CSV-format: `jira,confluence` or single: `sharepoint`
- `workflow_user_id` is **required** for user integrations (security: prevents cross-user data leakage)
- System integrations excluded by default, included with `tool_type=system`
- `system_prompt` is rendered by the integration's Twig template (e.g., `jira_full.xml.twig`)
- Returns `null` system_prompt with `system_prompt_error` if rendering fails

### Tools API — Tool Discovery

**Purpose**: Discover available tools for a user in OpenAPI function-call format.

```
GET /api/integrations/{org-uuid}/?workflow_user_id={id}&tool_type={type}&execution_id={id}
```

**Response**:
```json
{
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "sharepoint_search_42",
        "description": "Search SharePoint documents using KQL...",
        "parameters": {
          "type": "object",
          "properties": {
            "query": { "type": "string", "description": "KQL search query" },
            "maxResults": { "type": "integer", "description": "Max results" }
          },
          "required": ["query"]
        }
      }
    }
  ]
}
```

**Tool naming convention**:
- User integrations: `{type}_{toolName}_{configId}` (e.g., `jira_search_123`)
- System integrations: `{type}_{toolName}` (e.g., `system_web_search`)
- Remote MCP tools: `{toolName}_{configId}` or `{toolName}_org`

### Execute API — Tool Execution

**Purpose**: Execute a specific tool with parameters.

```
POST /api/integrations/{org-uuid}/execute?workflow_user_id={id}&execution_id={id}
```

**Request**:
```json
{
  "tool_id": "sharepoint_search_42",
  "parameters": {
    "query": "project plan",
    "maxResults": "10"
  }
}
```

**Response** (success):
```json
{
  "success": true,
  "result": { ... }
}
```

**Response** (error):
```json
{
  "success": false,
  "error": "Tool not found: sharepoint_search_999"
}
```

**Key behaviors**:
- Parses `tool_id` to extract `configId` suffix and `toolName`
- Decrypts user credentials from `IntegrationConfig.encryptedCredentials`
- Enforces **Tool Access Modes** (Read Only / Standard / Full) based on `ToolCategory` (READ, WRITE, DELETE)
- Audit logs execution start, completion, and failure
- Supports standard tools, per-user Remote MCP tools, and org-wide MCP tools

### MCP Server API

**Purpose**: Alternative tool interface using MCP protocol (for Claude Desktop, etc.).

```
GET  /api/mcp/tools      (X-Prompt-Token header)
POST /api/mcp/execute     (X-Prompt-Token header)
```

Authentication uses `X-Prompt-Token` header (maps to `UserOrganisation.personalAccessToken`), automatically identifying user and organisation.

### Tenant Settings API

**Purpose**: Resolve per-tenant orchestrator webhook URL.

```
GET /api/tenant/{org-uuid}/settings
```

**Response**: Contains webhook URL and auth configuration for the tenant's orchestrator endpoint.

### User Registration API

**Purpose**: Register users and generate magic links for integration management.

```
POST /api/register
```

**Payload**: `{ name, org_uuid, workflow_user_id, email, channel_info }`
**Response**: `{ magic_link, user_id, email, organisation }`

## Multi-Agent Orchestration Architecture

This is the core pattern that the orchestrator must implement.

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     ORCHESTRATOR                                 │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ MAIN AGENT (Router/Coordinator)                           │   │
│  │                                                            │   │
│  │ System Prompt: main_agent.twig                            │   │
│  │ LLM: via LiteLLM Proxy                                    │   │
│  │                                                            │   │
│  │ Tools: N sub-agent invocation tools                       │   │
│  │   ├── invoke_jira_agent(taskDescription)                  │   │
│  │   ├── invoke_confluence_agent(taskDescription)            │   │
│  │   ├── invoke_sharepoint_agent(taskDescription)            │   │
│  │   ├── invoke_gitlab_agent(taskDescription)                │   │
│  │   ├── invoke_outlook_mail_agent(taskDescription)          │   │
│  │   ├── invoke_outlook_calendar_agent(taskDescription)      │   │
│  │   ├── invoke_msteams_agent(taskDescription)               │   │
│  │   ├── invoke_trello_agent(taskDescription)                │   │
│  │   ├── invoke_wrike_agent(taskDescription)                 │   │
│  │   ├── invoke_hubspot_agent(taskDescription)               │   │
│  │   ├── invoke_sap_c4c_agent(taskDescription)               │   │
│  │   ├── invoke_sap_sac_agent(taskDescription)               │   │
│  │   ├── invoke_projektron_agent(taskDescription)            │   │
│  │   ├── invoke_remote_mcp_agent(taskDescription)            │   │
│  │   └── invoke_system_tools_agent(taskDescription)          │   │
│  └───────────────────────┬──────────────────────────────────┘   │
│                          │ delegates                             │
│                          ▼                                       │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ SUB-AGENT (e.g., SharePoint Agent)                        │   │
│  │                                                            │   │
│  │ System Prompt: loaded from /api/skills/?tool_type=X       │   │
│  │ LLM: via LiteLLM Proxy                                    │   │
│  │                                                            │   │
│  │ Tools (2 tools, always the same pattern):                 │   │
│  │   ├── CURRENT_USER_TOOLS     → GET  /api/integrations/…   │   │
│  │   └── CURRENT_USER_EXECUTE   → POST /api/integrations/…   │   │
│  │                                                            │   │
│  │ Tool Loop:                                                 │   │
│  │   1. Call CURRENT_USER_TOOLS → discover available tools    │   │
│  │   2. LLM selects tool + params based on taskDescription   │   │
│  │   3. Call CURRENT_USER_EXECUTE with tool_id + params      │   │
│  │   4. LLM analyzes result                                  │   │
│  │   5. If more work needed → repeat from step 2             │   │
│  │   6. Return final response to Main Agent                  │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### Main Agent

The Main Agent is the entry point for all user requests. It:

1. **Receives** the user message with context (tenant ID, user ID, session, locale)
2. **Analyzes** user intent using LLM with `main_agent.twig` system prompt
3. **Routes** to appropriate sub-agent(s) by calling sub-agent tools
4. **Coordinates** multi-agent tasks sequentially (data from agent A feeds into agent B)
5. **Aggregates** results and responds in past tense

**System prompt source**: `templates/skills/prompts/main_agent.twig`

**Key routing rules** (defined in the system prompt):
- **WRITE operations** → always delegate to sub-agent (never answer from memory)
- **READ operations on specific resources** (ticket IDs, page names) → always delegate
- **META questions** ("What tools do you have?") → handle directly, do NOT list integrations
- **Generic questions** → respond directly or delegate to system tools agent

**Sub-agent tools exposed to Main Agent**: Each sub-agent type becomes a callable tool for the Main Agent. The tool accepts a `taskDescription` parameter — a natural language description of what the sub-agent should accomplish.

### Sub-Agent Pattern

Each sub-agent follows an identical structural pattern. Only the system prompt and tool_type filter differ.

**Initialization** (per request):
1. Load system prompt: `GET /api/skills/?organisation_uuid={tenantID}&tool_type={type}&workflow_user_id={userID}`
2. Extract `skills[0].system_prompt` — the XML system prompt for this integration type
3. Create LLM agent with this system prompt
4. Attach two HTTP tools: `CURRENT_USER_TOOLS` and `CURRENT_USER_EXECUTE_TOOL`

**The Two-Tool Pattern**:

Every sub-agent has exactly two tools that bridge to the Integration Platform:

```
CURRENT_USER_TOOLS (Discovery)
────────────────────────────────
Method: GET
URL:    /api/integrations/{tenantID}/?workflow_user_id={userID}&tool_type={type}
Auth:   Basic Auth
Input:  None (URL is pre-configured with tenant/user context)
Output: Array of OpenAPI function definitions (available tools for this user)

CURRENT_USER_EXECUTE_TOOL (Execution)
────────────────────────────────────────
Method: POST
URL:    /api/integrations/{tenantID}/execute?workflow_user_id={userID}
Auth:   Basic Auth
Input:  { "tool_id": "sharepoint_search_42", "parameters": { ... } }
Output: { "success": true, "result": { ... } }
```

**Tool loop execution**:
```
Sub-Agent receives taskDescription from Main Agent
    │
    ▼
LLM reasons about task → decides to call CURRENT_USER_TOOLS
    │
    ▼
Gets list of available tools (e.g., sharepoint_search_42, sharepoint_read_document_42)
    │
    ▼
LLM selects tool + constructs parameters
    │
    ▼
Calls CURRENT_USER_EXECUTE_TOOL with tool_id + parameters
    │
    ▼
Gets result from Integration Platform
    │
    ▼
LLM analyzes result
    ├── Need more data? → call another tool (loop back)
    └── Task complete? → return response to Main Agent
```

**Maximum iterations**: Sub-agents are configured with a max iteration limit (currently 15 in n8n) to prevent infinite tool loops.

### Multi-Agent Coordination

When a task requires multiple sub-agents, the Main Agent coordinates them **sequentially**:

```
User: "Create a Confluence page from my current Jira sprint"

Main Agent:
  1. Delegates to Jira Agent:
     taskDescription: "Get current sprint data including all tickets, story points, and status"
     → Jira Agent runs tool loop → returns sprint data

  2. Extracts relevant data from Jira Agent response

  3. Delegates to Confluence Agent:
     taskDescription: "Create page titled 'Sprint 42 Summary' with: 23 tickets, 87 points..."
     → Confluence Agent runs tool loop → returns page URL

  4. Aggregates: "✅ Retrieved Sprint 42 from Jira and created Confluence page at [URL]"
```

**Data passing strategies** (defined in main_agent.twig):
- **Text Embedding**: Simple data → embed directly in taskDescription text
- **Structured Embedding**: Complex data → summarize key fields in taskDescription
- **Reference Embedding**: Large data → pass IDs/references for next agent to fetch

### System Prompts

System prompts are the behavioral contracts for each agent. They define:

| Component | Purpose | Source |
|-----------|---------|--------|
| Main Agent prompt | Routing rules, delegation mandate, output format | `templates/skills/prompts/main_agent.twig` |
| Sub-agent prompts | Integration-specific workflows, tool usage patterns | `templates/skills/prompts/{type}_full.xml.twig` |

**Sub-agent prompt structure** (common across all 14 types):
1. **Agent identity** — role, capabilities
2. **Webhook constraint** — synchronous execution, past-tense responses
3. **Tool discovery mandate** — must call CURRENT_USER_TOOLS first
4. **Tool execution patterns** — how to use CURRENT_USER_EXECUTE_TOOL
5. **Domain-specific workflows** — JQL patterns for Jira, KQL for SharePoint, etc.
6. **Data-first mandate** — always fetch real data, never hallucinate
7. **Output format** — structured response with `output` and optional `data` fields

**Available sub-agent types**:

| Type | Prompt Template | Integration |
|------|----------------|-------------|
| `jira` | `jira_full.xml.twig` | Jira (issue tracking, sprints, boards) |
| `confluence` | `confluence_full.xml.twig` | Confluence (wiki pages, CQL search) |
| `sharepoint` | `sharepoint_full.xml.twig` | SharePoint (documents, KQL search) |
| `gitlab` | `gitlab_full.xml.twig` | GitLab (repos, merge requests, pipelines) |
| `outlook_mail` | `outlook_mail_full.xml.twig` | Outlook Mail (email search, send) |
| `outlook_calendar` | `outlook_calendar_full.xml.twig` | Outlook Calendar (events, scheduling) |
| `msteams` | `msteams_full.xml.twig` | Microsoft Teams (chat, channels) |
| `trello` | `trello_full.xml.twig` | Trello (boards, cards, lists) |
| `wrike` | `wrike_full.xml.twig` | Wrike (projects, tasks) |
| `hubspot` | `hubspot_full.xml.twig` | HubSpot (CRM, contacts, deals) |
| `sap_c4c` | `sap_c4c.xml.twig` | SAP C4C (leads, opportunities) |
| `sap_sac` | `sap_sac.xml.twig` | SAP SAC (analytics, reports) |
| `projektron` | `projektron_full.xml.twig` | Projektron (time tracking) |
| `remote_mcp` | `remote_mcp_full.xml.twig` | Remote MCP (dynamic tool discovery) |

## Channel Clients

### Microsoft Teams Bot (`workoflow-bot`)

**Tech stack**: Node.js 18+, Microsoft Bot Framework SDK v4, PM2 clustering

**Responsibilities**:
1. Receive user messages via Teams Bot Framework
2. Enrich payload with user/tenant context
3. Resolve per-tenant webhook URL
4. Forward to orchestrator and display response
5. Collect user feedback

**Enriched payload structure** (sent to orchestrator webhook):
```json
{
  "type": "message",
  "text": "Show me open Jira tickets",
  "from": {
    "id": "...",
    "name": "Patrick",
    "aadObjectId": "azure-ad-user-id"
  },
  "conversation": {
    "tenantId": "org-uuid-from-teams",
    "id": "conversation-id"
  },
  "_fileDetection": {
    "urls": [],
    "attachments": []
  },
  "custom": {
    "isThreadReply": false,
    "threadMessageId": null,
    "user": {
      "aadObjectId": "azure-ad-user-id",
      "email": "patrick@company.com",
      "name": "Patrick",
      "userPrincipalName": "patrick@company.com"
    },
    "conversationDetails": {
      "conversationType": "personal",
      "tenantId": "org-uuid",
      "isGroup": false
    },
    "session": {
      "sessionId": "uuid-v4",
      "createdAt": "2026-03-10T10:00:00Z",
      "messageCount": 1,
      "isNewSession": true
    }
  }
}
```

**Expected response format** from orchestrator:
```json
{
  "output": "✅ Found 12 open tickets in project PROJ...",
  "attachment": null
}
```

The bot parses the `output` field for display and `attachment` for file download links.

**Webhook URL resolution**:
1. Extract `tenantId` from Teams activity
2. Call `GET /api/tenant/{tenantId}/settings` to get webhook URL
3. Cache for 5 minutes
4. Fallback to `WORKOFLOW_N8N_WEBHOOK_URL` env var

**Magic link**: In personal (1:1) chats, the bot generates a magic link via `POST /api/register` so users can access the Integration Platform to manage their skills without password.

### Future Channel Clients

The orchestrator webhook interface is channel-agnostic. Additional clients (Slack, WhatsApp, web chat) forward user messages to the same orchestrator webhook with equivalent context.

**Required context fields** (minimum for orchestrator):
- `userID` — unique user identifier within the platform (workflow_user_id)
- `tenantID` — organisation UUID
- `userPrompt` — the user's message text
- `locale` — user language preference (de, en, ro, lt)
- `sessionId` — conversation session for memory scoping

## Infrastructure (`workoflow-ai-setup`)

### Service Topology

```
┌──────────────────────────────────────────────────────────────┐
│                    Docker Compose Network                      │
│                                                                │
│  ┌────────────────┐  ┌──────────────┐  ┌──────────────────┐  │
│  │ n8n (5678)      │  │ LiteLLM      │  │ Phoenix (6006)    │  │
│  │ + Worker        │  │ Proxy (4000) │  │ OTEL Collector    │  │
│  │ + 2 Runners     │  │              │  │                    │  │
│  └───────┬─────────┘  └──────┬───────┘  └────────┬──────────┘  │
│          │                   │                    │              │
│  ┌───────▼─────────┐  ┌─────▼────────┐  ┌───────▼──────────┐  │
│  │ PostgreSQL       │  │ Azure OpenAI  │  │ All services     │  │
│  │ Redis            │  │ OpenAI        │  │ send traces via  │  │
│  │ MinIO            │  │ (failover)    │  │ OTEL → Phoenix   │  │
│  └─────────────────┘  └──────────────┘  └──────────────────┘  │
│                                                                │
│  ┌────────────────┐  ┌──────────────┐  ┌──────────────────┐  │
│  │ Qdrant (6333)   │  │ SearXNG      │  │ Tika (9998)       │  │
│  │ Vector DB       │  │ (8080)       │  │ Gotenberg (3002)  │  │
│  └────────────────┘  └──────────────┘  └──────────────────┘  │
│                                                                │
│  ┌────────────────┐  ┌──────────────┐                         │
│  │ Teams Bot       │  │ Workoflow    │                         │
│  │ (3978)          │  │ MCP (9006)   │                         │
│  └────────────────┘  └──────────────┘                         │
└──────────────────────────────────────────────────────────────┘
```

### LiteLLM Proxy

**Purpose**: Centralized LLM gateway with model routing, failover, and caching.

- **Models**: gpt-4.1 (Azure OpenAI + OpenAI, load-balanced with simple-shuffle)
- **Failover**: 1 allowed failure, 60s cooldown, 3 retries
- **Caching**: Redis (1h TTL, excludes completions for privacy)
- **Observability**: Traces sent to Phoenix via OTEL
- **Endpoint**: `http://litellm:4000` (internal) or configured via environment

The orchestrator should route all LLM calls through LiteLLM to benefit from model routing, caching, rate limiting, and unified observability.

### Phoenix Observability

**Purpose**: LLM call tracing and debugging via OpenTelemetry.

- **Endpoint**: `http://phoenix:4318` (OTEL collector)
- **UI**: Port 6006
- All services should send OTEL traces to Phoenix for end-to-end visibility

### Where the New Orchestrator Fits

The new orchestrator replaces n8n in the Docker Compose stack:

```yaml
# In docker-compose.yaml, replace n8n services with:
orchestrator:
  image: workoflow-orchestrator:latest
  ports:
    - "5678:5678"  # Same port for backward compatibility
  environment:
    - LITELLM_BASE_URL=http://litellm:4000
    - PLATFORM_API_URL=http://host.docker.internal:3979
    - PLATFORM_API_USER=${API_AUTH_USER}
    - PLATFORM_API_PASSWORD=${API_AUTH_PASSWORD}
    - OTEL_EXPORTER_OTLP_ENDPOINT=http://phoenix:4318
    - REDIS_URL=redis://redis:6379
  depends_on:
    - litellm
    - redis
    - phoenix
```

## Orchestrator Interface Contract

### Inbound: Webhook Endpoint (what channel clients call)

The orchestrator must expose a webhook endpoint that channel clients POST to:

```
POST /webhook/{tenant-specific-path}
Content-Type: application/json
Authorization: Basic {credentials}  (optional, per-tenant config)

{
  "text": "Show me open Jira tickets",
  "from": { "aadObjectId": "user-uuid", "name": "Patrick" },
  "conversation": { "tenantId": "org-uuid" },
  "custom": {
    "user": { "aadObjectId": "user-uuid", "email": "..." },
    "session": { "sessionId": "uuid", "messageCount": 1 },
    "conversationDetails": { "tenantId": "org-uuid" }
  }
}
```

**Required response** (synchronous — connection closes after response):
```json
{
  "output": "✅ Found 12 open tickets...",
  "attachment": "https://platform.example.com/file/uuid/report.pdf"
}
```

### Outbound: Platform APIs (what the orchestrator calls)

| API | Method | URL | Purpose |
|-----|--------|-----|---------|
| Skills | GET | `/api/skills/?organisation_uuid={}&workflow_user_id={}&tool_type={}` | Load sub-agent system prompt |
| Tools | GET | `/api/integrations/{org-uuid}/?workflow_user_id={}&tool_type={}` | Discover available tools |
| Execute | POST | `/api/integrations/{org-uuid}/execute?workflow_user_id={}` | Execute a tool |
| LLM | POST | `http://litellm:4000/v1/chat/completions` | LLM inference |

### Orchestration Pseudocode

```python
def handle_webhook(request):
    tenant_id = request.custom.conversationDetails.tenantId
    user_id = request.custom.user.aadObjectId
    user_message = request.text
    session_id = request.custom.session.sessionId
    locale = request.custom.locale or "en"

    # 1. Load main agent system prompt
    main_prompt = load_main_agent_prompt()  # main_agent.twig (static or fetched)

    # 2. Build sub-agent tools for the main agent
    sub_agent_tools = build_sub_agent_tools(tenant_id, user_id)
    # Each tool: { name: "invoke_jira_agent", params: { taskDescription: string } }

    # 3. Run main agent LLM loop
    response = run_agent(
        system_prompt=main_prompt,
        user_message=user_message,
        tools=sub_agent_tools,
        llm_endpoint="http://litellm:4000/v1/chat/completions",
        model="gpt-4.1",
        max_iterations=20
    )

    return { "output": response.output, "attachment": response.attachment }


def invoke_sub_agent(tenant_id, user_id, tool_type, task_description):
    # 1. Load sub-agent system prompt
    skills = GET /api/skills/?organisation_uuid={tenant_id}
                              &workflow_user_id={user_id}
                              &tool_type={tool_type}
    system_prompt = skills[0].system_prompt

    # 2. Define the two bridge tools
    tools = [
        {
            name: "CURRENT_USER_TOOLS",
            description: "Discover available tools for this user",
            execute: lambda: GET /api/integrations/{tenant_id}/
                                 ?workflow_user_id={user_id}
                                 &tool_type={tool_type}
        },
        {
            name: "CURRENT_USER_EXECUTE_TOOL",
            description: "Execute a tool by tool_id with parameters",
            params: { tool_id: string, parameters: object },
            execute: lambda params: POST /api/integrations/{tenant_id}/execute
                                         ?workflow_user_id={user_id}
                                    body: { tool_id, parameters }
        }
    ]

    # 3. Run sub-agent LLM loop
    return run_agent(
        system_prompt=system_prompt,
        user_message=task_description,
        tools=tools,
        llm_endpoint="http://litellm:4000/v1/chat/completions",
        model="gpt-4.1",
        max_iterations=15
    )
```

## Design Constraints & Principles

### Synchronous Webhook Execution

The orchestrator operates in a **synchronous request-response** pattern:
- Channel client POSTs → waits for response → connection closes
- **No** follow-up messages possible after initial response
- All agent work must complete within the request lifecycle
- Responses must use **past tense** ("I have found..." not "I will find...")
- Teams bot sends typing indicators every 2.5s while waiting

### Stateless Sub-Agents

Sub-agents are **stateless per invocation**:
- Receive `taskDescription` + user context
- Run tool loop to completion
- Return result
- No memory of previous invocations

Conversation memory is managed at the **orchestrator level** (session-scoped), not within sub-agents.

### Multi-Tenant Isolation

- Every request is scoped to a `tenantID` (organisation UUID) and `userID`
- Tool discovery and execution are filtered by tenant + user
- Credentials are encrypted per-user per-organisation
- Sub-agents can only access tools the specific user has configured

### Tool Access Modes

The Integration Platform enforces three access levels per user:
- **Read Only** — only `ToolCategory::READ` tools available
- **Standard** — READ + WRITE tools available
- **Full** — READ + WRITE + DELETE tools available

The orchestrator does not need to enforce these — the platform API rejects unauthorized operations.

### LLM Model Routing

All LLM calls should go through the **LiteLLM Proxy** for:
- Model failover (Azure → OpenAI)
- Response caching
- Usage tracking per virtual key
- Unified observability via Phoenix

### Observability

The orchestrator should emit **OpenTelemetry traces** to Phoenix (`http://phoenix:4318`):
- Span per user request
- Span per agent invocation (main + each sub-agent)
- Span per LLM call
- Span per tool execution
- Include: tenant_id, user_id, session_id, agent_type, tool_id

## Scheduled Tasks

The platform supports automated/recurring agent executions:

- **Entity**: `ScheduledTask` with prompt, frequency (manual/hourly/daily/weekdays/weekly), execution time
- **Executor**: `ScheduledTaskExecutor` sends prompt to orchestrator webhook
- **Flow**: Scheduler → builds payload → POSTs to tenant's webhook URL → orchestrator processes as normal request
- The orchestrator receives scheduled tasks identically to interactive requests

## Prompt Vault

Users can create, share, and upvote reusable prompts:
- Stored in the Integration Platform
- Accessible via `GET /api/prompts` (X-Prompt-Token auth)
- Channel clients can offer prompt selection before sending to orchestrator

## Security

### Authentication Layers

| Layer | Method | Purpose |
|-------|--------|---------|
| Channel → Orchestrator | Per-tenant webhook auth (configurable) | Tenant isolation |
| Orchestrator → Platform API | Basic Auth (`API_AUTH_USER:PASSWORD`) | Service-to-service auth |
| Platform → External Services | Per-user encrypted credentials (Sodium) | User-scoped access |
| MCP Clients → Platform | X-Prompt-Token header | Personal access |
| Users → Platform Web UI | OAuth2 (Google, Azure, HubSpot, Wrike) + Magic Link | User login |

### Credential Management

- External service credentials (Jira tokens, SharePoint OAuth, etc.) are stored encrypted using **Sodium** (libsodium)
- Encryption key: 32-character `ENCRYPTION_KEY` from `.env`
- Credentials are decrypted only at execution time by the Integration Platform
- The orchestrator **never sees** user credentials — it only calls the execute API

## Related Documentation

- [Jira Integration](JIRA.md) — Jira-specific tools and configuration
- [GitLab Integration](GITLAB.md) — GitLab-specific tools and configuration
- [Trello Integration](TRELLO.md) — Trello-specific tools and configuration
- [Setup Guide](SETUP_SH.md) — Platform installation and configuration
- [Testing Guide](TESTING.md) — Testing integrations and API
