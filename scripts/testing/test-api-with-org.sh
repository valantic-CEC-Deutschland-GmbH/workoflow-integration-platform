#!/bin/bash

# Test Integration API with organization UUID

# Use a test org UUID (you'll need to replace this with a real one)
ORG_UUID="550e8400-e29b-41d4-a716-446655440000"
WORKFLOW_USER_ID="test-user-123"
BASE_URL="http://localhost:3979/api/integration/$ORG_UUID"
AUTH_HEADER="Authorization: Basic xxxx=="

echo "=== Testing Integration API ==="
echo "Organization UUID: $ORG_UUID"
echo "Workflow User ID: $WORKFLOW_USER_ID"
echo ""

echo "1. Testing LIST TOOLS endpoint..."
echo "Request: GET $BASE_URL/tools?id=$WORKFLOW_USER_ID"
curl -X GET \
  "$BASE_URL/tools?id=$WORKFLOW_USER_ID" \
  -H "$AUTH_HEADER" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n" \
  2>/dev/null | jq . || echo "Response: $(curl -X GET "$BASE_URL/tools?id=$WORKFLOW_USER_ID" -H "$AUTH_HEADER" -H "Accept: application/json" 2>/dev/null)"

echo -e "\n\n2. Testing EXECUTE TOOL endpoint..."
echo "Request: POST $BASE_URL/execute?id=$WORKFLOW_USER_ID"
echo "Body: {\"tool_id\": \"jira_search_1\", \"parameters\": {\"jql\": \"project = TEST\", \"maxResults\": 5}}"

curl -X POST \
  "$BASE_URL/execute?id=$WORKFLOW_USER_ID" \
  -H "$AUTH_HEADER" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "tool_id": "jira_search_1",
    "parameters": {
      "jql": "project = TEST",
      "maxResults": 5
    }
  }' \
  -w "\nHTTP Status: %{http_code}\n" \
  2>/dev/null | jq . || echo "Response: $(curl -X POST "$BASE_URL/execute?id=$WORKFLOW_USER_ID" -H "$AUTH_HEADER" -H "Content-Type: application/json" -H "Accept: application/json" -d '{"tool_id": "jira_search_1", "parameters": {"jql": "project = TEST", "maxResults": 5}}' 2>/dev/null)"

echo -e "\n\n3. Testing without authentication (should fail)..."
curl -X GET \
  "$BASE_URL/tools?id=$WORKFLOW_USER_ID" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n" \
  2>/dev/null | jq . || echo "Response: $(curl -X GET "$BASE_URL/tools?id=$WORKFLOW_USER_ID" -H "Accept: application/json" 2>/dev/null)"

echo -e "\n\n4. Testing without workflow user ID (should fail)..."
curl -X GET \
  "$BASE_URL/tools" \
  -H "$AUTH_HEADER" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n" \
  2>/dev/null | jq . || echo "Response: $(curl -X GET "$BASE_URL/tools" -H "$AUTH_HEADER" -H "Accept: application/json" 2>/dev/null)"

echo -e "\n\nTests completed!"
