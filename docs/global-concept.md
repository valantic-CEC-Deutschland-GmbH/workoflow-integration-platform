# Workoflow Global Architecture

## Overview

Workoflow is a multi-agent AI integration platform. Users connect business tools (Jira, Confluence, SharePoint, GitLab, etc.) and interact with them through AI agents via chat interfaces (Microsoft Teams, Slack, WhatsApp).

Two tenant types:
- **n8n tenants** — legacy, use n8n workflows as orchestrator
- **COMMON tenants** — use the `workoflow-orchestrator` (Google ADK-based, Python)

This document describes the COMMON tenant architecture.

## Repositories

| Repo | Role | Tech |
|------|------|------|
| `workoflow-integration-platform` | Integration Platform — source of truth for integrations, tools, credentials, prompts | Symfony 8 / FrankenPHP |
| `workoflow-orchestrator` | AI Agent Orchestrator — Google ADK, multi-agent coordination | Python / FastAPI / Google ADK |
| `workoflow-bot` | MS Teams channel client | Node.js / Bot Framework SDK v4 |
| `workoflow-mcp` | MCP server for Claude Desktop / external MCP clients | Node.js |
| `workoflow-hosting` | Infrastructure — Docker Compose for all shared services | Docker Compose |
| `workoflow-metrics` | Grafana dashboards for usage, feedback, time saved | Grafana |

## System Components

```
┌───────────────────────────────────────────────────────────────────────────────────┐
│                              WORKOFLOW PLATFORM                                   │
│                                                                                   │
│  ┌────────────────┐   ┌────────────────────┐   ┌──────────────────────────────┐  │
│  │ Channel         │   │ AI Agent            │   │ Integration Platform          │  │
│  │ Clients         │──▶│ Orchestrator        │──▶│ (workoflow-integration-plat.) │  │
│  │                 │   │ (Google ADK)        │   │                              │  │
│  │ • Teams Bot     │   │                     │   │ • Tool Definitions           │  │
│  │ • Slack         │   │ Main Agent          │   │ • System Prompts             │  │
│  │ • WhatsApp      │   │  ├─▶ Sub-Agents     │   │ • Tool Execution             │  │
│  │ • MCP Client    │   │  └─▶ Native Agents  │   │ • Credential Management      │  │
│  └────────────────┘   └────────────────────┘   └──────────────────────────────┘  │
│                               │                             │                     │
│                      ┌────────▼─────────┐          ┌────────▼─────────┐          │
│                      │ Infrastructure    │          │ External Services │          │
│                      │ (hosting)         │          │ (Jira, GitLab…)  │          │
│                      │                   │          └──────────────────┘          │
│                      │ • LiteLLM Proxy   │                                        │
│                      │ • Phoenix         │                                        │
│                      │ • Qdrant          │                                        │
│                      │ • Redis/PG        │                                        │
│                      └───────────────────┘                                        │
└───────────────────────────────────────────────────────────────────────────────────┘
```

### 1. Integration Platform (`workoflow-integration-platform`)

- Symfony 8.0 / FrankenPHP
- Source of truth for integrations, tools, credentials, system prompts
- 14 user integrations + 13 system tools
- REST API + MCP Server for tool discovery/execution
- Per-user encrypted credentials (Sodium)
- Sub-agent system prompts via Twig templates
- Multi-tenant organisation management
- **Agent discovery**: for COMMON tenants, fetches `GET /api/capabilities` from orchestrator to discover native agents. Users enable/disable them on the skills edit page.

### 2. AI Agent Orchestrator (`workoflow-orchestrator`)

- **Framework**: Google ADK (Agent Development Kit) on Python/FastAPI
- **LLM**: GPT-5.4 via LiteLLM proxy (with fallback to GPT-4.1)
- **Pattern**: Main Agent (coordinator) delegates to Sub-Agents
- Two kinds of sub-agents:
  - **Platform-proxied agents** — created dynamically per user's enabled integrations, use the two-tool pattern (discover + execute) against the platform API
  - **Native agents** — self-contained agents with their own tools, registered via `NativeAgentRegistry` plugin pattern (e.g. People Finder)
- Conversation history: Redis-backed, 30-day TTL, thread-based via `conversationId`
- Streaming: SSE support (`/webhook/stream`)
- Webhook auth: Bearer token (`WEBHOOK_AUTH_TOKEN`)

### 3. Channel Clients (`workoflow-bot`)

- MS Teams Bot (Node.js, Bot Framework SDK v4, PM2 clustering)
- Enriches messages with user/tenant context
- Resolves per-tenant webhook URL
- Thread-based conversation tracking with Redis session mapping
- Displays AI responses with attachments and feedback collection

### 4. Infrastructure (`workoflow-hosting`)

- LiteLLM Proxy — model routing (GPT-5.4 via OpenAI, GPT-4.1 via Azure+OpenAI, text-embedding-3-large), failover, Redis caching
- Phoenix — OpenTelemetry observability for LLM calls
- Qdrant — vector database (employees collection for People Finder)
- SearXNG, Tika, Gotenberg — web search, document extraction, PDF conversion
- PostgreSQL, Redis, MinIO

## Agent Discovery & Creation Flow

For COMMON tenants, agent availability is a two-way handshake:

```
Orchestrator                          Platform
    │                                     │
    │  GET /api/capabilities              │
    │◀────────────────────────────────────│  (platform fetches at admin time)
    │  {agents: [{type, name, tools}]}    │
    │────────────────────────────────────▶│
    │                                     │  Admin enables/disables agents
    │                                     │  Stores as IntegrationConfig
    │                                     │
    │  (user sends message)               │
    │                                     │
    │  GET /api/skills/?org=X&user=Y      │
    │────────────────────────────────────▶│  (orchestrator at request time)
    │  {skills: [...enabled agents...]}   │
    │◀────────────────────────────────────│
    │                                     │
    │  create_sub_agents() builds:        │
    │  - Native agents (from registry)    │
    │  - Platform-proxied agents          │
    │    (with discover+execute tools)    │
```

Key points:
- Platform calls `GET /api/capabilities` to learn what the orchestrator offers
- At request time, orchestrator calls `GET /api/skills/` to get only the user's **enabled** agents
- Skills with type `orchestrator.*` are routed to `NativeAgentRegistry`
- All others get the standard two-tool pattern (CURRENT_USER_TOOLS + CURRENT_USER_EXECUTE_TOOL)

## NativeAgentRegistry — Adding a New Agent

Agents self-register at import time. No modification of existing code needed.

**Steps to add a new native agent:**

1. Create `src/agents/my_agent.py`:
```python
from src.agents.registry import NativeAgentRegistry
from src.agents.model import get_litellm_model

def _create_my_agent() -> LlmAgent:
    model = get_litellm_model(reasoning_effort="medium")
    return LlmAgent(
        name="my_agent",
        model=model,
        instruction=MY_PROMPT,
        description="...",
        tools=[...],
    )

# Self-register at import time
NativeAgentRegistry.instance().register(
    "orchestrator.my_agent",
    _create_my_agent,
)
```

2. Add import in `src/agents/capabilities.py` and `src/agents/sub_agent_factory.py`:
```python
import src.agents.my_agent  # noqa: F401
```

3. Add metadata in `capabilities.py` `_AGENT_METADATA` dict (name, description, tools list)

4. Create an `IntegrationConfig` in the platform with `integrationType = "orchestrator.my_agent"`

**Registry internals** (`src/agents/registry.py`):
- Singleton `NativeAgentRegistry` holds `dict[str, Callable[[], LlmAgent]]`
- `register(type, builder)` — stores builder
- `build(type)` — calls builder, returns agent
- `has(type)` — checks if registered
- `registered_types()` — lists all types

## People Finder — First Native Agent

- Type: `orchestrator.people_finder`
- Searches employee profiles by skills, experience, availability, roles, languages, certifications
- Data source: Decidalo API, scraped and indexed into Qdrant

**Search architecture:**
```
User query
    │
    ├─▶ Dense embedding (text-embedding-3-large, 3072 dims)
    └─▶ BM25 sparse embedding
          │
          ▼
    Qdrant hybrid query (RRF fusion)
    prefetch 50 per vector type
          │
          ▼
    Composite reranking (application-level)
          │
          ▼
    Top N results returned
```

**Code structure:**
- `src/search/base.py` — generic `BaseDocument`, `SearchResult`, `SearchQuery`
- `src/search/people/` — people-specific models, hybrid search, scoring
- `src/scraper/` — Decidalo client, mapper, Qdrant indexer (CLI: `python -m src.scraper`)
- `src/tools/employee_tools.py` — `employee_search` + `employee_profile` FunctionTools
- `src/agents/people_finder.py` — prompt, builder, self-registration

The search base in `src/search/base.py` is generic and reusable for future collections.

## Multi-Agent Orchestration

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     ORCHESTRATOR (Google ADK)                     │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ MAIN AGENT (Router/Coordinator)                           │   │
│  │                                                            │   │
│  │ LLM: GPT-5.4 (reasoning_effort=low, 4096 tokens)         │   │
│  │ Instruction: fetched from platform or hardcoded fallback  │   │
│  │                                                            │   │
│  │ Sub-agents registered as ADK sub_agents:                  │   │
│  │   ├── jira_agent_{configId}                               │   │
│  │   ├── confluence_agent_{configId}                         │   │
│  │   ├── sharepoint_agent_{configId}                         │   │
│  │   ├── ... (all platform-proxied integrations)             │   │
│  │   └── people_finder_agent  (native)                       │   │
│  └───────────────────────┬──────────────────────────────────┘   │
│                          │ ADK delegation                        │
│                          ▼                                       │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ SUB-AGENT (platform-proxied)                              │   │
│  │                                                            │   │
│  │ LLM: GPT-5.4 (reasoning_effort=medium, 16384 tokens)     │   │
│  │ Instruction: loaded from /api/skills/?tool_type=X         │   │
│  │                                                            │   │
│  │ Tools:                                                     │   │
│  │   ├── current_user_tools     → GET  /api/integrations/…   │   │
│  │   └── current_user_execute_tool → POST /api/integrations/…│   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │ NATIVE AGENT (e.g. People Finder)                         │   │
│  │                                                            │   │
│  │ LLM: GPT-5.4 (reasoning_effort=medium, 16384 tokens)     │   │
│  │ Instruction: hardcoded in agent module                     │   │
│  │                                                            │   │
│  │ Tools: agent-specific (employee_search, employee_profile) │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### Main Agent

- Entry point for all user requests
- Receives user message with context (tenant ID, user ID, conversation ID, locale)
- Routes to sub-agents via ADK's built-in delegation (sub-agents registered as `sub_agents`)
- Conversation history injected as real ADK events (last 10 turns from Redis)
- System prompt: fetched via `fetch_main_prompt()` from platform API, with hardcoded fallback

### Sub-Agent Pattern (Platform-Proxied)

Each platform-proxied sub-agent has exactly two tools:

```
current_user_tools (Discovery)
───────────────────────────────
GET /api/integrations/{orgUUID}/?workflow_user_id={userID}&tool_type={type}
→ Returns OpenAPI-style tool definitions

current_user_execute_tool (Execution)
──────────────────────────────────────
POST /api/integrations/{orgUUID}/execute?workflow_user_id={userID}
Body: {"tool_id": "sharepoint_search_42", "parameters": {...}}
→ Returns {"success": true, "result": {...}}
```

Tool loop: discover → select tool → execute → analyze → repeat or return.

### Conversation History

- Stored in Redis per `(org_uuid, user_id, conversation_id)`
- 30-day TTL
- Thread-based: `conversationId` from bot maps to Redis key
- Injected into ADK session as real `Event` objects (not prompt text)
- Last 10 turns (20 messages) included

## Orchestrator API Contract

### Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/` | GET | Service info |
| `/health` | GET | Health check |
| `/api/capabilities` | GET | Advertise native agents to platform |
| `/webhook` | POST | Process message (sync JSON) |
| `/webhook/stream` | POST | Process message (SSE streaming) |

### Webhook Request

```json
{
  "text": "Find me a Java developer",
  "locale": "de-DE",
  "from": { "aadObjectId": "user-uuid", "name": "Patrick" },
  "conversation": { "tenantId": "org-uuid" },
  "custom": {
    "conversationId": "conv-uuid",
    "isThreadReply": false,
    "session": { "sessionId": "sess-uuid", "messageCount": 1, "isNewSession": true },
    "user": { "aadObjectId": "user-uuid", "email": "jane@example.com" }
  }
}
```

Auth: Bearer token (`WEBHOOK_AUTH_TOKEN` env var). Empty = no auth.

### Webhook Response (sync)

```json
{
  "output": "I found 3 Java developers...",
  "attachment": null,
  "conversationId": "conv-uuid"
}
```

### SSE Stream Events

| Event | Data | Purpose |
|-------|------|---------|
| `status` | `{"type":"informative","message":"..."}` | Processing started |
| `chunk` | `{"text":"partial text"}` | Token-by-token streaming |
| `done` | `{"type":"final","output":"...","attachment":null,"conversationId":"..."}` | Complete response |

### Capabilities Response

```json
{
  "agents": [
    {
      "type": "orchestrator.people_finder",
      "name": "People Finder",
      "description": "Search and find employee profiles...",
      "tools": [
        {"name": "employee_search", "description": "..."},
        {"name": "employee_profile", "description": "..."}
      ]
    }
  ]
}
```

## Integration Platform APIs

All endpoints use **Basic Auth** (`WORKOFLOW_API_USER:WORKOFLOW_API_PASSWORD`).

### Skills API — System Prompts

```
GET /api/skills/?organisation_uuid={uuid}&workflow_user_id={id}&tool_type={type}&execution_id={id}
```

Response:
```json
{
  "skills": [
    {
      "type": "sharepoint",
      "name": "SharePoint",
      "instance_id": 42,
      "instance_name": "Company SharePoint",
      "system_prompt": "<?xml ...>"
    }
  ]
}
```

- `tool_type` is CSV-format: `jira,confluence` or single
- `workflow_user_id` required for user integrations (security)
- Skills with `type` starting with `orchestrator.` are native agents — they have no `system_prompt`, handled by the registry
- System integrations excluded by default, included with `tool_type=system`

### Tools API — Tool Discovery

```
GET /api/integrations/{org-uuid}/?workflow_user_id={id}&tool_type={type}&execution_id={id}
```

Returns OpenAPI function-call format tool definitions.

Tool naming: `{type}_{toolName}_{configId}` (user), `{type}_{toolName}` (system), `{toolName}_{configId}` (MCP).

### Execute API — Tool Execution

```
POST /api/integrations/{org-uuid}/execute?workflow_user_id={id}&execution_id={id}
Body: {"tool_id": "sharepoint_search_42", "parameters": {...}}
```

- Decrypts credentials at execution time
- Enforces Tool Access Modes (Read Only / Standard / Full)
- Audit logs execution

### MCP Server API

```
GET  /api/mcp/tools      (X-Prompt-Token header)
POST /api/mcp/execute     (X-Prompt-Token header)
```

Auth via `X-Prompt-Token` (maps to `UserOrganisation.personalAccessToken`).

### Tenant Settings API

```
GET /api/tenant/{org-uuid}/settings
```

Returns webhook URL and auth config for the tenant's orchestrator endpoint.

### User Registration API

```
POST /api/register
Payload: { name, org_uuid, workflow_user_id, email, channel_info }
Response: { magic_link, user_id, email, organisation }
```

## Infrastructure (`workoflow-hosting`)

### Docker Compose Services

| Service | Port | Purpose |
|---------|------|---------|
| `adk-orchestrator` | 8080 | ADK-based agent orchestrator |
| `n8n` + worker + runners | 5678 | Legacy orchestrator (n8n tenants) |
| `workoflow-teams-bot` | 3978 | MS Teams bot |
| `workoflow-mcp` | 9006 | MCP server |
| `litellm` | 4000 | LLM gateway/proxy |
| `phoenix` | 6006 | OTEL observability |
| `qdrant` | 6333 | Vector database |
| `searxng` | 8090 | Web search |
| `tika` | 9998 | Document extraction |
| `gotenberg` | 3002 | PDF conversion |
| `postgres` | 5432 | Main database |
| `redis` | 6381 | Session/cache |
| `minio` | 9000 | Object storage |

### LiteLLM Model Routing

```yaml
Models:
  gpt-5.4:    OpenAI only (primary model for orchestrator)
  gpt-4.1:    Azure OpenAI + OpenAI (load-balanced, simple-shuffle)
  text-embedding-3-large: OpenAI (People Finder embeddings)

Failover: gpt-5.4 → gpt-4.1
Retries: 3, cooldown 60s after 1 failure
Cache: Redis (1h TTL, excludes completions)
Observability: Phoenix via OTEL
```

### Orchestrator Config

Key environment variables for `adk-orchestrator`:
- `LITELLM_MODEL=gpt-5.4` — primary LLM
- `LITELLM_BASE_URL=http://litellm:4000` — LLM proxy
- `WORKOFLOW_API_URL` / `WORKOFLOW_API_USER` / `WORKOFLOW_API_PASSWORD` — platform API
- `WEBHOOK_AUTH_TOKEN` — bearer auth for inbound webhooks
- `QDRANT_URL=http://qdrant:6333` — vector DB
- `EMBEDDING_MODEL=text-embedding-3-large` — embedding model
- `DECIDALO_BEARER_TOKEN` — scraper data source
- `REDIS_URL` — session storage
- `AGENT_TIMEOUT_SECONDS=120` — request timeout
- `AGENT_MAX_TOOL_CALLS=10` — safety limit per execution

## Design Constraints

### Synchronous Webhook Execution
- Channel client POSTs → waits → connection closes
- No follow-up messages possible
- Responses use past tense
- Timeout: 120s default
- SSE streaming available via `/webhook/stream`

### Stateless Sub-Agents
- Receive task via ADK delegation + user context
- Run tool loop to completion
- No memory of previous invocations
- Conversation memory managed at orchestrator level (Redis)

### Multi-Tenant Isolation
- Every request scoped to `tenantID` + `userID`
- Tool discovery/execution filtered by tenant + user
- Credentials encrypted per-user per-organisation (Sodium)
- Orchestrator never sees user credentials

### Tool Access Modes
- Read Only / Standard / Full — enforced by the platform API
- Orchestrator does not need to enforce these

## Security

| Layer | Method | Purpose |
|-------|--------|---------|
| Channel → Orchestrator | Bearer token (`WEBHOOK_AUTH_TOKEN`) | Webhook auth |
| Orchestrator → Platform API | Basic Auth (`WORKOFLOW_API_USER:PASSWORD`) | Service-to-service |
| Platform → External Services | Per-user encrypted credentials (Sodium) | User-scoped access |
| MCP Clients → Platform | X-Prompt-Token header | Personal access |
| Users → Platform Web UI | OAuth2 (Google, Azure, HubSpot, Wrike) + Magic Link | User login |

## Scheduled Tasks

- `ScheduledTask` entity with prompt, frequency (manual/hourly/daily/weekdays/weekly)
- `ScheduledTaskExecutor` sends prompt to orchestrator webhook
- Orchestrator processes them identically to interactive requests

## Prompt Vault

- Reusable prompts: create, share, upvote
- `GET /api/prompts` (X-Prompt-Token auth)
- Channel clients can offer prompt selection

## Related Documentation

- [Jira Integration](JIRA.md)
- [GitLab Integration](GITLAB.md)
- [Trello Integration](TRELLO.md)
- [Setup Guide](SETUP_SH.md)
- [Testing Guide](TESTING.md)
