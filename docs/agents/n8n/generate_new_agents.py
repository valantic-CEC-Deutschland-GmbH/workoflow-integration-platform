#!/usr/bin/env python3
"""
Generate n8n workflow JSON files for MS Teams, Outlook Calendar, and Outlook Mail agents.
Also generates an updated main agent JSON with the 3 new tool nodes.
"""

import json
import uuid
import os

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
TEMPLATE_DIR = os.path.join(os.path.dirname(SCRIPT_DIR), '..', '..', 'templates', 'skills', 'prompts')

def read_twig_template(filename):
    """Read a Twig template and convert it for n8n usage."""
    filepath = os.path.join(TEMPLATE_DIR, filename)
    with open(filepath, 'r') as f:
        content = f.read()

    # Replace Twig variables with n8n expressions or static values
    # {{ 'now'|date('Y-m-d') }} -> 2026-02-10
    content = content.replace("{{ 'now'|date('Y-m-d') }}", "2026-02-10")
    # {{ tool_count }} -> (dynamic)
    content = content.replace("{{ tool_count }}", "(dynamic)")
    # {{ integration_id }} -> {integration_id} (single braces to avoid n8n evaluation)
    content = content.replace("{{ integration_id }}", "{integration_id}")

    return content


def add_context_section(xml_content, insert_after_tag="</stateless-operation>"):
    """Insert <context> section with n8n expressions after the specified tag."""
    context_section = """
  <context>
    <user-info>
      <tenant-id>{{ $('When Executed by Another Workflow').item.json.tenantID }}</tenant-id>
      <workflow-user-id>{{ $('When Executed by Another Workflow').item.json.userID }}</workflow-user-id>
    </user-info>
    <current-date>{{ $now.format('dd.MM.yyyy') }}</current-date>
    <default-language>{{ $('When Executed by Another Workflow').item.json.locale }}</default-language>
  </context>
"""
    if insert_after_tag in xml_content:
        xml_content = xml_content.replace(
            insert_after_tag,
            insert_after_tag + context_section
        )
    else:
        # Fallback: insert before </system-prompt>
        xml_content = xml_content.replace(
            "</system-prompt>",
            context_section + "\n</system-prompt>"
        )
    return xml_content


def add_tool_discovery_section(xml_content, tool_type, insert_after_tag="</context>"):
    """Insert <tool-discovery> section with n8n URLs."""
    discovery_section = f"""
  <tool-discovery>
    <endpoint>
      <url>https://subscribe-workflows.vcec.cloud/api/integrations/{{{{ $('When Executed by Another Workflow').item.json.tenantID }}}}/?workflow_user_id={{{{ $('When Executed by Another Workflow').item.json.userID }}}}&amp;tool_type={tool_type}</url>
      <method>GET</method>
      <purpose>Fetch available tools for this user</purpose>
    </endpoint>
    <execution>
      <url>https://subscribe-workflows.vcec.cloud/api/integrations/{{{{ $('When Executed by Another Workflow').item.json.tenantID }}}}/execute?workflow_user_id={{{{ $('When Executed by Another Workflow').item.json.userID }}}}</url>
      <method>POST</method>
      <purpose>Execute a specific tool</purpose>
    </execution>
  </tool-discovery>
"""
    if insert_after_tag in xml_content:
        xml_content = xml_content.replace(
            insert_after_tag,
            insert_after_tag + discovery_section
        )
    return xml_content


def create_sub_agent_json(system_prompt, tool_type, service_name):
    """Create a sub-agent workflow JSON following the standard template."""

    # Generate unique UUIDs for all nodes
    ids = {
        'trigger': str(uuid.uuid4()),
        'agent': str(uuid.uuid4()),
        'llm': str(uuid.uuid4()),
        'tools': str(uuid.uuid4()),
        'execute': str(uuid.uuid4()),
    }

    workflow = {
        "nodes": [
            {
                "parameters": {
                    "workflowInputs": {
                        "values": [
                            {"name": "userID"},
                            {"name": "tenantID"},
                            {"name": "locale"},
                            {"name": "userPrompt"},
                            {"name": "taskDescription"}
                        ]
                    }
                },
                "type": "n8n-nodes-base.executeWorkflowTrigger",
                "typeVersion": 1.1,
                "position": [-144, -256],
                "id": ids['trigger'],
                "name": "When Executed by Another Workflow"
            },
            {
                "parameters": {
                    "promptType": "define",
                    "text": "={{ $json.taskDescription }}",
                    "options": {
                        "systemMessage": "=" + system_prompt,
                        "maxIterations": 15
                    }
                },
                "type": "@n8n/n8n-nodes-langchain.agent",
                "typeVersion": 2.2,
                "position": [48, -256],
                "id": ids['agent'],
                "name": "AI Agent1"
            },
            {
                "parameters": {
                    "model": "gpt-4.1",
                    "options": {
                        "maxRetries": 2
                    }
                },
                "type": "@n8n/n8n-nodes-langchain.lmChatAzureOpenAi",
                "typeVersion": 1,
                "position": [48, -80],
                "id": ids['llm'],
                "name": "workoflow-proxy1",
                "credentials": {
                    "azureOpenAiApi": {
                        "id": "cLd5WshSqySpWGKz",
                        "name": "azure-open-ai-proxy"
                    }
                }
            },
            {
                "parameters": {
                    "toolDescription": f"Always call this tool FIRST to understand what {service_name} tools are available for this user. This returns a dynamic list of tools based on the user's {service_name} integration configuration. The response will show you which tools you can execute via CURRENT_USER_EXECUTE_TOOL. If the tools array is empty, the user hasn't configured a {service_name} integration yet.",
                    "url": f"=https://subscribe-workflows.vcec.cloud/api/integrations/{{{{ $('When Executed by Another Workflow').item.json.tenantID }}}}/?workflow_user_id={{{{ $('When Executed by Another Workflow').item.json.userID }}}}&tool_type={tool_type}",
                    "authentication": "genericCredentialType",
                    "genericAuthType": "httpBasicAuth",
                    "options": {}
                },
                "type": "n8n-nodes-base.httpRequestTool",
                "typeVersion": 4.2,
                "position": [400, 64],
                "id": ids['tools'],
                "name": "CURRENT_USER_TOOLS1",
                "credentials": {
                    "httpBasicAuth": {
                        "id": "6DYEkcLl6x47zCLE",
                        "name": "Workoflow Integration Platform"
                    }
                }
            },
            {
                "parameters": {
                    "toolDescription": f"Execute a {service_name} tool after discovering available tools with CURRENT_USER_TOOLS. You must specify the tool_id from the discovery response and provide all required parameters as strings. Analyze the tool's parameter schema from CURRENT_USER_TOOLS to know which parameters to include.",
                    "method": "POST",
                    "url": "=https://subscribe-workflows.vcec.cloud/api/integrations/{{ $('When Executed by Another Workflow').item.json.tenantID }}/execute?workflow_user_id={{ $('When Executed by Another Workflow').item.json.userID }}",
                    "authentication": "genericCredentialType",
                    "genericAuthType": "httpBasicAuth",
                    "sendBody": True,
                    "specifyBody": "json",
                    "jsonBody": "={\n  \"tool_id\": \"{{ $fromAI('toolId', 'The exact tool_id from CURRENT_USER_TOOLS response (e.g., " + tool_type + "_search_123)', 'string') }}\",\n  \"parameters\": {{ JSON.stringify($fromAI('parameters', 'Tool parameters as a JSON object. All values must be strings, not numbers. Refer to the tool schema from CURRENT_USER_TOOLS to know which parameters are required.', 'json')) }}\n}",
                    "options": {}
                },
                "type": "n8n-nodes-base.httpRequestTool",
                "typeVersion": 4.2,
                "position": [576, 64],
                "id": ids['execute'],
                "name": "CURRENT_USER_EXECUTE_TOOL1",
                "credentials": {
                    "httpBasicAuth": {
                        "id": "6DYEkcLl6x47zCLE",
                        "name": "Workoflow Integration Platform"
                    }
                }
            }
        ],
        "connections": {
            "When Executed by Another Workflow": {
                "main": [[
                    {"node": "AI Agent1", "type": "main", "index": 0}
                ]]
            },
            "AI Agent1": {
                "main": [[]]
            },
            "workoflow-proxy1": {
                "ai_languageModel": [[
                    {"node": "AI Agent1", "type": "ai_languageModel", "index": 0}
                ]]
            },
            "CURRENT_USER_TOOLS1": {
                "ai_tool": [[
                    {"node": "AI Agent1", "type": "ai_tool", "index": 0}
                ]]
            },
            "CURRENT_USER_EXECUTE_TOOL1": {
                "ai_tool": [[
                    {"node": "AI Agent1", "type": "ai_tool", "index": 0}
                ]]
            }
        },
        "pinData": {
            "When Executed by Another Workflow": [
                {
                    "userID": "asjgajsjgasjj",
                    "tenantID": "0cd3a714-adc1-4540-bd08-7316a80b34f3",
                    "locale": "de",
                    "userPrompt": "test",
                    "taskDescription": "gg"
                }
            ]
        },
        "meta": {
            "instanceId": "c05075230b6e7924fad6850755c864f4945f3a365e0e5e0749c41c356f32c0bc"
        }
    }

    return workflow


def create_tool_workflow_node(name, description, workflow_id_placeholder, position, task_description_hint):
    """Create a toolWorkflow node for the main agent."""

    node_id = str(uuid.uuid4())

    standard_schema = [
        {"id": "userID", "displayName": "userID", "required": False, "defaultMatch": False, "display": True, "canBeUsedToMatch": True, "type": "string", "removed": False},
        {"id": "tenantID", "displayName": "tenantID", "required": False, "defaultMatch": False, "display": True, "canBeUsedToMatch": True, "type": "string", "removed": False},
        {"id": "locale", "displayName": "locale", "required": False, "defaultMatch": False, "display": True, "canBeUsedToMatch": True, "type": "string", "removed": False},
        {"id": "userPrompt", "displayName": "userPrompt", "required": False, "defaultMatch": False, "display": True, "canBeUsedToMatch": True, "type": "string", "removed": False},
        {"id": "taskDescription", "displayName": "taskDescription", "required": False, "defaultMatch": False, "display": True, "canBeUsedToMatch": True, "type": "string", "removed": False},
        {"id": "previousAgentData", "displayName": "previousAgentData", "required": False, "defaultMatch": False, "display": True, "canBeUsedToMatch": True, "type": "string", "removed": False},
    ]

    return {
        "parameters": {
            "description": description,
            "workflowId": {
                "__rl": True,
                "value": workflow_id_placeholder,
                "mode": "list",
                "cachedResultUrl": f"/workflow/{workflow_id_placeholder}",
                "cachedResultName": name
            },
            "workflowInputs": {
                "mappingMode": "defineBelow",
                "value": {
                    "tenantID": "={{ $('SET USER').item.json.tenantId }}",
                    "userID": "={{ $('SET USER').item.json.userId }}",
                    "locale": "={{ $('SET USER').item.json.locale }}",
                    "userPrompt": "={{ $('SET USER').item.json.chatInput }}",
                    "taskDescription": "={{ $fromAI('taskDescription', '" + task_description_hint + "', 'string') }}",
                    "previousAgentData": "={{ $fromAI(\"previousAgentData\", \"If this is a multi-agent workflow and a previous agent (JIRA, Confluence, SharePoint, GitLab, Trello, MS Teams, Outlook Calendar, Outlook Mail, System Tools) returned data in its response, construct a previousAgentData object: {agentType: \\\"jira|confluence|sharepoint|gitlab|trello|msteams|outlook_calendar|outlook_mail|system\\\", data: {the data object from previous agent response}, context: \\\"brief description of the data\\\"}. IMPORTANT: Extract data from the previous tool response.data field if available. Return null if no previous agent was called or response.data does not exist.\", \"string\") }}"
                },
                "matchingColumns": [],
                "schema": standard_schema,
                "attemptToConvertTypes": False,
                "convertFieldsToString": False
            }
        },
        "type": "@n8n/n8n-nodes-langchain.toolWorkflow",
        "typeVersion": 2.2,
        "position": position,
        "id": node_id,
        "name": name
    }


def main():
    output_dir = SCRIPT_DIR

    # =========================================================================
    # 1. MS Teams Sub-Agent
    # =========================================================================
    print("Generating MS Teams agent...")
    msteams_prompt = read_twig_template('msteams_full.xml.twig')
    msteams_prompt = add_context_section(msteams_prompt)
    msteams_prompt = add_tool_discovery_section(msteams_prompt, 'msteams')

    msteams_workflow = create_sub_agent_json(
        system_prompt=msteams_prompt,
        tool_type='msteams',
        service_name='MS Teams',
    )

    with open(os.path.join(output_dir, 'msteams_agent.json'), 'w') as f:
        json.dump(msteams_workflow, f, indent=2, ensure_ascii=False)
    print(f"  -> {os.path.join(output_dir, 'msteams_agent.json')}")

    # =========================================================================
    # 2. Outlook Calendar Sub-Agent
    # =========================================================================
    print("Generating Outlook Calendar agent...")
    outlook_cal_prompt = read_twig_template('outlook_calendar_full.xml.twig')
    outlook_cal_prompt = add_context_section(outlook_cal_prompt)
    outlook_cal_prompt = add_tool_discovery_section(outlook_cal_prompt, 'outlook_calendar')

    outlook_cal_workflow = create_sub_agent_json(
        system_prompt=outlook_cal_prompt,
        tool_type='outlook_calendar',
        service_name='Outlook Calendar',
    )

    with open(os.path.join(output_dir, 'outlook_calendar_agent.json'), 'w') as f:
        json.dump(outlook_cal_workflow, f, indent=2, ensure_ascii=False)
    print(f"  -> {os.path.join(output_dir, 'outlook_calendar_agent.json')}")

    # =========================================================================
    # 3. Outlook Mail Sub-Agent
    # =========================================================================
    print("Generating Outlook Mail agent...")
    outlook_mail_prompt = read_twig_template('outlook_mail_full.xml.twig')
    outlook_mail_prompt = add_context_section(outlook_mail_prompt)
    outlook_mail_prompt = add_tool_discovery_section(outlook_mail_prompt, 'outlook_mail')

    outlook_mail_workflow = create_sub_agent_json(
        system_prompt=outlook_mail_prompt,
        tool_type='outlook_mail',
        service_name='Outlook Mail',
    )

    with open(os.path.join(output_dir, 'outlook_mail_agent.json'), 'w') as f:
        json.dump(outlook_mail_workflow, f, indent=2, ensure_ascii=False)
    print(f"  -> {os.path.join(output_dir, 'outlook_mail_agent.json')}")

    # =========================================================================
    # 4. Updated Main Agent
    # =========================================================================
    print("Generating updated main agent...")

    with open(os.path.join(output_dir, 'main_agent.json'), 'r') as f:
        main_agent = json.load(f)

    # Create 3 new tool workflow nodes
    msteams_node = create_tool_workflow_node(
        name="MS Teams Agent",
        description="Use this tool when the user wants to interact with Microsoft Teams. This includes:\n- Listing teams and channels\n- Reading channel messages\n- Sending messages to channels\n- Creating new channels\n- Listing and reading 1:1 and group chats\n- Sending chat messages\n\nKeywords: teams, microsoft teams, ms teams, channel, chat, message, send message, team, group chat, direct message",
        workflow_id_placeholder="PLACEHOLDER_MSTEAMS_WORKFLOW_ID",
        position=[720, 992],
        task_description_hint="Based on the user request and conversation history, what specific MS Teams-related task should this agent perform? Be precise and include all necessary context. If this is a multi-agent workflow, only describe the MS Teams portion."
    )

    outlook_cal_node = create_tool_workflow_node(
        name="Outlook Calendar Agent",
        description="Use this tool when the user wants to interact with Outlook Calendar. This includes:\n- Listing calendar events within a date range\n- Getting event details\n- Searching for events by subject\n- Checking free/busy availability for people\n- Listing available calendars\n\nKeywords: outlook, calendar, event, meeting, appointment, schedule, termin, availability, free busy, kalender, besprechung",
        workflow_id_placeholder="PLACEHOLDER_OUTLOOK_CALENDAR_WORKFLOW_ID",
        position=[896, 992],
        task_description_hint="Based on the user request and conversation history, what specific Outlook Calendar-related task should this agent perform? Be precise and include all necessary context. If this is a multi-agent workflow, only describe the Outlook Calendar portion."
    )

    outlook_mail_node = create_tool_workflow_node(
        name="Outlook Mail Agent",
        description="Use this tool when the user wants to interact with Outlook Mail. This includes:\n- Searching emails using keywords\n- Reading full email content\n- Listing mail folders\n- Listing messages in folders with pagination\n\nKeywords: outlook, mail, email, e-mail, inbox, nachricht, postfach, search email, read email, folder, posteingang",
        workflow_id_placeholder="PLACEHOLDER_OUTLOOK_MAIL_WORKFLOW_ID",
        position=[1072, 992],
        task_description_hint="Based on the user request and conversation history, what specific Outlook Mail-related task should this agent perform? Be precise and include all necessary context. If this is a multi-agent workflow, only describe the Outlook Mail portion."
    )

    # Add new nodes to the main agent
    main_agent['nodes'].append(msteams_node)
    main_agent['nodes'].append(outlook_cal_node)
    main_agent['nodes'].append(outlook_mail_node)

    # Add new connections
    main_agent['connections']['MS Teams Agent'] = {
        "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
    }
    main_agent['connections']['Outlook Calendar Agent'] = {
        "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
    }
    main_agent['connections']['Outlook Mail Agent'] = {
        "ai_tool": [[{"node": "AI Agent", "type": "ai_tool", "index": 0}]]
    }

    # Update the main agent system prompt to include new agents in the specialized-agents section
    # Find the AI Agent node and update its system message
    for node in main_agent['nodes']:
        if node['name'] == 'AI Agent':
            current_prompt = node['parameters']['options']['systemMessage']

            # Add new agents to the specialized-agents section (before closing tag)
            new_agents_xml = """
    <agent>
      <name>MS Teams Agent</name>
      <workflow>msteams_agent_workflow</workflow>
      <capabilities>
        - List teams and channels
        - Read channel messages
        - Send messages to channels
        - Create new channels
        - List and read 1:1 and group chats
        - Send chat messages
      </capabilities>
      <keywords>teams, microsoft teams, ms teams, channel, chat, message, send message, team, group chat, direct message</keywords>
      <integration-type>msteams</integration-type>
      <special-note>MS Teams agent can send messages and create channels - these are irreversible write operations</special-note>
    </agent>

    <agent>
      <name>Outlook Calendar Agent</name>
      <workflow>outlook_calendar_agent_workflow</workflow>
      <capabilities>
        - List calendar events within a date range
        - Get event details
        - Search for events by subject
        - Check free/busy availability for people
        - List available calendars
      </capabilities>
      <keywords>outlook, calendar, event, meeting, appointment, schedule, termin, availability, free busy, kalender, besprechung</keywords>
      <integration-type>outlook_calendar</integration-type>
      <special-note>Read-only calendar access - cannot create, modify, or delete events</special-note>
    </agent>

    <agent>
      <name>Outlook Mail Agent</name>
      <workflow>outlook_mail_agent_workflow</workflow>
      <capabilities>
        - Search emails using keywords
        - Read full email content
        - List mail folders
        - List messages in folders with pagination
      </capabilities>
      <keywords>outlook, mail, email, e-mail, inbox, nachricht, postfach, search email, read email, folder, posteingang</keywords>
      <integration-type>outlook_mail</integration-type>
      <special-note>Read-only mail access - cannot send, delete, or modify emails</special-note>
    </agent>"""

            # Insert before </specialized-agents>
            current_prompt = current_prompt.replace(
                "  </specialized-agents>",
                new_agents_xml + "\n  </specialized-agents>"
            )

            # Update the description to mention 10 agents instead of 7
            current_prompt = current_prompt.replace(
                "You have access to 7 specialized agents:",
                "You have access to 10 specialized agents:"
            )
            current_prompt = current_prompt.replace(
                "JIRA Agent, Confluence Agent, SharePoint Agent, GitLab Agent, Trello Agent, and System Tools Agent.",
                "JIRA Agent, Confluence Agent, SharePoint Agent, GitLab Agent, Trello Agent, MS Teams Agent, Outlook Calendar Agent, Outlook Mail Agent, and System Tools Agent."
            )

            # Update routing examples with new agent types
            new_routing_examples = """
    <example>
      <user-request>Send a message to the project channel in Teams</user-request>
      <analysis>
        - Intent: Send a Teams channel message
        - Keywords: \"Teams\", \"channel\", \"message\"
        - Agent needed: MS Teams Agent
        - Single agent task (write operation)
      </analysis>
      <action>Delegate to MS Teams Agent, wait for completion, return confirmation</action>
    </example>

    <example>
      <user-request>What meetings do I have tomorrow?</user-request>
      <analysis>
        - Intent: Query calendar events
        - Keywords: \"meetings\", \"tomorrow\"
        - Agent needed: Outlook Calendar Agent
        - Single agent task
      </analysis>
      <action>Delegate to Outlook Calendar Agent, wait for completion, return events</action>
    </example>

    <example>
      <user-request>Find emails from Sarah about the budget report</user-request>
      <analysis>
        - Intent: Search emails
        - Keywords: \"emails\", \"find\"
        - Agent needed: Outlook Mail Agent
        - Single agent task
      </analysis>
      <action>Delegate to Outlook Mail Agent, wait for completion, return search results</action>
    </example>"""

            # Insert before </routing-examples>
            current_prompt = current_prompt.replace(
                "  </routing-examples>",
                new_routing_examples + "\n  </routing-examples>"
            )

            # Add new agent keywords to routing step 2
            current_prompt = current_prompt.replace(
                "        - General questions → Handle directly without delegation",
                "        - MS Teams keywords → MS Teams Agent\n        - Outlook Calendar keywords → Outlook Calendar Agent\n        - Outlook Mail keywords → Outlook Mail Agent\n        - General questions → Handle directly without delegation"
            )

            node['parameters']['options']['systemMessage'] = current_prompt
            break

    with open(os.path.join(output_dir, 'main_agent_updated.json'), 'w') as f:
        json.dump(main_agent, f, indent=2, ensure_ascii=False)
    print(f"  -> {os.path.join(output_dir, 'main_agent_updated.json')}")

    print("\nDone! Generated 4 files:")
    print("  1. msteams_agent.json - MS Teams sub-agent workflow")
    print("  2. outlook_calendar_agent.json - Outlook Calendar sub-agent workflow")
    print("  3. outlook_mail_agent.json - Outlook Mail sub-agent workflow")
    print("  4. main_agent_updated.json - Updated main orchestrator with 3 new tool nodes")
    print("\nNOTE: After importing sub-agents into n8n, replace these placeholders in main_agent_updated.json:")
    print("  - PLACEHOLDER_MSTEAMS_WORKFLOW_ID")
    print("  - PLACEHOLDER_OUTLOOK_CALENDAR_WORKFLOW_ID")
    print("  - PLACEHOLDER_OUTLOOK_MAIL_WORKFLOW_ID")


if __name__ == '__main__':
    main()
