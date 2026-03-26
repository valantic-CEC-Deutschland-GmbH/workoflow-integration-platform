# n8n Workflow Configuration Guide

## Overview

This directory contains n8n workflow JSON files for the multi-agent AI orchestration system. These workflows implement the agent architecture defined in `../system_prompts/`.

**Version:** 2.0.0
**Last Updated:** 2025-01-19

## Workflows

| Workflow | File | Type | Description |
|----------|------|------|-------------|
| Main Agent | `main_agent.json` | Orchestrator | Routes user requests to specialized sub-agents |
| Jira Agent | `jira_agent.json` | User Integration | Handles Jira operations (issues, sprints, comments) |
| Confluence Agent | `confluence_agent.json` | User Integration | Manages Confluence pages, spaces, and documentation |
| GitLab Agent | `gitlab_agent.json` | User Integration | Handles GitLab repositories, MRs, and CI/CD operations |
| SharePoint Agent | `sharepoint_agent.json` | User Integration | Manages SharePoint document libraries and searches |
| Trello Agent | `trello_agent.json` | User Integration | Handles Trello boards, cards, and project management |
| System Tools Agent | `system_tools_agent.json` | Platform Tools | Platform-internal tools (PDF generation, web search, etc.) |

## Architecture

```
User Request (Webhook)
    ↓
Main Agent (Orchestrator) ← Has conversation memory
    ├─→ Jira Agent (Stateless Sub-Workflow)
    ├─→ Confluence Agent (Stateless Sub-Workflow)
    ├─→ GitLab Agent (Stateless Sub-Workflow)
    ├─→ SharePoint Agent (Stateless Sub-Workflow)
    ├─→ Trello Agent (Stateless Sub-Workflow)
    └─→ System Tools Agent (Stateless Sub-Workflow)
```

### Key Principles

1. **Stateless Sub-Agents**: Only Main Agent has conversation memory
2. **Main Agent Orchestration**: All user interaction flows through Main Agent
3. **Synchronous Execution**: All work completes before webhook response
4. **Dynamic Tool Discovery**: Sub-agents fetch user-specific tools at runtime

## Importing Workflows

### Prerequisites

1. **n8n Instance**: Version 1.0.0 or higher
2. **Required Credentials**:
   - Azure OpenAI API credentials (for LLM)
   - PostgreSQL credentials (for chat memory)
   - HTTP Basic Auth credentials (for Workoflow Integration Platform API)

### Import Steps

1. **Navigate to n8n**: Open your n8n instance
2. **Import Main Agent**:
   - Go to Workflows → Import from File
   - Select `main_agent.json`
   - Click "Import"
3. **Import Jira Agent**:
   - Go to Workflows → Import from File
   - Select `jira_agent.json`
   - Click "Import"

### Configure Credentials

#### 1. Azure OpenAI API

Both workflows use the same OpenAI proxy:

- **Credential Name**: `azure-open-ai-proxy`
- **Type**: Azure OpenAI API
- **Required Fields**:
  - Resource Name
  - API Key
  - Deployment Name (model: `gpt-4.1`)

**Configuration Location**: Both Main Agent and Jira Agent → "workoflow-proxy" node

#### 2. PostgreSQL (Chat Memory)

Only Main Agent uses PostgreSQL for conversation history:

- **Credential Name**: `Postgres account`
- **Type**: PostgreSQL
- **Required Fields**:
  - Host
  - Database
  - User
  - Password
  - Port (default: 5432)

**Configuration Location**: Main Agent → "Postgres Chat Memory" node

#### 3. HTTP Basic Auth (Integration Platform)

Jira Agent uses Basic Auth to access the Workoflow Integration Platform API:

- **Credential Name**: `Workoflow Integration Platform`
- **Type**: HTTP Basic Auth
- **Required Fields**:
  - User: (API username from Integration Platform)
  - Password: (API password from Integration Platform)

**Configuration Location**: Jira Agent → "CURRENT_USER_TOOLS" and "CURRENT_USER_EXECUTE_TOOL" nodes

#### 4. Webhook Basic Auth (Main Agent)

Main Agent webhook is protected with Basic Auth:

- **Credential Name**: `Workoflow basic auth`
- **Type**: HTTP Basic Auth
- **Required Fields**:
  - User: (your webhook username)
  - Password: (your webhook password)

**Configuration Location**: Main Agent → "Webhook" node

## Workflow Configuration

### Main Agent Setup

1. **Activate Webhook**:
   - Open Main Agent workflow
   - Click on "Webhook" node
   - Copy the Production URL (e.g., `https://your-n8n.com/webhook/3fc1f315-9805-49fd-af01-bcc92ee7551d`)
   - This is your API endpoint for user requests

2. **Connect Sub-Agents**:
   - The "Call 'Jira Agent'" node must reference the correct Jira Agent workflow ID
   - After importing, verify: Click "Call 'Jira Agent'" node → Check "Workflow" dropdown shows "Jira Agent"

3. **Configure Session Memory**:
   - The "Postgres Chat Memory" node uses `userId` as session key
   - Each user gets isolated conversation history

### Jira Agent Setup

1. **Configure Integration Platform URLs**:
   - Both HTTP Request Tool nodes have URLs pointing to `https://subscribe-workflows.vcec.cloud`
   - If your Integration Platform is hosted elsewhere, update these URLs in:
     - "CURRENT_USER_TOOLS" node
     - "CURRENT_USER_EXECUTE_TOOL" node

2. **Tool Discovery Endpoint**:
   ```
   GET /api/integrations/{tenantID}/?workflow_user_id={userID}&tool_type=jira
   ```

3. **Tool Execution Endpoint**:
   ```
   POST /api/integrations/{tenantID}/execute?workflow_user_id={userID}
   ```

## Testing

### Test Main Agent

1. **Pin Test Data**:
   - Main Agent → "Webhook" node
   - Pin test data (already configured):
   ```json
   {
     "body": {
       "from": {
         "id": "test-user-123",
         "name": "Test User",
         "aadObjectId": "test-aad-id"
       },
       "conversation": {
         "id": "test-session",
         "tenantId": "0cd3a714-adc1-4540-bd08-7316a80b34f3"
       },
       "text": "Show me my open Jira tickets",
       "locale": "de-DE"
     }
   }
   ```

2. **Execute Workflow**:
   - Click "Test Workflow"
   - Verify Main Agent calls Jira Agent
   - Check response format

### Test Jira Agent

1. **Pin Test Data**:
   - Jira Agent → "When Executed by Another Workflow" node
   - Pin test data (already configured):
   ```json
   {
     "userID": "test-user-123",
     "tenantID": "0cd3a714-adc1-4540-bd08-7316a80b34f3",
     "userPrompt": "Show me my open tickets",
     "taskDescription": "Search for open Jira issues assigned to current user",
     "locale": "de-DE"
   }
   ```

2. **Execute Workflow**:
   - Click "Test Workflow"
   - Verify tool discovery works
   - Check execution completes

## Parameter Flow

### Standard Sub-Agent Parameters

All sub-agents receive these parameters from Main Agent:

| Parameter | Type | Source | Purpose |
|-----------|------|--------|---------|
| `tenantID` | string | Webhook body | Organization identifier for API calls |
| `userID` | string | Webhook body | User identifier for permission/tool filtering |
| `locale` | string | Webhook body | Language preference (de-DE, en-US) |
| `userPrompt` | string | Webhook body | Original user message (for context) |
| `taskDescription` | string | AI-generated | Refined task for this specific sub-agent |

### How Main Agent Generates taskDescription

The Main Agent uses `$fromAI()` to dynamically generate task descriptions based on:
- User's original request
- Conversation context
- Which sub-agent is being called
- Data from previous sub-agents (for multi-agent workflows)

**Example**:
- User: "Create a Confluence page from my current Jira sprint"
- Main Agent → Jira Agent: `taskDescription: "Get current sprint data with all tickets and story points"`
- Main Agent → Confluence Agent: `taskDescription: "Create page titled 'Sprint 42 Summary' with this data: {sprint_data}"`

## Response Format

All sub-agents return responses in this format:

```json
{
  "output": "User-facing message in past tense",
  "data": {
    // Optional: Structured data for Main Agent to use
  },
  "attachment": null // Or file URL
}
```

**Key Points**:
- `output`: Always past tense (e.g., "I retrieved 12 tickets" not "I will retrieve")
- `data`: Used by Main Agent to pass information to next sub-agent
- `attachment`: File URLs for generated documents (PDFs, PowerPoints)

## Troubleshooting

### Main Agent Issues

**Issue**: Sub-agent not being called
- **Check**: Tool description in "Call 'Jira Agent'" node
- **Fix**: Ensure description contains relevant keywords (jira, ticket, issue, etc.)

**Issue**: Session memory not working
- **Check**: PostgreSQL connection
- **Fix**: Verify credentials and table schema in Postgres Chat Memory node

**Issue**: Webhook not responding
- **Check**: "Respond to Webhook" nodes are connected
- **Fix**: Ensure "Successful?" If node properly routes to response nodes

### Jira Agent Issues

**Issue**: No tools available
- **Check**: Integration Platform API URL and credentials
- **Fix**: Verify user has Jira integration configured in Integration Platform

**Issue**: Tool execution fails
- **Check**: Tool parameters in CURRENT_USER_EXECUTE_TOOL node
- **Fix**: Ensure JSON body format matches API expectations

**Issue**: Wrong tools returned
- **Check**: `tool_type=jira` query parameter
- **Fix**: Verify URL in CURRENT_USER_TOOLS node includes correct tool_type

## Helper Script: create_agent.sh

To simplify the creation of new user integration agents, use the provided `create_agent.sh` helper script. This script automates the generation of n8n workflow JSON files from XML system prompts.

### Purpose

The script generates complete n8n workflow JSON files with:
- Proper node structure (Execute Workflow Trigger → AI Agent → Respond to Webhook)
- Embedded system prompt from XML file
- HTTP Request Tool nodes for dynamic tool discovery and execution
- Unique UUIDs for all nodes
- Correct connections between nodes
- Predefined credentials (Azure OpenAI, HTTP Basic Auth)
- Pin data for testing

### Usage

```bash
bash create_agent.sh <agent_type> <xml_file> <output_file>
```

**Parameters**:
- `agent_type`: Type of integration (e.g., `jira`, `confluence`, `gitlab`, `sharepoint`, `trello`)
- `xml_file`: Path to system prompt XML file (e.g., `../system_prompts/confluence_agent.xml`)
- `output_file`: Output path for generated JSON (e.g., `confluence_agent.json`)

### Examples

```bash
# Generate Confluence Agent
bash create_agent.sh confluence ../system_prompts/confluence_agent.xml confluence_agent.json

# Generate GitLab Agent
bash create_agent.sh gitlab ../system_prompts/gitlab_agent.xml gitlab_agent.json

# Generate SharePoint Agent
bash create_agent.sh sharepoint ../system_prompts/sharepoint_agent.xml sharepoint_agent.json

# Generate Trello Agent
bash create_agent.sh trello ../system_prompts/trello_agent.xml trello_agent.json
```

### What the Script Generates

The script creates a complete user integration agent with:

1. **Execute Workflow Trigger** node with parameters:
   - `userID` (string)
   - `tenantID` (string)
   - `locale` (string)
   - `userPrompt` (string)
   - `taskDescription` (string)

2. **AI Agent** node with:
   - Embedded system prompt from XML file
   - `maxIterations: 15`
   - Task text from `taskDescription` parameter

3. **Azure OpenAI LLM** node:
   - Model: `gpt-4.1`
   - Credential: `azure-open-ai-proxy` (ID: cLd5WshSqySpWGKz)
   - Max retries: 2

4. **CURRENT_USER_TOOLS** (HTTP Request Tool):
   - GET `/api/integrations/{tenantID}/?workflow_user_id={userID}&tool_type={agent_type}`
   - Description: Dynamic tool discovery for current user
   - Credential: `Workoflow Integration Platform` (ID: 6DYEkcLl6x47zCLE)

5. **CURRENT_USER_EXECUTE_TOOL** (HTTP Request Tool):
   - POST `/api/integrations/{tenantID}/execute?workflow_user_id={userID}`
   - Description: Executes tools with parameters
   - Credential: `Workoflow Integration Platform` (ID: 6DYEkcLl6x47zCLE)

6. **Respond to Webhook** node for returning results

7. **Pin Data** for testing with sample parameters

### Important Notes

- **User Integration Agents Only**: This script is designed for user integration agents (Jira, Confluence, GitLab, SharePoint, Trello)
- **Not for System Tools Agent**: System Tools Agent has a different structure and requires manual tool connections
- **Credentials**: Generated workflows use predefined credential IDs from existing jira_agent.json
- **UUIDs**: Each run generates unique UUIDs for all nodes
- **XML Escaping**: System prompt XML is properly escaped for JSON embedding

### Limitations

The helper script does NOT handle:
- System Tools Agent creation (different architecture)
- Custom credential IDs (uses predefined IDs)
- Direct n8n tool nodes (content_query, generate_pdf, etc.)
- Main Agent modifications (must be done manually)

## Updating Agent System Prompts in n8n Workflows

**IMPORTANT:** When system prompt XML files in `../system_prompts/` are updated, the corresponding n8n workflow JSON files must also be updated manually.

### Why Manual Update is Required

The n8n workflow JSON files contain embedded copies of the system prompt XML within the "AI Agent" node's `systemMessage` field. These embedded prompts are **not** automatically synchronized when the XML files change.

### How to Update an Agent's System Prompt

When you modify a system prompt XML file (e.g., `sharepoint_agent.xml`), follow these steps:

1. **Open the n8n Workflow in the UI**:
   - Navigate to your n8n instance
   - Open the corresponding workflow (e.g., "SharePoint Agent")

2. **Locate the AI Agent Node**:
   - Find the node named "AI Agent" or "AI Agent1"
   - Click to edit the node

3. **Update the System Message**:
   - In the node configuration, find the "Options" section
   - Locate the "System Message" field
   - **Copy the entire contents** of the updated XML file from `../system_prompts/<agent>_agent.xml`
   - **Paste** it into the "System Message" field, replacing the old content

4. **Save and Test**:
   - Click "Save" to save the node changes
   - Click "Save" again to save the workflow
   - Test the workflow with pinned data to verify the updated behavior

### Alternative: Programmatic Update (Advanced)

For large XML files or multiple agents, you can use this Python script:

```bash
cd /workoflow-integration-platform/docs/agents/n8n

python3 << 'PYTHON_EOF'
import json

# Read the XML file
with open('../system_prompts/sharepoint_agent.xml', 'r') as f:
    xml_content = f.read()

# Read the JSON workflow
with open('sharepoint_agent.json', 'r') as f:
    workflow = json.load(f)

# Find and update the AI Agent node's systemMessage
for node in workflow.get('nodes', []):
    if node.get('type') == '@n8n/n8n-nodes-langchain.agent':
        if 'parameters' in node and 'options' in node['parameters']:
            node['parameters']['options']['systemMessage'] = xml_content
            break

# Write back
with open('sharepoint_agent.json', 'w') as f:
    json.dump(workflow, f, indent=2, ensure_ascii=False)

print("✓ Updated sharepoint_agent.json")
PYTHON_EOF
```

### Affected Workflows

The following workflows contain embedded system prompts that must be manually updated:

| Workflow | XML Source | JSON File | AI Agent Node Name |
|----------|------------|-----------|-------------------|
| Main Agent | `main_agent.xml` | `main_agent.json` | "AI Agent" |
| Jira Agent | `jira_agent.xml` | `jira_agent.json` | "AI Agent1" |
| Confluence Agent | `confluence_agent.xml` | `confluence_agent.json` | "AI Agent1" |
| GitLab Agent | `gitlab_agent.xml` | `gitlab_agent.json` | "AI Agent1" |
| **SharePoint Agent** | `sharepoint_agent.xml` | `sharepoint_agent.json` | "AI Agent1" |
| Trello Agent | `trello_agent.xml` | `trello_agent.json` | "AI Agent1" |
| System Tools Agent | `system_tools_agent.xml` | `system_tools_agent.json` | "AI Agent1" |

### Recent Updates Requiring Manual Sync

**2025-01-19 - SharePoint Agent KQL Enhancement:**
- **File**: `sharepoint_agent.xml`
- **Changes**:
  - Added automatic KQL (Keyword Query Language) parsing
  - Implemented result grouping by type (Files, Sites, Pages, Lists, Drives)
  - Simplified intelligent search workflow (single search call with multi-keywords)
  - Enhanced error handling with detailed troubleshooting messages
- **Action Required**: Update `sharepoint_agent.json` in n8n with the new XML content from `sharepoint_agent.xml`

## Adding New Sub-Agents

To add a new user integration agent:

### Option A: Using Helper Script (Recommended)

1. **Create System Prompt**:
   - Create `../system_prompts/<agent_type>_agent.xml`
   - Define agent behavior and tool usage patterns

2. **Generate Workflow**:
   ```bash
   bash create_agent.sh <agent_type> ../system_prompts/<agent_type>_agent.xml <agent_type>_agent.json
   ```

3. **Update Main Agent**:
   - Add new "toolWorkflow" node with appropriate description
   - Connect to AI Agent
   - Configure parameter mapping (tenantID, userID, locale, userPrompt, taskDescription)

4. **Import and Configure**:
   - Import workflow to n8n
   - Note the workflow ID
   - Update Main Agent's toolWorkflow node with actual workflow ID

### Option B: Manual Creation

1. **Create Workflow**:
   - Copy `jira_agent.json` as template
   - Update system prompt with agent-specific instructions
   - Update HTTP Request Tool URLs to use `tool_type=<agent_type>`
   - Generate new UUIDs for all nodes

2. **Update Main Agent**:
   - Add new "toolWorkflow" node
   - Connect to AI Agent
   - Set tool description with relevant keywords
   - Configure parameter mapping

3. **Update System Prompts**:
   - Create `../system_prompts/<agent_type>_agent.xml`
   - Update `../system_prompts/main_agent.xml` with routing logic

4. **Document**:
   - Add workflow to this README
   - Update `workflow_design.md` with new agent

## Version History

- **2.0.0** (2025-01-19): Added Confluence, GitLab, SharePoint, Trello, and System Tools agents. Introduced create_agent.sh helper script for automated agent generation
- **1.0.0** (2025-01-18): Initial release with Main Agent and Jira Agent

## Support

- **Documentation**: `/docs/agents/system_prompts/README.md`
- **Architecture**: `workflow_design.md`
- **Integration Platform**: https://subscribe-workflows.vcec.cloud
- **Repository**: /workoflow-integration-platform

## Next Steps

After importing and configuring workflows:

1. ✅ Verify all credentials are configured
2. ✅ Test Main Agent with pinned data
3. ✅ Test Jira Agent with pinned data
4. ✅ Test end-to-end with real webhook call
5. ✅ Configure user integrations in Integration Platform
6. ✅ Monitor audit logs for issues

---

**Last Updated:** 2025-01-19
**Maintainer:** Development Team
