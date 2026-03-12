# SharePoint Integration Setup Guide

## Prerequisites
1. Ensure the Azure app is configured with the correct redirect URI in Azure Portal:
   - Redirect URI: `http://localhost:3979/integrations/oauth/callback/microsoft`

## Steps to Connect SharePoint:

### 1. Create a SharePoint Integration
First, create a new SharePoint integration through the UI:
- Go to http://localhost:3979/integrations
- Click "Add New Integration"
- Select "SharePoint" as the type
- Enter a name (e.g., "My SharePoint")
- Enter a Workflow User ID (e.g., "test")
- Select the functions you want to enable
- Click Save

### 2. Connect to SharePoint via OAuth2
After creating the integration:
- Go back to the integrations list at http://localhost:3979/integrations
- Find your SharePoint integration
- Click the menu (⋮) button
- Click "Connect to SharePoint"
- You'll be redirected to Microsoft login
- Sign in with your Microsoft/SharePoint account
- Grant the requested permissions
- You'll be redirected back and see a success message

### 3. Test the Integration
Once connected, you can test the SharePoint functions via the API:

```bash
# List available tools
curl --location 'http://localhost:3979/api/integration/YOUR_ORG_UUID/tools?id=YOUR_WORKFLOW_USER_ID' \
--header 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw=='

# Search documents
curl --location 'http://localhost:3979/api/integration/YOUR_ORG_UUID/execute?id=YOUR_WORKFLOW_USER_ID' \
--header 'Content-Type: application/json' \
--header 'Authorization: Basic d29ya29mbG93OndvcmtvZmxvdw==' \
--data '{
    "tool_id": "sharepoint_search_documents_INTEGRATION_ID",
    "parameters": {
      "query": "test",
      "limit": 10
    }
}'
```

## Troubleshooting

### Error: "SharePoint integration is not connected"
This means the OAuth2 flow hasn't been completed. Follow step 2 above to connect.

### Error: "401 Unauthorized" from Microsoft Graph
This could mean:
1. The access token has expired - the system should auto-refresh it
2. The integration was never connected - follow step 2
3. The Azure app permissions are incorrect - check Azure Portal settings

### Finding Your Organization UUID
1. Check the database: 
   ```sql
   SELECT uuid FROM organisation WHERE id = YOUR_ORG_ID;
   ```
2. Or check the URL when viewing organization settings in the UI

## Required Azure App Permissions
Make sure your Azure app has these API permissions:
- Microsoft Graph:
  - Sites.Read.All
  - Files.Read.All
  - User.Read (for authentication)
  - offline_access (for refresh tokens)