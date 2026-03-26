# Claude Code Prompt: Build Google ADK Orchestrator for Workoflow

## Mission

Replace the n8n workflow engine with a **Google ADK (Agent Development Kit) Python** application as the new AI agent orchestrator for the Workoflow Integration Platform. This is a **code-first, production-ready** replacement that must be fully compatible with the existing ecosystem.

---

## Claude Code Execution Model (CRITICAL)

**YOU (the main Claude Code agent) are the COORDINATOR ONLY.** You must NOT write implementation code yourself. Your context window is limited — use it for orchestration, not implementation.

### Your Role as Coordinator:
1. **Read and understand** this prompt and the referenced docs
2. **Spawn sub-agents** (using the Agent tool) for ALL implementation work
3. **Delegate clearly** — give each sub-agent a focused task with all necessary context
4. **Review results** — check sub-agent outputs for correctness and alignment
5. **Iterate** — if a sub-agent's work is incomplete or wrong, resume it or spawn a new one
6. **Spawn additional specialized agents** as needed (e.g., a testing agent, a Docker agent, a docs agent)

### Rules:
- **NEVER write code directly** in the main conversation — always delegate to a sub-agent
- **NEVER read large files** yourself — delegate file reading and analysis to sub-agents
- **DO** maintain a TodoList tracking overall progress across all agents
- **DO** pass context between agents (e.g., share Backend Dev's architecture decisions with Frontend Dev)
- **DO** spawn agents in parallel when their tasks are independent
- **DO** use `isolation: "worktree"` for agents that write code, so they work on isolated branches
- **DO** spawn new specialized agents if the three core agents aren't enough (e.g., a dedicated "Testing Agent" or "Docker Agent")

### Agent Communication Pattern:
```
You (Coordinator)
  ├── Spawn Team Lead agent → gets back architecture plan
  ├── Share plan with Backend Dev agent → gets back implementation
  ├── Share plan with Frontend Dev agent → gets back bot compatibility analysis
  ├── Resume Team Lead agent → reviews Backend Dev's work
  ├── Spawn Testing Agent (if needed) → validates against test scenarios
  ├── Resume Backend Dev agent → fixes issues found by Team Lead
  └── Final verification → spawn a fresh agent to do end-to-end review
```

---

## Agent Team Structure

Spawn **three collaborating AI agents** (minimum). They should communicate through you, challenge each other's decisions, and only stop when the work is production-ready. **You may spawn additional specialized agents at any time.**

### Agent 1: AI Agent Team Lead
- **Role**: Conceptual oversight, architecture validation, quality gate
- **Spawn as**: Research/planning agent (no code writing, reads docs and produces architecture plan)
- **Responsibilities**:
  - Reads ALL reference codebases and docs first (workoflow-integration-platform, workoflow-hosting, workoflow-bot, workoflow-tests)
  - Produces a concrete architecture document with module design, data flow, and interface contracts
  - Reviews every design decision against SOLID principles and production readiness
  - Validates that the new orchestrator is **observable, correct, and produces valid answers**
  - Compares design against the existing n8n solution (documented below) and identifies improvements
  - If any agent makes a suboptimal decision, pushes back with reasoning and alternatives
  - Ensures the final system handles all 22 real test scenarios (documented below)
  - Final sign-off: the orchestrator must be **better than n8n** in correctness, observability, and maintainability
  - **Can be resumed** multiple times for review rounds

### Agent 2: AI Agent Backend Developer
- **Role**: Builds the Google ADK Python application
- **Spawn as**: Implementation agent (writes all Python code, Dockerfile, configs)
- **Responsibilities**:
  - Researches Google ADK Python docs using Context7 MCP (`resolve-library-id` for `google-adk`) and web search
  - Designs and implements the multi-agent orchestrator using correct ADK patterns
  - Applies Clean Code and SOLID principles throughout
  - Creates Dockerfile and integration configs for workoflow-hosting
  - Implements OpenTelemetry tracing
  - Writes proper error handling, logging, and health checks
  - May clone boilerplate repos if useful (e.g., `huang06/google-adk-with-litellm`)

### Agent 3: AI Agent Frontend Developer
- **Role**: Ensures bot compatibility and plans streaming upgrade
- **Spawn as**: Research/analysis agent (reads bot code, produces compatibility spec and required changes)
- **Responsibilities**:
  - Understands the workoflow-bot MS Teams codebase (see Bot Architecture below)
  - Defines the exact payload contract between bot → orchestrator → bot
  - Ensures both n8n and the new ADK orchestrator are supported (bot dispatches based on `webhook_type`)
  - **Implements MS Teams streaming** in workoflow-bot (see Streaming section for full spec)
  - Aligns with Backend Developer on request/response format AND SSE event format
  - Produces a concrete, PR-ready diff for bot.js with streaming support + sync fallback
  - Documents any required bot changes (minimal for sync compatibility — more substantial for streaming)

### Additional Agents (Spawn As Needed)
The coordinator should spawn additional specialized agents when the workload demands it. Examples:

- **Testing Agent**: Validates the orchestrator against the 22 workoflow-tests scenarios, writes pytest tests
- **Docker Agent**: Builds and validates the Dockerfile, tests the docker-compose integration
- **Documentation Agent**: Writes README, API docs, and architecture decision records
- **Evaluation Agent**: Creates ADK evaluation suites for response quality and correctness
- **Refactoring Agent**: Reviews completed code for SOLID violations, duplication, and cleanup

These are suggestions — the coordinator decides what's needed based on progress.

---

## Research Requirements (Do This First)

Before writing any code, agents must research:

1. **Google ADK Python** — Use Context7 MCP to get latest docs:
   ```
   resolve-library-id("google-adk")
   get-library-docs(context7CompatibleLibraryID, topic="multi-agents")
   get-library-docs(context7CompatibleLibraryID, topic="litellm")
   get-library-docs(context7CompatibleLibraryID, topic="tools")
   get-library-docs(context7CompatibleLibraryID, topic="session")
   get-library-docs(context7CompatibleLibraryID, topic="api-server")
   ```

2. **ADK Multi-Agent Patterns** — Web search/fetch these:
   - https://google.github.io/adk-docs/agents/multi-agents/
   - https://google.github.io/adk-docs/agents/models/litellm/
   - https://google.github.io/adk-docs/runtime/api-server/
   - https://google.github.io/adk-docs/sessions/
   - https://google.github.io/adk-docs/tools-custom/function-tools/
   - https://developers.googleblog.com/developers-guide-to-multi-agent-patterns-in-adk/

3. **Existing Codebase** — Read these files:
   - `/workoflow-integration-platform/docs/global-concept.md` — Full system architecture
   - `/workoflow-integration-platform/templates/skills/prompts/` — All system prompts (main_agent.twig + sub-agent XML templates)
   - `/workoflow-integration-platform/src/Integration/` — All 27 integrations
   - `/workoflow-integration-platform/src/Controller/TenantApiController.php` — Tenant settings API
   - `/workoflow-integration-platform/src/Controller/IntegrationApiController.php` — Tool discovery & execution API

4. **workoflow-hosting** — Clone and read:
   - `https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-hosting`
   - Read `docker-compose.yaml` to understand all 14 services
   - Read `.env.dev` and `.env.prod` for configuration patterns
   - Read `litellm_config.yaml` for LLM routing setup

5. **workoflow-bot** — Clone and read:
   - `https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-bot`
   - Read `bot.js` — main message handler, payload enrichment, response parsing
   - Read `tenant-settings.js` — webhook URL resolution
   - Read `.env.dist` — all configuration options

6. **workoflow-tests** — Clone and read:
   - `https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-tests`
   - Read ALL test scenarios (22 tests across 8 integration categories)
   - These are the acceptance criteria for the new orchestrator

---

## Architecture Overview

### Current Flow (n8n — being replaced)
```
User → MS Teams Bot → POST webhook_url → n8n Main Agent
  → n8n loads system prompt
  → LLM decides which sub-agent(s) to invoke
  → Sub-agent loads its system prompt via GET /api/skills/
  → Sub-agent discovers tools via GET /api/integrations/{org}/
  → Sub-agent executes tools via POST /api/integrations/{org}/execute
  → Results flow back to Main Agent
  → Main Agent aggregates and returns {output, attachment}
→ Bot displays response to user
```

### New Flow (Google ADK — to be built)
```
User → MS Teams Bot → POST webhook_url → ADK Orchestrator (FastAPI/HTTP)
  → Main Agent (LlmAgent) receives enriched payload
  → Main Agent uses ParallelAgent or sequential delegation to sub-agents
  → Each sub-agent:
    1. Loads system prompt from /api/skills/ endpoint
    2. Discovers tools dynamically from /api/integrations/{org}/
    3. Executes tools via /api/integrations/{org}/execute
    4. Returns structured result
  → Main Agent aggregates results
  → Returns {output, attachment} JSON
→ Bot displays response (with SSE streaming support)
```

### Key Architecture Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| LLM Provider | Azure OpenAI via LiteLLM Proxy | Data privacy — no permission for Google LLMs |
| ADK Model Config | `LiteLlm` class pointing to `http://litellm:4000` | ADK natively supports LiteLLM integration |
| Agent Pattern | Coordinator/Dispatcher with ParallelAgent | Main agent routes to specialists, can run multiple in parallel |
| HTTP Framework | ADK's built-in `adk api_server` or FastAPI wrapper | Exposes webhook endpoint for bot |
| Session Storage | Redis (`redis:6379`) for sessions, PostgreSQL for long-term memory | Both available in hosting stack |
| Observability | OpenTelemetry → Phoenix (`http://phoenix:4318`) | Existing infrastructure, plus ADK's native tracing |
| System Tools | **Excluded** from this orchestrator | Remain API-only via platform, simplifies scope |
| Streaming | Implement in v1 — both orchestrator SSE + bot streaming | MS Teams supports it without admin changes |
| Deployment | Dockerfile → Docker Hub → docker-compose in workoflow-hosting | Standard CI/CD pipeline |

---

## Workoflow Platform API (The Orchestrator's Interface)

The ADK orchestrator communicates with the Workoflow Integration Platform via these REST APIs. All use HTTP Basic Auth (`API_AUTH_USER:API_AUTH_PASSWORD`).

### 1. Load Sub-Agent System Prompts
```
GET /api/skills/?organisation_uuid={uuid}&workflow_user_id={user_id}&tool_type={type}&execution_id={exec_id}

Response:
{
  "skills": [
    {
      "type": "jira",
      "name": "Jira",
      "instance_id": 42,
      "instance_name": "Company Jira",
      "system_prompt": "<?xml>...[full XML system prompt with instructions]..."
    }
  ]
}
```
- `tool_type` filters by integration (jira, confluence, sharepoint, gitlab, trello, etc.)
- Each skill returns a rich system prompt that instructs the sub-agent how to behave
- Multiple instances possible per type (e.g., two Jira configs)

### 2. Discover Available Tools
```
GET /api/integrations/{org-uuid}/?workflow_user_id={user_id}&tool_type={type}&execution_id={exec_id}

Response:
{
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "jira_search_42",
        "description": "Search Jira issues using JQL...",
        "parameters": {
          "type": "object",
          "properties": { "query": {"type": "string"}, ... },
          "required": ["query"]
        }
      }
    }
  ]
}
```
- Tool names follow pattern: `{type}_{toolName}_{configId}` (e.g., `jira_search_42`)
- Returns OpenAI-compatible function calling schema
- Only returns tools the user has enabled and their access mode allows

### 3. Execute a Tool
```
POST /api/integrations/{org-uuid}/execute?workflow_user_id={user_id}&execution_id={exec_id}

Request: {"tool_id": "jira_search_42", "parameters": {"query": "sprint = 'Sprint 42'"}}
Response: {"success": true, "result": { ... }}
```
- The platform handles credential decryption, API calls, and access mode enforcement
- Orchestrator never sees user credentials

### 4. Tenant Settings (Bot calls this, not orchestrator — for reference)
```
GET /api/tenant/{org-uuid}/settings

Response:
{
  "settings": {
    "webhook_url": "http://adk-orchestrator:8080/webhook",
    "webhook_type": "COMMON",    // "COMMON" = new ADK orchestrator, "N8N" = legacy
    "webhook_auth_header": "Bearer xyz...",
    "tenant_type": "ms_teams",
    "uuid": "org-uuid-here"
  }
}
```

---

## Webhook Contract (Bot ↔ Orchestrator)

### Incoming Payload (from MS Teams Bot)
```json
{
  "type": "message",
  "text": "User's message in natural language",
  "timestamp": "2026-03-10T10:00:00.000Z",
  "channelId": "msteams",
  "from": {
    "id": "29:user-azure-id",
    "name": "Patrick Schönfeld",
    "aadObjectId": "user-uuid-for-workflow-user-id"
  },
  "conversation": {
    "tenantId": "organisation-uuid",
    "conversationType": "personal",
    "id": "conversation-id"
  },
  "custom": {
    "isThreadReply": false,
    "threadMessageId": null,
    "user": {
      "aadObjectId": "user-uuid",
      "email": "user@company.com",
      "name": "Patrick Schönfeld",
      "tenantId": "organisation-uuid"
    },
    "session": {
      "sessionId": "random-uuid",
      "createdAt": "2026-03-10T10:00:00Z",
      "messageCount": 5,
      "isNewSession": false
    }
  }
}
```

**Critical fields for the orchestrator:**
- `text` — the user's prompt
- `conversation.tenantId` — the organisation UUID (for API calls)
- `from.aadObjectId` or `custom.user.aadObjectId` — the workflow_user_id (for API calls)
- `custom.session.sessionId` — for conversation memory
- `custom.session.isNewSession` — reset memory if true
- `custom.user.email` — user identity context
- `custom.isThreadReply` + `custom.threadMessageId` — thread context

### Expected Response (to Bot)
```json
{
  "output": "I retrieved your current sprint tickets. You have 5 open tickets: ...",
  "attachment": "https://platform.example.com/file/uuid/report.pdf"
}
```

**Rules:**
- `output` — always present, string, user-facing response text
- `attachment` — nullable, URL to downloadable file (PDF, PPTX, etc.)
- **Past tense only** — never "I will..." or "Please wait...", always "I retrieved...", "I created..."
- **Synchronous** — all work must complete before response is sent
- Response must be valid JSON

---

## Multi-Agent Design

### Main Agent (Coordinator/Dispatcher)
- **Type**: `LlmAgent` (ADK)
- **Model**: `litellm/gpt-4.1` via LiteLLM proxy at `http://litellm:4000`
- **System Prompt**: Loaded from `/api/skills/` with no `tool_type` filter, or use a static coordinator prompt based on the existing `main_agent.twig` template
- **Behavior**:
  1. Receives user prompt + tenant context
  2. Determines which sub-agent(s) are needed
  3. Can run sub-agents in **parallel** (ParallelAgent) when tasks are independent
  4. Runs sub-agents **sequentially** when output of one feeds into another
  5. Aggregates results and formats final response
  6. Returns `{output, attachment}` JSON

### Sub-Agents (One per Integration Type)
- **Type**: `LlmAgent` (ADK) — dynamically created based on user's enabled integrations
- **System Prompt**: Loaded dynamically from `/api/skills/?tool_type={type}`
- **Tools**: Two custom function tools per sub-agent:
  1. `discover_tools(tool_type)` → calls GET `/api/integrations/{org}/`
  2. `execute_tool(tool_id, parameters)` → calls POST `/api/integrations/{org}/execute`
- **Behavior**:
  1. Receives task description from Main Agent
  2. Calls `discover_tools` to learn available tools
  3. LLM selects appropriate tool(s) and parameters
  4. Calls `execute_tool` with selected tool
  5. Analyzes results, may call more tools
  6. Returns structured result to Main Agent

### Dynamic Agent Creation
Sub-agents should be created **dynamically per request** based on the user's enabled integrations:
```python
# Pseudocode — actual implementation uses ADK patterns
async def create_sub_agents(org_uuid, workflow_user_id):
    skills = await fetch_skills(org_uuid, workflow_user_id)
    agents = []
    for skill in skills:
        agent = LlmAgent(
            name=f"{skill['type']}_agent",
            model=litellm_model,
            instruction=skill['system_prompt'],
            tools=[discover_tools_fn, execute_tool_fn],
        )
        agents.append(agent)
    return agents
```

### Parallel Execution
When the Main Agent identifies independent tasks (e.g., "Get Jira sprint AND search SharePoint"), it should spawn sub-agents concurrently:
```
User: "Get my sprint tickets and find the API docs in SharePoint"
  → Main Agent detects: 2 independent tasks
  → ParallelAgent runs: [JiraAgent, SharePointAgent] concurrently
  → Main Agent aggregates both results
  → Returns combined response
```

### Sequential Execution with Data Passing
When tasks depend on each other (e.g., "Get sprint data and create a Confluence page about it"):
```
User: "Create a Confluence page with my current sprint status"
  → Main Agent detects: sequential dependency
  → Step 1: JiraAgent gets sprint data → result stored in session state
  → Step 2: ConfluenceAgent reads sprint data from state, creates page
  → Main Agent returns combined result with Confluence page URL
```

---

## Critical Constraints

1. **No Google LLMs** — All LLM calls go through LiteLLM proxy to Azure OpenAI. Configure ADK with `LiteLlm` model class.

2. **System Tools Excluded** — Do not implement system tools (WebSearch, PDF Generator, ShareFile, etc.) in this orchestrator. They remain platform API-only.

3. **User Integrations Only** — The orchestrator handles these 14 integration types: Jira, Confluence, SharePoint, GitLab, Trello, Wrike, HubSpot, SAP C4C, SAP SAC, Projektron, Outlook Mail, Outlook Calendar, MS Teams, Remote MCP.

4. **Dual Webhook Modes** — The orchestrator must expose both `/webhook` (synchronous, full response) and `/webhook/stream` (SSE streaming). The bot chooses which to call based on capability.

5. **Past Tense Responses** — The orchestrator is a synchronous webhook. All responses must use past tense ("I found...", "I created..."). Never future tense.

6. **Data Privacy** — No user credentials pass through the orchestrator. The platform API handles all credential management.

7. **Multi-Tenant Isolation** — Every request is scoped to `org_uuid` + `workflow_user_id`. Never leak data across tenants.

8. **Delegation Mandates** (from existing main_agent.twig):
   - WRITE operations: Always delegate to sub-agent (never answer from memory)
   - READ on specific resources: Always delegate
   - META-questions ("What can you do?"): Handle directly without listing all integrations

9. **German Language Support** — Most users write in German (de-DE). The orchestrator must handle German prompts correctly and respond in the user's language.

---

## Session & Memory Design

### Per-Request Context
```python
# Extract from incoming webhook payload
org_uuid = payload["conversation"]["tenantId"]
workflow_user_id = payload["from"]["aadObjectId"]  # or custom.user.aadObjectId
session_id = payload["custom"]["session"]["sessionId"]
is_new_session = payload["custom"]["session"]["isNewSession"]
user_message = payload["text"]
```

### ADK Session Management
- Use ADK's `SessionService` with a custom backend (Redis or PostgreSQL)
- Session keyed by: `{org_uuid}:{workflow_user_id}:{session_id}`
- If `isNewSession == true`, create fresh session (clear history)
- Store conversation history in session for multi-turn context
- Use `state` for inter-agent data passing within a single request

### Memory Architecture
- **Short-term** (Redis): Current session state, conversation history (TTL: 24 hours)
- **Long-term** (PostgreSQL): Cross-session memory if needed (optional, v2)

---

## Observability & Tracing

### OpenTelemetry Integration
- ADK supports OpenTelemetry natively (since v1.17.0)
- Send traces to Phoenix: `OTEL_EXPORTER_OTLP_ENDPOINT=http://phoenix:4318`
- Service name: `OTEL_SERVICE_NAME=workoflow-adk-orchestrator`

### What to Trace
- Every incoming webhook request (span: `webhook.request`)
- Main Agent reasoning (span: `main_agent.process`)
- Each sub-agent invocation (span: `sub_agent.{type}.process`)
- Every tool discovery call (span: `tool.discover.{type}`)
- Every tool execution call (span: `tool.execute.{tool_id}`)
- LLM calls (auto-traced by ADK + LiteLLM)
- Response generation (span: `response.format`)

### Logging
- Structured JSON logging
- Log levels: DEBUG for development, INFO for production
- Include `org_uuid`, `workflow_user_id`, `execution_id` in every log line

### Evaluation (Optional Enhancement)
- Create an evaluation agent that validates orchestrator responses against expected patterns
- Use the 22 test scenarios from workoflow-tests as evaluation dataset
- Track: response correctness, latency, tool call efficiency, error rate

---

## MS Teams Streaming (Implement in v1)

**Key Finding**: MS Teams Bot Framework supports streaming WITHOUT admin.teams.com changes.

### How MS Teams Streaming Works
- Three message types: `informative` (progress bar) → `streaming` (incremental text) → `final` (complete message)
- Informative messages show blue progress bar (max 1KB)
- Streaming messages reveal text progressively
- No Azure AD admin configuration needed — this is a Bot Framework SDK feature

### Orchestrator Side (Backend Dev's Responsibility)
The ADK orchestrator must expose TWO endpoints:

1. **`POST /webhook`** — Synchronous (backward compatible with n8n pattern)
   - Waits for all agents to finish, returns `{output, attachment}` JSON
   - Used as fallback or by clients that don't support streaming

2. **`POST /webhook/stream`** — Server-Sent Events (SSE)
   - Returns a stream of events as agents work:
   ```
   event: status
   data: {"type": "informative", "message": "Searching Jira for sprint tickets..."}

   event: status
   data: {"type": "informative", "message": "Found 5 tickets. Creating Confluence page..."}

   event: chunk
   data: {"type": "streaming", "delta": "I retrieved your current sprint tickets from Jira. "}

   event: chunk
   data: {"type": "streaming", "delta": "Your Sprint 42 has 5 open tickets: ..."}

   event: done
   data: {"type": "final", "output": "Full response text here", "attachment": null}
   ```
   - ADK natively supports SSE via its `run_sse` pattern — adapt this for the webhook
   - Each sub-agent start/completion should emit a `status` event
   - The final LLM response should stream as `chunk` events
   - The `done` event contains the complete response (same format as sync endpoint)

### Bot Side (Frontend Dev's Responsibility)
The workoflow-bot needs these changes:

1. **Add SSE client** — Replace `axios.post(webhookUrl, payload)` with an SSE-capable client (e.g., `eventsource-parser` or `fetch` with streaming body)

2. **Use Bot Framework streaming** — The `context.sendActivity()` supports streaming natively:
   ```javascript
   // Informative message (blue progress bar)
   await context.sendActivity({
     type: 'typing',
     text: 'Searching Jira for sprint tickets...',
     channelData: { streamType: 'informative' }
   });

   // Streaming message (progressive text reveal)
   const streamingActivity = await context.sendActivity({
     type: 'message',
     text: partialText,
     channelData: { streamType: 'streaming', streamSequence: sequenceNumber }
   });

   // Final message (replaces streaming message)
   await context.updateActivity({
     ...streamingActivity,
     type: 'message',
     text: finalText,
     channelData: { streamType: 'final' }
   });
   ```

3. **Endpoint selection logic** in bot.js:
   ```javascript
   // Try streaming first, fall back to sync
   const streamUrl = `${webhookUrl}/stream`;
   const syncUrl = webhookUrl;

   try {
     const response = await streamFromOrchestrator(streamUrl, enrichedPayload, context);
   } catch (streamError) {
     // Fallback to synchronous
     const response = await axios.post(syncUrl, enrichedPayload, config);
   }
   ```

4. **Backward compatibility** — If `webhook_type` is `N8N`, always use sync. If `COMMON`, try streaming first.

### Research Task for Frontend Dev
The Frontend Dev agent must research the exact Bot Framework streaming API by reading:
- The workoflow-bot repo (`/workoflow-bot/`)
- Microsoft Bot Framework streaming docs (web search: "botbuilder-js streaming activities 2025 2026")
- Verify the exact `channelData` fields needed for MS Teams streaming
- Confirm no admin.teams.com changes are required
- Produce a concrete PR-ready diff for bot.js and any new files needed

---

## Docker Integration

### Dockerfile (to be created in new project)
```dockerfile
# Multi-stage build for production
FROM python:3.12-slim AS base

# Install dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application
COPY src/ /app/src/
COPY pyproject.toml /app/

WORKDIR /app

# Health check
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD curl -f http://localhost:8080/health || exit 1

# Run
EXPOSE 8080
CMD ["python", "-m", "src.main"]
```

### docker-compose.yaml Addition (in workoflow-hosting)
```yaml
  adk-orchestrator:
    image: patrickjasia/workoflow-adk-orchestrator:main
    ports:
      - "8080:8080"
    environment:
      - LITELLM_BASE_URL=http://litellm:4000
      - LITELLM_API_KEY=${LITELLM_MASTER_KEY}
      - LITELLM_MODEL=gpt-4.1
      - WORKOFLOW_API_URL=http://host.docker.internal:3979
      - WORKOFLOW_API_USER=${API_AUTH_USER}
      - WORKOFLOW_API_PASSWORD=${API_AUTH_PASSWORD}
      - REDIS_URL=redis://redis:6379
      - POSTGRES_URL=postgresql://postgres:postgres@postgres:5432/adk_orchestrator
      - OTEL_EXPORTER_OTLP_ENDPOINT=http://phoenix:4318
      - OTEL_SERVICE_NAME=workoflow-adk-orchestrator
      - LOG_LEVEL=INFO
    depends_on:
      litellm:
        condition: service_healthy
      redis:
        condition: service_healthy
      postgres:
        condition: service_healthy
      phoenix:
        condition: service_started
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/health"]
      interval: 30s
      timeout: 10s
      retries: 3
    restart: unless-stopped
```

### Tenant Configuration
When a tenant selects "COMMON" as webhook type in the Workoflow platform UI:
- `webhook_url` = `http://adk-orchestrator:8080/webhook` (internal Docker networking)
- `webhook_type` = `COMMON`
- The bot reads this from `/api/tenant/{uuid}/settings` and POSTs to the ADK endpoint

---

## Project Structure (Suggested)

```
workoflow-adk-orchestrator/
├── Dockerfile
├── pyproject.toml
├── requirements.txt
├── README.md
├── .env.example
├── src/
│   ├── __init__.py
│   ├── main.py                    # Entry point, HTTP server setup
│   ├── config.py                  # Environment configuration
│   ├── agents/
│   │   ├── __init__.py
│   │   ├── main_agent.py          # Coordinator/Dispatcher agent
│   │   ├── sub_agent_factory.py   # Dynamic sub-agent creation
│   │   └── prompts.py             # Prompt loading from API
│   ├── tools/
│   │   ├── __init__.py
│   │   ├── tool_discovery.py      # GET /api/integrations/{org}/
│   │   └── tool_execution.py      # POST /api/integrations/{org}/execute
│   ├── webhook/
│   │   ├── __init__.py
│   │   ├── handler.py             # Webhook request handler
│   │   ├── payload_parser.py      # Parse incoming bot payload
│   │   └── response_builder.py    # Build {output, attachment} response
│   ├── session/
│   │   ├── __init__.py
│   │   ├── redis_session.py       # Redis-backed session service
│   │   └── memory.py              # Conversation memory management
│   ├── observability/
│   │   ├── __init__.py
│   │   ├── tracing.py             # OpenTelemetry setup
│   │   └── logging.py             # Structured logging
│   └── utils/
│       ├── __init__.py
│       └── http_client.py         # Async HTTP client for platform API
├── tests/
│   ├── __init__.py
│   ├── test_main_agent.py
│   ├── test_sub_agent_factory.py
│   ├── test_webhook_handler.py
│   ├── test_payload_parser.py
│   └── test_tool_execution.py
└── docker/
    └── .env.dev
```

---

## Real Test Scenarios (Acceptance Criteria)

These are real user prompts from the workoflow-tests repository. The new orchestrator must handle all of them correctly. All prompts are in German (de-DE).

### Main Agent Tests
1. **"Was kannst du alles für mich tun?"** — Meta-question: respond with capabilities overview, don't list every integration
2. **"Wer bist du?"** — Identity question: respond as Workoflow assistant

### Jira Tests
3. **"Welche Tickets habe ich im aktuellen Sprint?"** — Get current sprint tickets
4. **"Erstelle ein Ticket im Projekt X mit Titel Y"** — Create a ticket (WRITE operation — must delegate)
5. **"Analysiere meinen Sprint und gib mir einen Statusbericht"** — Sprint analysis with recommendations

### Confluence Tests
6. **"Suche nach Seiten über Onboarding in Confluence"** — Search Confluence
7. **"Erstelle eine Confluence-Seite mit meinem Sprint-Status"** — Multi-agent: Jira → Confluence

### SharePoint Tests
8. **"Suche nach der API-Dokumentation in SharePoint"** — SharePoint search
9. **"Finde alle Dokumente zum Thema Datenschutz"** — Document discovery

### GitLab Tests
10. **"Zeige mir alle offenen Merge Requests"** — List open MRs
11. **"Überprüfe diesen Merge Request auf Code-Qualität"** — Code review

### Multi-Agent Workflows
12. **"Hole meine Sprint-Tickets und erstelle einen PDF-Bericht"** — Jira → System Tools
13. **"Finde die API-Doku in SharePoint und konvertiere sie zu PDF"** — SharePoint → System Tools
14. **"Hole Sprint-Daten, erstelle eine Confluence-Seite, dann generiere ein PDF davon"** — 3-agent chain

---

## Code Quality Requirements

### Python Best Practices
- **Type hints** on all functions and methods
- **Pydantic** models for all data structures (payloads, configs, responses)
- **async/await** throughout (async HTTP client, async agent execution)
- **Dependency injection** — no hard-coded dependencies
- **SOLID principles**:
  - Single Responsibility: each module has one purpose
  - Open/Closed: new integrations via configuration, not code changes
  - Liskov Substitution: session backends are interchangeable
  - Interface Segregation: tool discovery and execution are separate interfaces
  - Dependency Inversion: depend on abstractions (protocols/ABCs), not implementations

### Error Handling
- Return structured error responses, never crash the webhook
- Graceful degradation: if a sub-agent fails, report partial results
- Timeout handling: configurable per-agent timeout (default: 120s)
- Retry logic: tool execution failures get 1 retry before reporting error

### Testing
- Unit tests for payload parsing, response building, agent creation
- Integration tests for tool discovery and execution (mock HTTP)
- Use pytest with async support (pytest-asyncio)

---

## Environment Variables

```env
# LLM Configuration
LITELLM_BASE_URL=http://litellm:4000
LITELLM_API_KEY=sk-workoflow-dev-master-key-change-me
LITELLM_MODEL=gpt-4.1

# Workoflow Platform API
WORKOFLOW_API_URL=http://host.docker.internal:3979
WORKOFLOW_API_USER=api_user
WORKOFLOW_API_PASSWORD=api_password

# Session Storage
REDIS_URL=redis://redis:6379

# Database (optional, for long-term memory)
POSTGRES_URL=postgresql://postgres:postgres@postgres:5432/adk_orchestrator

# Observability
OTEL_EXPORTER_OTLP_ENDPOINT=http://phoenix:4318
OTEL_SERVICE_NAME=workoflow-adk-orchestrator
LOG_LEVEL=INFO

# Server
HOST=0.0.0.0
PORT=8080

# Agent Configuration
AGENT_TIMEOUT_SECONDS=120
AGENT_MAX_TOOL_CALLS=10
```

---

## Definition of Done

The Team Lead agent signs off when ALL of these are met:

1. ✅ ADK orchestrator handles all 22 test scenarios correctly
2. ✅ Parallel sub-agent execution works for independent tasks
3. ✅ Sequential execution with data passing works for dependent tasks
4. ✅ LiteLLM integration confirmed (no direct Google LLM calls)
5. ✅ Webhook endpoint accepts bot payload and returns `{output, attachment}`
6. ✅ OpenTelemetry traces visible in Phoenix
7. ✅ Dockerfile builds and runs successfully
8. ✅ docker-compose integration config ready for workoflow-hosting
9. ✅ All Python code passes type checking (mypy) and linting (ruff)
10. ✅ Unit tests pass with >80% coverage on core modules
11. ✅ Session management works (multi-turn conversations)
12. ✅ German language prompts handled correctly
13. ✅ Error handling: graceful degradation, no crashes
14. ✅ Health check endpoint at `/health`
15. ✅ README with setup instructions
16. ✅ Clean Code and SOLID principles applied throughout
