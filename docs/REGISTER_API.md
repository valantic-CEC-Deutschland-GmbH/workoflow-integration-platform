# Registration API Documentation

## Overview

The Registration API endpoint allows external systems to automatically create users and organizations in the Workoflow Integration Platform. Upon successful registration, the API returns a magic link URL that provides instant authentication for the user.

This approach enables seamless onboarding by creating users immediately when the API is called, rather than waiting for them to click a link.

## Endpoint Details

### URL
```
POST /api/register
```

### Authentication
Basic Authentication is required. Use the API credentials configured in your environment:
- Username: `API_AUTH_USER` (from .env)
- Password: `API_AUTH_PASSWORD` (from .env)

### Content-Type
`application/json`

### Request Payload

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | User's full name (e.g., "John Doe") |
| `org_uuid` | string | Yes | Organization UUID (must be a valid UUID format) |
| `workflow_user_id` | string | Yes | External workflow system user identifier |
| `org_name` | string | No | Organization display name (defaults to "Organisation {uuid-prefix}") |
| `channel_uuid` | string | No | Channel UUID to associate user with (creates channel if doesn't exist) |
| `channel_name` | string | No | Channel name (used when creating new channel or with channel_uuid) |

#### Example Request Body
```json
{
    "name": "John Doe",
    "org_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "workflow_user_id": "WF-USER-12345",
    "org_name": "Acme Corporation",
    "channel_uuid": "channel-550e8400-e29b-41d4-a716-446655440001",
    "channel_name": "General"
}
```

### Response

#### Success Response (201 Created)
```json
{
    "success": true,
    "magic_link": "https://app.workoflow.com/auth/magic-link?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user_id": 123,
    "email": "john.doe@local.local",
    "organisation": {
        "id": 456,
        "uuid": "550e8400-e29b-41d4-a716-446655440000",
        "name": "Acme Corporation"
    },
    "channel": {
        "id": 789,
        "uuid": "channel-550e8400-e29b-41d4-a716-446655440001",
        "name": "General"
    }
}
```

Note: The `channel` object in the response is only present if `channel_uuid` or `channel_name` was provided in the request.

#### Error Responses

**400 Bad Request** - Missing required fields
```json
{
    "success": false,
    "error": "Missing required fields. Required: name, org_uuid, workflow_user_id"
}
```

**401 Unauthorized** - Invalid authentication
```json
{
    "success": false,
    "error": "Unauthorized"
}
```

**500 Internal Server Error** - Registration failure
```json
{
    "success": false,
    "error": "Registration failed: [error details]"
}
```

## Integration Examples

### cURL Example
```bash
curl -X POST https://app.workoflow.com/api/register \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'username:password' | base64)" \
  -d '{
    "name": "John Doe",
    "org_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "workflow_user_id": "WF-USER-12345",
    "org_name": "Acme Corporation",
    "channel_uuid": "channel-550e8400-e29b-41d4-a716-446655440001",
    "channel_name": "General"
  }'
```

### PHP Example
```php
<?php
$apiUrl = 'https://app.workoflow.com/api/register';
$apiUser = 'your_api_user';
$apiPassword = 'your_api_password';

$data = [
    'name' => 'John Doe',
    'org_uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'workflow_user_id' => 'WF-USER-12345',
    'org_name' => 'Acme Corporation'
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Basic ' . base64_encode($apiUser . ':' . $apiPassword)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($httpCode === 201 && $result['success']) {
    // Provide the magic link to the user
    $magicLink = $result['magic_link'];
    // Redirect user or send link via email
    header('Location: ' . $magicLink);
} else {
    // Handle error
    echo "Registration failed: " . $result['error'];
}
```

### JavaScript/Node.js Example
```javascript
const axios = require('axios');

async function registerUser(userData) {
    const apiUrl = 'https://app.workoflow.com/api/register';
    const apiUser = process.env.API_AUTH_USER;
    const apiPassword = process.env.API_AUTH_PASSWORD;

    try {
        const response = await axios.post(apiUrl, userData, {
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Basic ' + Buffer.from(`${apiUser}:${apiPassword}`).toString('base64')
            }
        });

        if (response.data.success) {
            // Use the magic link
            console.log('Magic Link:', response.data.magic_link);
            return response.data;
        }
    } catch (error) {
        console.error('Registration failed:', error.response?.data?.error || error.message);
        throw error;
    }
}

// Usage
registerUser({
    name: 'John Doe',
    org_uuid: '550e8400-e29b-41d4-a716-446655440000',
    workflow_user_id: 'WF-USER-12345',
    org_name: 'Acme Corporation'
}).then(result => {
    // Redirect user to magic link or handle as needed
    window.location.href = result.magic_link;
});
```

## Workflow Integration

### Typical Integration Flow

1. **External System Triggers Registration**
   - Your workflow system determines a user needs access
   - Collects user information (name, org UUID, workflow user ID)

2. **Call Registration API**
   - Make POST request to `/api/register` with user data
   - Receive magic link URL in response

3. **Provide Magic Link to User**
   - Option A: Redirect user immediately to the magic link
   - Option B: Send magic link via email/SMS
   - Option C: Display link in your application UI

4. **User Clicks Magic Link**
   - User is automatically authenticated
   - Session is established
   - User lands on the dashboard

### Multiple User Registration

For bulk user registration, call the endpoint sequentially for each user:

```javascript
async function registerMultipleUsers(users) {
    const results = [];

    for (const user of users) {
        try {
            const result = await registerUser(user);
            results.push({
                success: true,
                user: user.name,
                magicLink: result.magic_link
            });
        } catch (error) {
            results.push({
                success: false,
                user: user.name,
                error: error.message
            });
        }
    }

    return results;
}
```

## Authentication Lifecycle

### Magic Link Token Validity
- **Duration**: 24 hours from generation
- **Reusability**: Can be used multiple times within the validity period
- **Use Case**: Allows users to bookmark the link or retry if initial login fails
- **Security**: JWT tokens with cryptographic signatures prevent tampering

### Session Persistence
- **Duration**: 24 hours from last authentication
- **Type**: Server-side sessions stored in Redis
- **Cookie**: Secure, HttpOnly, SameSite=Lax settings for protection
- **Behavior**: Sessions remain active for full 24 hours regardless of activity

### Complete Authentication Flow
1. **API Call**: External system calls `/api/register` with user data
2. **Token Generation**: Magic link with 24-hour JWT token created
3. **First Login**: User clicks link and establishes 24-hour session
4. **Subsequent Access**: User can reuse same link within 24 hours if needed
5. **Session Expiry**: After 24 hours, user needs new magic link from API

### Timeline Example
```
Day 1, 10:00 AM - API generates magic link (valid until Day 2, 10:00 AM)
Day 1, 11:00 AM - User clicks link, session established (valid until Day 2, 11:00 AM)
Day 1, 03:00 PM - User can click same link again if needed
Day 2, 09:00 AM - Original link still valid, can establish new session
Day 2, 10:01 AM - Link expired, need to call API for new link
Day 2, 11:01 AM - Session expired, user logged out
```

## Security Considerations

### Authentication
- Always use HTTPS in production
- Store API credentials securely (environment variables, secrets management)
- Rotate API credentials regularly
- Never expose API credentials in client-side code

### Magic Links
- Links expire after 24 hours
- Links can be used multiple times within the 24-hour validity period
- Links are cryptographically signed to prevent tampering
- Contains only necessary information (name, org_uuid, workflow_user_id)

### Session Duration
- Once authenticated via magic link, the user session lasts for 24 hours
- Sessions remain active for 24 hours even without activity
- After 24 hours, users need to authenticate again using a new magic link
- Sessions are stored in Redis for optimal performance

### User Creation
- Email addresses are automatically generated in format: `{sanitized.name}@local.local`
- Duplicate registrations update the existing user's workflow_user_id
- Users can belong to multiple organizations
- Organization UUIDs must be valid UUID v4 format

## Migration from Previous Flow

### Before (Click-to-Create Flow)
1. Generate magic link with user data
2. Send link to user
3. User clicks link
4. User and organization created during authentication
5. Session established

### After (API Registration Flow)
1. Call `/api/register` with user data
2. User and organization created immediately
3. Receive magic link in response
4. Send link to user
5. User clicks link for instant authentication (already exists in DB)

### Benefits of New Flow
- **Predictable User Lifecycle**: Users exist before first login
- **Better Integration**: External systems know immediately if registration succeeded
- **Pre-configuration**: Can set up user permissions/settings before first login
- **Audit Trail**: Clear registration timestamp separate from first login
- **Bulk Operations**: Can register multiple users programmatically

## Error Handling

### Retry Logic
Implement exponential backoff for transient failures:

```javascript
async function registerWithRetry(userData, maxRetries = 3) {
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            return await registerUser(userData);
        } catch (error) {
            if (attempt === maxRetries) throw error;

            // Exponential backoff: 1s, 2s, 4s
            const delay = Math.pow(2, attempt - 1) * 1000;
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }
}
```

### Common Issues and Solutions

| Issue | Solution |
|-------|----------|
| 401 Unauthorized | Verify API credentials are correct and properly encoded |
| 400 Bad Request | Ensure all required fields are present and properly formatted |
| Invalid UUID | Use standard UUID v4 format (8-4-4-4-12 hexadecimal digits) |
| Duplicate registration | Safe to call multiple times; updates workflow_user_id |
| Magic link expired | Call API again to generate a new link |

## Testing

### Test Credentials
For development/testing environments, use the test authentication:
```
Username: test_api_user
Password: test_api_password
```

### Test Data
```json
{
    "name": "Test User",
    "org_uuid": "test-0000-0000-0000-000000000000",
    "workflow_user_id": "TEST-WF-001",
    "org_name": "Test Organization"
}
```

### Verification Steps
1. Call `/api/register` endpoint
2. Verify 201 status code
3. Check response contains magic_link
4. Verify user exists in database
5. Test magic link authentication
6. Confirm session established

## Support

For technical support or questions about the Registration API:
- Documentation: https://docs.workoflow.com/api/register
- Support Email: support@workoflow.com
- GitHub Issues: https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-integration-platform/issues