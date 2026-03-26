# Multi-Agent System - Architecture Documentation

## Overview

This directory contains system prompts for a multi-agent AI system designed to help users interact with various integrated services (Jira, Confluence, SharePoint, GitLab, Trello) and platform utilities through specialized AI agents.

**Version:** 1.0.0
**Last Updated:** 2025-01-14

## Architecture

### System Design

```
┌─────────────────────────────────────────────────────────────┐
│                         User Request                         │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                      Main Agent (Orchestrator)               │
│  - Analyzes user intent                                      │
│  - Routes to specialized agent(s)                            │
│  - Coordinates multi-agent workflows                         │
│  - Aggregates results                                        │
└───────────────────────────┬─────────────────────────────────┘
                            │
                ┌───────────┴───────────┐
                │                       │
                ▼                       ▼
┌───────────────────────┐   ┌───────────────────────┐
│   User Integrations   │   │   System Tools        │
└───────────────────────┘   └───────────────────────┘
         │                           │
    ┌────┼────┬────┬────┬────┬────┐ │
    ▼    ▼    ▼    ▼    ▼    ▼    ▼ ▼
  JIRA  Conf  SP  GitLab Trello SAP Proj Platform
 Agent Agent Agent Agent Agent  C4C tron  Tools
                               Agent Agent
```

### Agent Responsibilities

#### Main Agent (Orchestrator)
- **File:** `main_agent.xml`
- **Role:** Central coordinator and router
- **Responsibilities:**
  - Analyze user intent and determine which agent(s) to invoke
  - Route requests to appropriate specialized agents
  - Coordinate multi-agent workflows (sequential execution)
  - Aggregate results from multiple agents
  - Handle general queries that don't require specialized agents
  - Provide no-config messages when integrations aren't set up

#### JIRA Agent
- **File:** `jira_agent.xml`
- **Integration Type:** `jira`
- **Tools:** 8 (search, get issue, sprints, transitions, comments)
- **Capabilities:**
  - Issue management and tracking
  - Sprint planning and analysis
  - Workflow transitions (manual and intelligent)
  - Comment management

#### Confluence Agent
- **File:** `confluence_agent.xml`
- **Integration Type:** `confluence`
- **Tools:** 5 (search, get page, comments, create, update)
- **Capabilities:**
  - Wiki page search (CQL)
  - Create and update documentation
  - Manage page comments
  - Maintain version history

#### SharePoint Agent
- **File:** `sharepoint_agent.xml`
- **Integration Type:** `sharepoint`
- **Tools:** 6 (search with KQL, read document/page, list files, download, list items)
- **Capabilities:**
  - **Direct KQL (Keyword Query Language) search** - AI builds queries natively
  - Document reading (Word, Excel, PowerPoint, PDF)
  - File and folder management
  - SharePoint list operations
  - **Always asks user permission before reading documents**

#### GitLab Agent
- **File:** `gitlab_agent.xml`
- **Integration Type:** `gitlab`
- **Tools:** 26 (repos, MRs, issues, CI/CD, commits, packages)
- **Capabilities:**
  - Repository and code management
  - Merge request workflows and code review
  - Issue tracking
  - CI/CD pipeline monitoring and troubleshooting
  - Package management

#### Trello Agent
- **File:** `trello_agent.xml`
- **Integration Type:** `trello`
- **Tools:** 12 (boards, lists, cards, comments, checklists)
- **Capabilities:**
  - Board and list management
  - Card creation and updates
  - Checklist management
  - Project progress tracking

#### SAP C4C Agent
- **File:** `sap_c4c_agent.xml` (to be created)
- **Integration Type:** `sap_c4c`
- **Tools:** 5 (create lead, get lead, search leads, update lead, list leads)
- **Capabilities:**
  - Lead management (create, read, update)
  - OData-based search and filtering
  - Lead qualification tracking
  - Pagination support

#### Projektron Agent
- **File:** `projektron_agent.xml` (to be created)
- **Integration Type:** `projektron`
- **Tools:** 1 (get all tasks)
- **Capabilities:**
  - Fetch available projects and tasks
  - Provide time booking URLs
  - Task/project OID discovery for time tracking

#### System Tools Agent
- **File:** `system_tools_agent.xml`
- **Integration Type:** `system`
- **Tools:** 13 (web search, file generation, employee queries, etc.)
- **Capabilities:**
  - Web search (SearXNG)
  - PDF and PowerPoint generation
  - File sharing and uploads
  - Employee search and profiles
  - Company events
  - Content query and learning
  - Memory management

## Key Design Principles

### 1. Synchronous Webhook Execution

**CRITICAL CONSTRAINT:** All agents operate in a synchronous webhook environment.

```
❌ PROHIBITED: "I will update...", "Please wait...", "I'm working on..."
✅ REQUIRED: "I have updated...", "The page was created...", "Done!"
```

**Rules:**
- Complete ALL actions BEFORE responding
- Use PAST TENSE only
- Never promise future actions
- Connection closes immediately after response

### 2. Specialized Agent Delegation

**Main Agent delegates to specialized agents:**
1. Analyzes user intent
2. Determines required agent(s)
3. Invokes agent workflow(s) **synchronously**
4. Waits for completion
5. Aggregates results
6. Responds in past tense

**Multi-Agent Workflows:**
- Execute **sequentially** (not parallel)
- Wait for each agent to complete
- Pass data between agents
- Aggregate final results

### 3. Tool Discovery Pattern

Each specialized agent fetches its own tools dynamically:

```
GET /api/integrations/{tenantID}/?workflow_user_id={userID}&tool_type={type}
```

Tool types:
- `jira`
- `confluence`
- `sharepoint`
- `gitlab`
- `trello`
- `system`

Execution:
```
POST /api/integrations/{tenantID}/execute?workflow_user_id={userID}
```

### 4. Output Format Preservation

**CRITICAL RULE:** Main Agent MUST preserve formatting from specialized agents.

**Why**: Specialized agents (like SharePoint Agent) format their output for end-users with:
- Markdown links: `[filename](https://...)`
- Bold/italic emphasis
- Structured lists
- Clickable URLs

**Main Agent Responsibility**:
- Preserve all markdown syntax during aggregation
- Never strip link formatting `[text](url)` → `text`
- Maintain user-facing formatting from sub-agents

**Example**:
```json
// SharePoint Agent returns:
{
  "output": "[Document.pdf](https://sharepoint.com/doc.pdf) – Summary"
}

// Main Agent MUST return:
{
  "output": "[Document.pdf](https://sharepoint.com/doc.pdf) – Summary"
}

// NOT:
{
  "output": "Document.pdf – Summary" ❌
}
```

**Common Mistake**:
When the Main Agent aggregates results, it often reformats the output for clarity.
During this reformatting, markdown link syntax `[text](url)` can be accidentally
stripped to just `text`, breaking the user experience. The Main Agent must be
vigilant to preserve all markdown links exactly as provided by sub-agents.

### 5. SharePoint KQL Search

**Special Feature:** SharePoint Agent uses direct KQL (Keyword Query Language) search:

1. **AI builds KQL queries directly:**
   - Agent naturally understands context and builds appropriate queries
   - Uses OR operators for synonyms and translations
   - Supports field filters (author:, filename:, filetype:, title:)
   - Supports wildcards, exact phrases, and date filters

2. **Example KQL patterns:**
   - `vacation OR Urlaub OR leave OR Ferien` (bilingual search)
   - `"project status" AND filetype:docx` (exact phrase + filter)
   - `budget* author:John` (wildcard + author filter)

3. **User confirmation required:**
   - Present ALL results grouped by type
   - Ask which to read
   - NEVER auto-read documents
   - Complete reading BEFORE responding

### 6. Parameter Collection

**Inherited by all agents:**
- Ask ONE question at a time
- Reference conversation history
- Execute immediately when all params collected
- Never promise "I will do X after you answer"

## File Structure

```
docs/agents/
├── system_prompts/                # AI agent system prompts (this directory)
│   ├── README.md                  # This file
│   ├── main_agent.xml             # Orchestrator (routes to specialized agents)
│   ├── jira_agent.xml             # JIRA task management
│   ├── confluence_agent.xml       # Wiki/documentation
│   ├── sharepoint_agent.xml       # Document management (intelligent search)
│   ├── gitlab_agent.xml           # DevOps/CI-CD/repos
│   ├── trello_agent.xml           # Project management
│   └── system_tools_agent.xml     # Platform utilities
└── n8n/                           # n8n workflow implementations
    ├── README.md                  # Import/configuration guide
    ├── workflow_design.md         # Architecture documentation
    ├── main_agent.json            # Main Agent n8n workflow
    └── jira_agent.json            # Jira Agent n8n workflow
```

**Related Documentation:**
- **n8n Workflows:** See `/docs/agents/n8n/` for n8n workflow JSON files and implementation guide
- **Architecture:** See `/docs/agents/n8n/workflow_design.md` for detailed architecture documentation

## n8n Workflow Integration

### Architecture
- **Main Agent:** Separate n8n workflow (orchestrator)
- **Specialized Agents:** Each is a separate n8n workflow
- **Invocation:** Main Agent calls sub-workflows via "Execute Workflow" node
- **Tool Fetching:** Each agent fetches its own tools independently

### Context Passing

All agents receive context variables:
```javascript
{
  tenantID: "{{ $json.tenantId }}",
  userID: "{{ $json.userID }}",
  locale: "{{ $json.locale }}",
  currentDate: "{{ $now.format('dd.MM.yyyy') }}"
}
```

### Response Format

All agents return:
```json
{
  "output": "Past tense message describing completed actions",
  "attachment": null // or URL for generated files
}
```

## Maintenance Guidelines

### When to Update System Prompts

#### 1. New Tool Added to Integration

**Steps:**
1. Identify which agent handles this integration
2. Open corresponding `{agent}_agent.xml`
3. Add tool to `<available-tools>` section:
   ```xml
   <tool>
     <name>tool_name_{integration_id}</name>
     <purpose>Description</purpose>
     <parameters>...</parameters>
   </tool>
   ```
4. Add workflow guideline if tool requires special handling
5. Add example interaction showing tool usage
6. Update tool count in XML header comment
7. Commit with message: `feat: add {tool_name} to {agent} agent`

**Example:**
```bash
# New Jira tool: jira_watch_issue
# Edit: docs/agents/system_prompts/jira_agent.xml
# Update: Tool count from 8 to 9
# Add: Tool definition, parameters, workflow guideline
# Commit: "feat: add jira_watch_issue to JIRA agent"
```

#### 2. Tool Logic Changed

**Steps:**
1. Identify affected agent
2. Update `<workflow-guidelines>` section
3. Update examples if behavior changed
4. Update `<best-practices>` if relevant
5. Commit with message: `fix: update {tool_name} workflow in {agent} agent`

**Example:**
```bash
# SharePoint search now supports filters
# Edit: docs/agents/system_prompts/sharepoint_agent.xml
# Update: Workflow guideline for search
# Update: Example interaction
# Commit: "fix: update SharePoint search workflow with filter support"
```

#### 3. New Integration Type Added

**Steps:**
1. Analyze integration tools and capabilities
2. Create new `{integration}_agent.xml` based on template below
3. Update `main_agent.xml`:
   - Add agent to `<specialized-agents>`
   - Add keywords for routing
   - Add routing example
4. Update this README with new agent info
5. Commit with message: `feat: add {Integration} agent support`

**Agent Template:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<!--
  {Name} Agent - System Prompt
  Version: 1.0.0
  Last Updated: YYYY-MM-DD
  Purpose: ...
  Integration Type: {type}
  Tool Count: {N}
-->
<system-prompt>
  <agent>...</agent>
  <webhook-constraint>...</webhook-constraint>
  <context>...</context>
  <tool-discovery>...</tool-discovery>
  <core-responsibilities>...</core-responsibilities>
  <available-tools>...</available-tools>
  <workflow-guidelines>...</workflow-guidelines>
  <parameter-collection>...</parameter-collection>
  <best-practices>...</best-practices>
  <verification-requirements>...</verification-requirements>
  <response-structure>...</response-structure>
  <output-format>...</output-format>
  <common-mistakes>...</common-mistakes>
  <example-interactions>...</example-interactions>
  <security-and-privacy>...</security-and-privacy>
  <core-principle>...</core-principle>
</system-prompt>
```

#### 4. Bug Pattern Identified

**Steps:**
1. Identify which agent(s) affected
2. Add to `<common-mistakes>` section
3. Add to verification checklist if applicable
4. Update examples if needed
5. Commit with message: `docs: document {issue} anti-pattern in {agent} agent`

**Example:**
```bash
# Agents were updating without reading first
# Edit: All affected agent XMLs
# Add: Common mistake "Always read before updating"
# Commit: "docs: document read-before-update pattern in all agents"
```

### Version Control Best Practices

#### Commit Message Format
```
<type>: <description>

<optional body>

Affected agents: {list of agents}
```

**Types:**
- `feat`: New tool, integration, or capability
- `fix`: Correction to existing workflow or logic
- `docs`: Documentation updates (README, comments)
- `refactor`: Restructuring without behavior change
- `chore`: Maintenance tasks

#### Example Commits
```bash
# New tool added
feat: add jira_bulk_update to JIRA agent

Added support for bulk updating multiple Jira issues at once.
Tool requires issue IDs array and update fields.

Affected agents: jira_agent

# Logic correction
fix: update Confluence version handling workflow

Confluence updates now correctly increment version number.
Added guideline to always fetch current version first.

Affected agents: confluence_agent

# Documentation improvement
docs: clarify SharePoint intelligent search workflow

Added more examples of keyword variations and search strategies.
Emphasized the requirement to ask before reading documents.

Affected agents: sharepoint_agent
```

### Changelog Tracking

**In XML Header:**
```xml
<!--
  Version History:
  - 1.0.0 (2025-01-14): Initial release
  - 1.1.0 (2025-02-01): Added new tool X
  - 1.1.1 (2025-02-15): Fixed workflow Y
-->
```

**In Repository CHANGELOG.md:**
Follow existing project patterns (user-facing changes only).

## Testing System Prompts

### Manual Testing Checklist

When updating an agent prompt:

1. **Syntax Validation**
   - [ ] XML is well-formed
   - [ ] All tags properly closed
   - [ ] n8n variables use correct syntax

2. **Tool Definitions**
   - [ ] All parameters documented
   - [ ] Required vs optional clearly marked
   - [ ] Return values described

3. **Workflow Guidelines**
   - [ ] Step-by-step processes clear
   - [ ] Examples provided
   - [ ] Edge cases covered

4. **Consistency**
   - [ ] Webhook constraint preserved
   - [ ] Output format consistent
   - [ ] Past tense language enforced

5. **Integration Testing**
   - [ ] Test in n8n workflow
   - [ ] Verify tool discovery works
   - [ ] Confirm execution completes before response
   - [ ] Check multi-agent coordination (if applicable)

### Common Testing Scenarios

**Single Agent Test:**
```
User: "Show me my open Jira tickets"
Expected: JIRA Agent executes, returns results in past tense
Verify: No future tense, all data present, correct format
```

**Multi-Agent Test:**
```
User: "Create a Confluence page from JIRA sprint data"
Expected:
  1. Main Agent analyzes intent
  2. JIRA Agent fetches sprint data (completes)
  3. Confluence Agent creates page (completes)
  4. Main Agent aggregates and responds (past tense)
Verify: Sequential execution, data flows correctly, past tense
```

**No-Config Test:**
```
User: "Search my Trello boards"
Expected: Agent detects missing Trello integration
Response: Helpful setup instructions with URL
Verify: No error, clear guidance, integration platform URL included
```

## Troubleshooting

### Common Issues

#### Issue: Agent responding before completing actions
**Symptom:** Response says "I will do X" or uses future tense
**Solution:** Review `<webhook-constraint>` section, ensure agent waits for all tool calls
**Fix Location:** Agent XML `<execution-order>` section

#### Issue: Multi-agent tasks executing in parallel
**Symptom:** Data from first agent not available to second agent
**Solution:** Ensure Main Agent coordinates sequentially
**Fix Location:** `main_agent.xml` `<multi-agent-coordination>` section

#### Issue: Agent not asking for missing parameters
**Symptom:** Agent errors or fails due to missing data
**Solution:** Review `<parameter-collection>` workflow
**Fix Location:** Agent XML `<missing-information>` section

#### Issue: SharePoint auto-reading documents without permission
**Symptom:** User privacy concern, documents read without asking
**Solution:** Verify intelligent search workflow steps
**Fix Location:** `sharepoint_agent.xml` `<intelligent-search-workflow>` section

#### Issue: Tool names incorrect or outdated
**Symptom:** Integration fails, tool not found errors
**Solution:** Tools are dynamically loaded - verify tool discovery endpoint
**Fix Location:** Agent XML `<tool-discovery>` section, backend integration class

### Debug Workflow

1. **Identify which agent is involved**
   - Look at user request keywords
   - Check Main Agent routing logic
   - Determine if single or multi-agent

2. **Review agent XML**
   - Check tool definitions match backend
   - Verify workflow guidelines
   - Review example interactions

3. **Test in isolation**
   - Invoke specific agent workflow directly
   - Check tool discovery response
   - Verify execution completes

4. **Check backend integration**
   - Verify tool exists in `src/Integration/`
   - Check tool parameters match prompt
   - Confirm API endpoints are correct

## Agent Statistics

| Agent | Tools | Integration Type | Credentials Required |
|-------|-------|------------------|---------------------|
| JIRA Agent | 8 | jira | API Token |
| Confluence Agent | 5 | confluence | API Token |
| SharePoint Agent | 6 | sharepoint | OAuth2 (Microsoft) |
| GitLab Agent | 26 | gitlab | Personal Access Token |
| Trello Agent | 12 | trello | API Key + Token |
| SAP C4C Agent | 5 | sap_c4c | Username + Password |
| Projektron Agent | 1 | projektron | Username + Cookies (CSRF/JSESSION) |
| System Tools Agent | 13 | system | None (Platform-internal) |
| **Total** | **76** | 8 types | 7 external + 1 internal |

## Future Enhancements

### Potential Additions

1. **New Integrations:**
   - GitHub Agent (similar to GitLab)
   - Slack Agent (messaging, channels)
   - Microsoft Teams Agent (chats, meetings)
   - Google Drive Agent (similar to SharePoint)

2. **Enhanced Capabilities:**
   - Batch operations across multiple items
   - Advanced analytics and reporting
   - Automated workflow triggers
   - Cross-integration data sync

3. **Improved Intelligence:**
   - Learning from user patterns
   - Proactive suggestions
   - Smart defaults based on history
   - Context-aware routing

### Migration Path

When adding new agents:
1. Follow the agent template structure
2. Inherit webhook constraint
3. Implement tool discovery pattern
4. Add to Main Agent routing
5. Update this README
6. Test thoroughly

## n8n Implementation

For the complete n8n workflow implementation of this multi-agent system:

- **Implementation Guide:** See `/docs/agents/n8n/README.md`
- **Architecture Details:** See `/docs/agents/n8n/workflow_design.md`
- **Workflow Files:**
  - Main Agent: `/docs/agents/n8n/main_agent.json`
  - Jira Agent: `/docs/agents/n8n/jira_agent.json`

The n8n folder contains ready-to-import workflow JSON files with:
- Improved tool descriptions for better AI routing
- Standard parameter schemas (tenantID, userID, locale, userPrompt, taskDescription)
- Stateless sub-agent design
- Data-passing patterns for multi-agent coordination

## Support and Contact

For questions or issues:
- **Integration Platform:** https://subscribe-workflows.vcec.cloud
- **Repository:** /workoflow-integration-platform
- **System Prompts:** `/docs/agents/system_prompts/`
- **n8n Workflows:** `/docs/agents/n8n/`

## References

- [n8n Documentation](https://docs.n8n.io/)
- [Jira REST API](https://developer.atlassian.com/cloud/jira/platform/rest/v3/)
- [Confluence REST API](https://developer.atlassian.com/cloud/confluence/rest/v1/)
- [Microsoft Graph API (SharePoint)](https://learn.microsoft.com/en-us/graph/api/overview)
- [GitLab API](https://docs.gitlab.com/ee/api/)
- [Trello API](https://developer.atlassian.com/cloud/trello/rest/)

---

**Last Updated:** 2025-01-14
**Version:** 1.0.0
**Maintainer:** Development Team
