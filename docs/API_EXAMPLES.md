# Integration API Examples

## Authentication
All requests require Basic Authentication:
- Username: `workoflow`
- Password: `workoflow`

## Base URL
```
https://your-domain.com/api/integrations/{orgUuid}
```

Replace `{orgUuid}` with your organization's UUID (visible in Organization Settings).

## 1. List Available Tools

**Endpoint:** `GET /api/integrations/{orgUuid}/?workflow_user_id={workflow-user-id}`

### Get All Tools (Default)

**Example Request:**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

### Filter by Integration Type

**Get only Jira tools:**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=jira' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

**Get only Confluence tools:**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=confluence' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

**Get all system tools:**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=system' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

**Get specific system integration tools (e.g., file sharing):**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=system.share_file' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

### Filter Multiple Integration Types (CSV)

**Get tools from multiple integrations at once:**

**Example 1: Get Jira and Confluence tools:**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=jira,confluence' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

**Example 2: Get all user integrations (Jira, Confluence, SharePoint):**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=jira,confluence,sharepoint' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

**Example 3: Get system tools plus specific user integration:**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=system,jira' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

**Example 4: Get specific system tool plus user integrations:**
```bash
curl -X GET \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/?workflow_user_id=user123&tool_type=system.share_file,jira,confluence' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Accept: application/json'
```

**Available Integration Types:**
- **User Integrations:** `jira`, `confluence`, `sharepoint`, `gitlab`, `trello`
- **System Tools:** `system` (all system tools) or `system.share_file`, `system.read_file_tool`, `system.report_issue_tool`, `system.clear_memory_tool`, `system.content_query`, `system.content_learn`, `system.searxng_tool`, `system.fetch_valantic_events`, `system.employee_profile`, `system.employees_query_v2`, `system.read_page_tool`, `system.generate_and_upload_pdf`, `system.generate_pptx`

**CSV Filtering Rules:**
- Separate multiple types with commas (no spaces)
- Values are case-sensitive and must match exactly
- Empty values are ignored (e.g., `jira,,confluence` works the same as `jira,confluence`)
- Duplicate values are automatically removed
- Whitespace around values is automatically trimmed

**Note:** When filtering by `tool_type`, the API returns only tools from that specific integration. If you get an empty array:
- The integration might be disabled
- Individual tools within the integration might be disabled
- The workflow_user_id might not have access to that integration
- The tool_type might not match any configured integration (check exact spelling)

**Example Response:**
```json
{
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "jira_search_123",
        "description": "Search for Jira issues using JQL (My Jira Workspace)",
        "parameters": {
          "type": "object",
          "properties": {
            "jql": {
              "type": "string",
              "description": "JQL query string"
            },
            "maxResults": {
              "type": "integer",
              "description": "Maximum number of results",
              "default": 50
            }
          },
          "required": ["jql"]
        }
      }
    },
    {
      "type": "function",
      "function": {
        "name": "confluence_search_456",
        "description": "Search Confluence pages using CQL (My Confluence Space)",
        "parameters": {
          "type": "object",
          "properties": {
            "query": {
              "type": "string",
              "description": "CQL query string"
            },
            "limit": {
              "type": "integer",
              "description": "Maximum number of results",
              "default": 25
            }
          },
          "required": ["query"]
        }
      }
    },
    {
      "type": "function",
      "function": {
        "name": "share_file",
        "description": "Share a file and get a download URL",
        "parameters": {
          "type": "object",
          "properties": {
            "filename": {
              "type": "string",
              "description": "Name of the file to share"
            },
            "content": {
              "type": "string",
              "description": "Base64 encoded file content"
            },
            "mime_type": {
              "type": "string",
              "description": "MIME type of the file"
            },
            "expires_in": {
              "type": "integer",
              "description": "Expiration time in seconds (max 86400)",
              "default": 3600
            }
          },
          "required": ["filename", "content"]
        }
      }
    }
  ]
}
```

## 2. Execute a Tool

**Endpoint:** `POST /api/integrations/{orgUuid}/execute?workflow_user_id={workflow-user-id}`

### Example 1: Jira Search

**Request:**
```bash
curl -X POST \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/execute?workflow_user_id=user123' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "tool": "jira_search_123",
    "parameters": {
      "jql": "project = PROJ AND status = Open",
      "maxResults": 10
    }
  }'
```

**Success Response:**
```json
{
  "success": true,
  "result": {
    "issues": [
      {
        "key": "PROJ-123",
        "summary": "Example issue",
        "status": "Open",
        "assignee": "john.doe@example.com"
      }
    ],
    "total": 1
  }
}
```

### Example 2: Confluence Page Search

**Request:**
```bash
curl -X POST \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/execute?workflow_user_id=user123' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "tool": "confluence_search_456",
    "parameters": {
      "query": "type=page AND text ~ \"documentation\"",
      "limit": 5
    }
  }'
```

### Example 3: Share File (System Tool)

**Request:**
```bash
curl -X POST \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/execute?workflow_user_id=user123' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "tool": "share_file",
    "parameters": {
      "filename": "report.pdf",
      "content": "base64_encoded_file_content_here",
      "mime_type": "application/pdf",
      "expires_in": 7200
    }
  }'
```

**Success Response:**
```json
{
  "success": true,
  "result": {
    "url": "https://your-domain.com/files/download/abc123def456",
    "expires_at": "2024-07-03T14:30:00Z"
  }
}
```

### Example 4: Get Jira Issue Details

**Request:**
```bash
curl -X POST \
  'http://localhost:3979/api/integrations/550e8400-e29b-41d4-a716-446655440000/execute?workflow_user_id=user123' \
  -H 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "tool": "jira_get_issue_123",
    "parameters": {
      "issueKey": "PROJ-123"
    }
  }'
```

## Error Responses

### Missing Authentication
```json
{
  "error": "Unauthorized"
}
```
HTTP Status: 401

### Invalid Organization UUID
```json
{
  "error": "Organisation not found"
}
```
HTTP Status: 404

### Missing Workflow User ID
```json
{
  "error": "Missing workflow user ID parameter"
}
```
HTTP Status: 400

### Tool Not Found
```json
{
  "error": "Integration not found or inactive"
}
```
HTTP Status: 404

### Tool Execution Error
```json
{
  "success": false,
  "error": "Connection failed: Unable to reach Jira instance"
}
```
HTTP Status: 500

## Integration with n8n

In n8n, you can use the HTTP Request node with these settings:

1. **Method**: GET or POST
2. **URL**: `https://your-domain.com/api/integrations/{orgUuid}/?workflow_user_id={workflow-user-id}`
3. **Authentication**: Basic Auth
   - Username: `workoflow`
   - Password: `workoflow`
4. **Headers**:
   - Content-Type: `application/json` (for POST requests)
   - Accept: `application/json`

### n8n Workflow Example

1. **HTTP Request Node** - List available tools
2. **Function Node** - Parse tools and select one
3. **HTTP Request Node** - Execute selected tool
4. **Process results** - Use the API response in your workflow
