# Microsoft 365 Integration Concept: Email, Calendar & MS Teams

## 1. Overview

Extend the Workoflow Platform with three new Microsoft 365 integrations, enabling AI agents to access **Outlook Mail (read)**, **Outlook Calendar (read)**, and **MS Teams (read + write)** on behalf of authenticated users via the Microsoft Graph API.

All three integrations reuse the **existing Azure AD app registration** (`AZURE_CLIENT_ID`) that is already approved for SharePoint. Each integration requests only its own scopes via incremental consent.

---

## 2. Architecture Decision: 3 Separate Integrations

Each Microsoft 365 capability becomes its own integration, matching the platform's existing pattern where each integration has its own OAuth flow, credential storage, and tool set.

| Integration | Type ID | Class Name | Scope of Access |
|---|---|---|---|
| Outlook Mail | `outlook_mail` | `OutlookMailIntegration` | Email read access |
| Outlook Calendar | `outlook_calendar` | `OutlookCalendarIntegration` | Calendar read access |
| MS Teams | `msteams` | `MsTeamsIntegration` | Teams channels + chats (read + write) |

### Why 3 separate integrations (not 1 combined)?

1. **Granular user consent** - Users connect only what they need. An employee who wants calendar queries doesn't have to consent to email access.
2. **Independent admin consent** - MS Teams requires admin-consent scopes; Mail and Calendar don't. Separate integrations allow phased rollout.
3. **Independent enable/disable** - Users can activate/deactivate each capability individually in their skills dashboard.
4. **Consistent platform pattern** - Every other integration (Jira, Confluence, SharePoint, HubSpot, Wrike) is a single-purpose plugin.

### Shared infrastructure

- **Same Azure AD app registration** (`AZURE_CLIENT_ID` / `AZURE_CLIENT_SECRET` / `AZURE_TENANT_ID`)
- **Same OAuth2 provider type** (`azure` in `knpu_oauth2_client.yaml`)
- **Same token refresh logic** (reuse `SharePointService::refreshToken()` pattern or extract shared helper)
- **Same Graph API base URL** (`https://graph.microsoft.com/v1.0`)

---

## 3. Microsoft Graph API Permissions

### 3.1 Per-Integration Scopes

| Integration | Microsoft Graph Scopes | Admin Consent Required? |
|---|---|---|
| Outlook Mail | `Mail.Read` | No |
| Outlook Calendar | `Calendars.Read` | No |
| MS Teams | `Team.ReadBasic.All`, `Channel.ReadBasic.All`, `ChannelMessage.Read.All`, `ChannelMessage.Send`, `Channel.Create`, `Chat.Read`, `ChatMessage.Send` | **Yes** for `ChannelMessage.Read.All` and `Channel.Create` |

### 3.2 Shared Base Scopes (all integrations)

Every OAuth flow also requests these shared scopes (same as existing SharePoint integration):

```
openid, profile, email, offline_access, User.Read
```

### 3.3 Full Scope Strings for OAuth Redirect

**Outlook Mail:**
```
openid profile email offline_access
https://graph.microsoft.com/User.Read
https://graph.microsoft.com/Mail.Read
```

**Outlook Calendar:**
```
openid profile email offline_access
https://graph.microsoft.com/User.Read
https://graph.microsoft.com/Calendars.Read
```

**MS Teams:**
```
openid profile email offline_access
https://graph.microsoft.com/User.Read
https://graph.microsoft.com/Team.ReadBasic.All
https://graph.microsoft.com/Channel.ReadBasic.All
https://graph.microsoft.com/ChannelMessage.Read.All
https://graph.microsoft.com/ChannelMessage.Send
https://graph.microsoft.com/Channel.Create
https://graph.microsoft.com/Chat.Read
https://graph.microsoft.com/ChatMessage.Send
```

### 3.4 Azure AD Consent Model

- **Adding scopes to existing app registration**: Existing user consents for SharePoint are preserved. Users are not re-prompted for SharePoint scopes.
- **Incremental consent**: Each integration only requests its own scopes. Microsoft's consent UI shows only the new permissions.
- **User-consent scopes** (`Mail.Read`, `Calendars.Read`, `Chat.Read`, `Team.ReadBasic.All`, `Channel.ReadBasic.All`, `ChannelMessage.Send`, `ChatMessage.Send`): Users consent themselves during the OAuth flow.
- **Admin-consent scopes** (`ChannelMessage.Read.All`, `Channel.Create`): IT admin must click "Grant admin consent" in the Azure Portal after the permissions are added to the app registration. Without this, the Teams integration OAuth flow will fail for these scopes.

---

## 4. Tool Definitions

### 4.1 Outlook Mail (4 tools)

| Tool | Graph API Endpoint | Description |
|---|---|---|
| `outlook_mail_search` | `GET /me/messages?$search=...&$filter=...` | Search emails using `$search` (KQL) and `$filter` (OData). Supports sender, subject, date range, hasAttachments filters. Returns subject, sender, receivedDateTime, bodyPreview. |
| `outlook_mail_get_message` | `GET /me/messages/{id}` | Read full email by ID. Returns body (HTML→text), sender, recipients, attachments list, conversation thread ID. |
| `outlook_mail_list_folders` | `GET /me/mailFolders` | List mail folders (Inbox, Sent, Drafts, custom folders). Returns folder name, unreadItemCount, totalItemCount. |
| `outlook_mail_list_messages` | `GET /me/mailFolders/{folderId}/messages` | List messages in a specific folder with pagination. Supports `$top`, `$skip`, `$orderby`. |

**Key parameters:**
- `outlook_mail_search`: `query` (string, required), `folder` (string, optional - restrict to folder), `limit` (integer, optional, default 25)
- `outlook_mail_get_message`: `messageId` (string, required)
- `outlook_mail_list_folders`: no required params
- `outlook_mail_list_messages`: `folderId` (string, required), `limit` (integer, optional, default 25), `skip` (integer, optional)

### 4.2 Outlook Calendar (5 tools)

| Tool | Graph API Endpoint | Description |
|---|---|---|
| `outlook_calendar_list_events` | `GET /me/calendarView?startDateTime=...&endDateTime=...` | List events in a date range. Expands recurring events into individual occurrences. Returns subject, start/end times, location, organizer, attendees, isOnlineMeeting. |
| `outlook_calendar_get_event` | `GET /me/events/{id}` | Get full event details by ID. Returns body, attendees with response status, recurrence pattern, online meeting URL. |
| `outlook_calendar_search` | `GET /me/events?$filter=...` | Search events by subject, organizer, or date. Uses OData `$filter` with `contains()` for text search. |
| `outlook_calendar_check_availability` | `POST /me/calendar/getSchedule` | Free/busy check for one or more users. Returns availability status (free/busy/tentative/oof) for a time range. Useful for meeting scheduling. |
| `outlook_calendar_list_calendars` | `GET /me/calendars` | List user's calendars (primary, shared, group calendars). Returns calendar name, color, canEdit, owner. |

**Key parameters:**
- `outlook_calendar_list_events`: `startDateTime` (string, required, ISO 8601), `endDateTime` (string, required, ISO 8601), `calendarId` (string, optional)
- `outlook_calendar_get_event`: `eventId` (string, required)
- `outlook_calendar_search`: `query` (string, required), `startDateTime` (string, optional), `endDateTime` (string, optional)
- `outlook_calendar_check_availability`: `emailAddresses` (array, required), `startDateTime` (string, required), `endDateTime` (string, required)
- `outlook_calendar_list_calendars`: no required params

### 4.3 MS Teams (8 tools)

| Tool | Graph API Endpoint | Description |
|---|---|---|
| `teams_list_teams` | `GET /me/joinedTeams` | List all teams the user is a member of. Returns team name, description, ID. |
| `teams_list_channels` | `GET /teams/{teamId}/channels` | List channels in a team. Returns channel name, description, membershipType. |
| `teams_read_channel_messages` | `GET /teams/{teamId}/channels/{channelId}/messages` | Read messages in a channel (most recent first). Returns sender, body, createdDateTime, reactions, replies count. |
| `teams_send_channel_message` | `POST /teams/{teamId}/channels/{channelId}/messages` | Post a new message to a channel. Supports plain text and HTML content. |
| `teams_create_channel` | `POST /teams/{teamId}/channels` | Create a new channel in a team. Requires channel name and optional description. |
| `teams_list_chats` | `GET /me/chats` | List user's 1:1 and group chats. Returns chat type, topic, last updated, members. |
| `teams_read_chat_messages` | `GET /me/chats/{chatId}/messages` | Read messages in a 1:1 or group chat. Returns sender, body, createdDateTime. |
| `teams_send_chat_message` | `POST /me/chats/{chatId}/messages` | Send a message in an existing 1:1 or group chat. |

**Key parameters:**
- `teams_list_teams`: no required params
- `teams_list_channels`: `teamId` (string, required)
- `teams_read_channel_messages`: `teamId` (string, required), `channelId` (string, required), `limit` (integer, optional, default 25)
- `teams_send_channel_message`: `teamId` (string, required), `channelId` (string, required), `message` (string, required), `contentType` (string, optional, default "text")
- `teams_create_channel`: `teamId` (string, required), `displayName` (string, required), `description` (string, optional)
- `teams_list_chats`: `limit` (integer, optional, default 25)
- `teams_read_chat_messages`: `chatId` (string, required), `limit` (integer, optional, default 25)
- `teams_send_chat_message`: `chatId` (string, required), `message` (string, required), `contentType` (string, optional, default "text")

---

## 5. Files to Create

Following the established integration pattern (see MEMORY.md):

### Per integration: 3 new files each

| # | File | Pattern | Purpose |
|---|------|---------|---------|
| 1 | `src/Service/Integration/OutlookMailService.php` | `SharePointService.php` | Graph API HTTP client for Mail endpoints |
| 2 | `src/Integration/UserIntegrations/OutlookMailIntegration.php` | `SharePointIntegration.php` | Plugin class implementing `PersonalizedSkillInterface` |
| 3 | `templates/skills/prompts/outlook_mail_full.xml.twig` | `sharepoint_full.xml.twig` | AI agent system prompt for mail queries |
| 4 | `src/Service/Integration/OutlookCalendarService.php` | `SharePointService.php` | Graph API HTTP client for Calendar endpoints |
| 5 | `src/Integration/UserIntegrations/OutlookCalendarIntegration.php` | `SharePointIntegration.php` | Plugin class implementing `PersonalizedSkillInterface` |
| 6 | `templates/skills/prompts/outlook_calendar_full.xml.twig` | `sharepoint_full.xml.twig` | AI agent system prompt for calendar queries |
| 7 | `src/Service/Integration/MsTeamsService.php` | `SharePointService.php` | Graph API HTTP client for Teams endpoints |
| 8 | `src/Integration/UserIntegrations/MsTeamsIntegration.php` | `SharePointIntegration.php` | Plugin class implementing `PersonalizedSkillInterface` |
| 9 | `templates/skills/prompts/msteams_full.xml.twig` | `sharepoint_full.xml.twig` | AI agent system prompt for Teams queries |

### Logo SVGs (6 files)

| # | File | Description |
|---|------|-------------|
| 10 | `public/images/logos/outlook-mail-icon.svg` | Outlook Mail icon (dark fill, skills table) |
| 11 | `public/images/logos/outlook-mail-icon-white.svg` | Outlook Mail icon (white fill, dropdown) |
| 12 | `public/images/logos/outlook-calendar-icon.svg` | Outlook Calendar icon (dark fill) |
| 13 | `public/images/logos/outlook-calendar-icon-white.svg` | Outlook Calendar icon (white fill) |
| 14 | `public/images/logos/msteams-icon.svg` | MS Teams icon (dark fill) |
| 15 | `public/images/logos/msteams-icon-white.svg` | MS Teams icon (white fill) |

**Total new files: 15**

---

## 6. Files to Modify

Each integration requires modifications to the same set of files:

| # | File | Changes |
|---|------|---------|
| 1 | `config/packages/knpu_oauth2_client.yaml` | Add 3 new Azure OAuth clients: `azure_outlook_mail`, `azure_outlook_calendar`, `azure_teams` |
| 2 | `src/Controller/IntegrationOAuthController.php` | Add 6 new methods: start + callback for each integration |
| 3 | `config/services/integrations.yaml` | Register 3 new integration classes with `app.integration` tag |
| 4 | `src/Controller/IntegrationController.php` | Add 3 entries to `getLogoPath()` logo map |
| 5 | `templates/components/skills_table.html.twig` | Add 3 dropdown icons |
| 6 | `templates/integration/setup.html.twig` | Add OAuth buttons at 5 insertion points per integration (15 changes total) |
| 7 | `translations/messages+intl-icu.en.yaml` | Add translation keys for all 3 integrations |
| 8 | `translations/messages+intl-icu.de.yaml` | Add German translations |
| 9 | `translations/messages+intl-icu.ro.yaml` | Add Romanian translations |
| 10 | `translations/messages+intl-icu.lt.yaml` | Add Lithuanian translations |
| 11 | `CHANGELOG.md` | User-facing change entries |
| 12 | `public/llms.txt` | Update tool counts and integration lists |

### No new environment variables needed

All three integrations reuse the existing `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, and `AZURE_TENANT_ID` environment variables. No changes to `.env.dist` required.

---

## 7. OAuth Client Configuration

Add to `config/packages/knpu_oauth2_client.yaml`:

```yaml
azure_outlook_mail:
    type: azure
    client_id: '%env(AZURE_CLIENT_ID)%'
    client_secret: '%env(AZURE_CLIENT_SECRET)%'
    redirect_route: app_tool_oauth_outlook_mail_callback
    redirect_params: {}
    tenant: '%env(AZURE_TENANT_ID)%'
    api_version: 'v2.0'
    url_api: 'https://graph.microsoft.com/'
    default_end_point_version: '1.0'

azure_outlook_calendar:
    type: azure
    client_id: '%env(AZURE_CLIENT_ID)%'
    client_secret: '%env(AZURE_CLIENT_SECRET)%'
    redirect_route: app_tool_oauth_outlook_calendar_callback
    redirect_params: {}
    tenant: '%env(AZURE_TENANT_ID)%'
    api_version: 'v2.0'
    url_api: 'https://graph.microsoft.com/'
    default_end_point_version: '1.0'

azure_teams:
    type: azure
    client_id: '%env(AZURE_CLIENT_ID)%'
    client_secret: '%env(AZURE_CLIENT_SECRET)%'
    redirect_route: app_tool_oauth_teams_callback
    redirect_params: {}
    tenant: '%env(AZURE_TENANT_ID)%'
    api_version: 'v2.0'
    url_api: 'https://graph.microsoft.com/'
    default_end_point_version: '1.0'
```

---

## 8. OAuth Controller Routes

Add to `IntegrationOAuthController.php`:

| Route | Method | Name |
|---|---|---|
| `/outlook-mail/start/{configId}` | `outlookMailStart()` | `app_tool_oauth_outlook_mail_start` |
| `/callback/outlook-mail` | `outlookMailCallback()` | `app_tool_oauth_outlook_mail_callback` |
| `/outlook-calendar/start/{configId}` | `outlookCalendarStart()` | `app_tool_oauth_outlook_calendar_start` |
| `/callback/outlook-calendar` | `outlookCalendarCallback()` | `app_tool_oauth_outlook_calendar_callback` |
| `/teams/start/{configId}` | `teamsStart()` | `app_tool_oauth_teams_start` |
| `/callback/teams` | `teamsCallback()` | `app_tool_oauth_teams_callback` |

Each start method follows the SharePoint pattern:
1. Verify config ownership
2. Store config ID in session (`outlook_mail_oauth_config_id`, etc.)
3. Get Azure OAuth client from registry
4. Redirect with integration-specific scopes

Each callback method follows the SharePoint pattern:
1. Handle errors/cancellation
2. Retrieve access token + refresh token
3. Merge OAuth tokens with existing credentials
4. Encrypt and save to `IntegrationConfig`
5. Redirect to skills page with success flash

### Key difference from SharePoint OAuth:

The SharePoint start method resolves the Azure AD tenant from the user's SharePoint URL. The new integrations don't need this - they use the default `AZURE_TENANT_ID` directly since Mail, Calendar, and Teams are always on the user's home tenant.

---

## 9. Credential Fields

### Outlook Mail & Outlook Calendar

These integrations only need an OAuth button - no additional text fields:

```php
public function getCredentialFields(): array
{
    return [
        new CredentialField(
            'oauth',
            'oauth',
            'Connect with Microsoft',
            null,
            true,
            'Authenticate with your Microsoft account to access Outlook Mail'
        ),
    ];
}
```

### MS Teams

Same pattern - OAuth only:

```php
public function getCredentialFields(): array
{
    return [
        new CredentialField(
            'oauth',
            'oauth',
            'Connect with Microsoft',
            null,
            true,
            'Authenticate with your Microsoft account to access MS Teams'
        ),
    ];
}
```

### Credential validation

All three integrations validate the same way:

```php
public function validateCredentials(array $credentials): bool
{
    return isset($credentials['access_token']);
}
```

(No `tenant_id` check needed since it's read from env, unlike SharePoint which resolves it from the user's SharePoint URL.)

---

## 10. Service Class Design

### 10.1 OutlookMailService

```
Graph API Base: https://graph.microsoft.com/v1.0

Methods:
- testConnection(): GET /me → validate token
- searchMessages(query, folder?, limit): GET /me/messages?$search=...&$filter=...&$top=...&$select=...
- getMessage(messageId): GET /me/messages/{id}?$expand=attachments
- listFolders(): GET /me/mailFolders?$top=50
- listMessages(folderId, limit, skip): GET /me/mailFolders/{id}/messages?$top=...&$skip=...&$orderby=receivedDateTime desc
- refreshToken(...): POST /oauth2/v2.0/token
```

**HTML→Text conversion**: Email bodies come as HTML. Use `strip_tags()` + basic formatting cleanup (same approach as SharePoint page content extraction).

### 10.2 OutlookCalendarService

```
Graph API Base: https://graph.microsoft.com/v1.0

Methods:
- testConnection(): GET /me → validate token
- listEvents(startDateTime, endDateTime, calendarId?): GET /me/calendarView?startDateTime=...&endDateTime=...
- getEvent(eventId): GET /me/events/{id}
- searchEvents(query, startDateTime?, endDateTime?): GET /me/events?$filter=contains(subject,'...')
- checkAvailability(emails[], start, end): POST /me/calendar/getSchedule
- listCalendars(): GET /me/calendars
- refreshToken(...): POST /oauth2/v2.0/token
```

**Date handling**: All datetime parameters use ISO 8601 format. The Graph API `calendarView` endpoint handles timezone conversion server-side.

### 10.3 MsTeamsService

```
Graph API Base: https://graph.microsoft.com/v1.0

Methods:
- testConnection(): GET /me → validate token
- listTeams(): GET /me/joinedTeams
- listChannels(teamId): GET /teams/{teamId}/channels
- readChannelMessages(teamId, channelId, limit): GET /teams/{teamId}/channels/{channelId}/messages?$top=...
- sendChannelMessage(teamId, channelId, message, contentType): POST /teams/{teamId}/channels/{channelId}/messages
- createChannel(teamId, displayName, description?): POST /teams/{teamId}/channels
- listChats(limit): GET /me/chats?$top=...&$expand=members
- readChatMessages(chatId, limit): GET /me/chats/{chatId}/messages?$top=...
- sendChatMessage(chatId, message, contentType): POST /me/chats/{chatId}/messages
- refreshToken(...): POST /oauth2/v2.0/token
```

**Message content types**: Support both `text` (plain text) and `html` (rich text with formatting). Default to `text` for simplicity.

---

## 11. System Prompt Design

Each integration gets its own `_full.xml.twig` template following the SharePoint pattern:

### Common sections (all three):
- Critical enforcement (mandatory tool execution, no hallucination)
- Stateless operation context (taskDescription, userPrompt, userID, locale)
- Webhook constraint (synchronous, past-tense responses)
- Data-first mandate (fetch before answering)
- Output format (JSON with `output` and `attachment` fields)

### Integration-specific sections:

**Outlook Mail** (`outlook_mail_full.xml.twig`):
- Email search syntax guide (`$search` KQL + `$filter` OData)
- Date range filtering patterns (receivedDateTime, sentDateTime)
- Folder navigation guidance
- Privacy reminder: only reads, never sends/deletes
- Common patterns: "Find emails from X about Y", "What did X say about the project?"

**Outlook Calendar** (`outlook_calendar_full.xml.twig`):
- Date/time format guide (ISO 8601)
- Calendar view vs. event list distinction
- Recurring event handling (calendarView expands them)
- Availability check guidance (getSchedule for multiple users)
- Common patterns: "What meetings do I have tomorrow?", "Is X available next Tuesday?"

**MS Teams** (`msteams_full.xml.twig`):
- Team/channel navigation (list teams → list channels → read messages)
- Write operation safety rules (confirm before sending messages, creating channels)
- Message formatting guidance (text vs. HTML)
- Chat vs. channel distinction
- Common patterns: "What's being discussed in #general?", "Send a message to the project channel"

---

## 12. Token Refresh Strategy

All three integrations use the same Azure AD OAuth2 token refresh flow. To avoid code duplication, extract a shared helper:

### Option A: Shared trait (recommended)

```php
trait AzureTokenRefreshTrait
{
    private function refreshAzureTokenIfNeeded(array $credentials): array
    {
        if (!isset($credentials['expires_at']) || time() < $credentials['expires_at']) {
            return $credentials;
        }

        if (!isset($credentials['refresh_token'])) {
            throw new \Exception('Token expired and no refresh token available');
        }

        // Call service-specific refreshToken method
        return $this->doRefreshToken($credentials);
    }
}
```

### Option B: Keep inline (simpler, matches existing pattern)

Each integration's `executeTool()` method handles refresh inline, matching the `SharePointIntegration` pattern exactly. This is simpler and avoids adding an abstraction for only 4 consumers.

**Recommendation**: Start with Option B (inline) for consistency with the existing codebase. Refactor to trait only if a 5th Azure integration is added.

---

## 13. Implementation Phases

### Phase 1: Outlook Mail (read-only)
- **Admin consent**: Not required (`Mail.Read` is user-consent)
- **Risk**: Low - read-only, familiar email data
- **Effort**: Medium - 3 new files, standard modifications
- **Ship as**: Stable (non-experimental)

### Phase 2: Outlook Calendar (read-only)
- **Admin consent**: Not required (`Calendars.Read` is user-consent)
- **Risk**: Low - read-only, calendar data
- **Effort**: Medium - 3 new files, standard modifications
- **Ship as**: Stable (non-experimental)
- **Dependency**: None (independent of Phase 1)

### Phase 3: MS Teams (read + write)
- **Admin consent**: **Required** for `ChannelMessage.Read.All` and `Channel.Create`
- **Risk**: Medium - write operations (sending messages, creating channels)
- **Effort**: High - 3 new files, 8 tools (most complex integration), write safety rules in system prompt
- **Ship as**: **Experimental** (using `isExperimental(): true`)
- **Dependency**: IT ticket approval (admin consent grant)

### Phase order rationale:
1. Mail and Calendar can be shipped immediately after implementation - no IT dependency
2. Teams requires IT admin consent, which may take weeks. Filing the IT ticket early (now) allows parallel work
3. Teams write operations need extra testing and safety considerations

---

## 14. Security Considerations

### Read vs. Write Operations

| Integration | Read | Write | Safety Model |
|---|---|---|---|
| Outlook Mail | Yes | No | Read-only, no risk |
| Outlook Calendar | Yes | No | Read-only, no risk |
| MS Teams | Yes | **Yes** (send messages, create channels) | AI agent must confirm write actions with user before executing |

### MS Teams Write Safety Rules

The system prompt for MS Teams must enforce:
1. **Explicit confirmation**: Before sending any message or creating a channel, the AI agent must state what it will do and wait for user confirmation
2. **No automated messaging**: The agent never sends messages proactively or in loops
3. **Content preview**: Show the exact message content before sending
4. **Channel creation guard**: Require explicit user request with team name and channel name

### Token Scope Isolation

Each integration requests only its own scopes. A user who connects only Outlook Mail grants only `Mail.Read` - the platform cannot access their calendar or Teams data with that token.

### Data Handling

- All OAuth tokens encrypted with Sodium at rest (existing `EncryptionService`)
- No email/calendar/Teams data stored in the database - all data is fetched in real-time
- Audit logging for all API tool executions (existing audit infrastructure)

---

## 15. Azure AD App Registration Changes

The following changes are needed in the Azure Portal for the existing app registration:

### Add Redirect URIs

```
https://subscribe-workflows.vcec.cloud/integrations/oauth/callback/outlook-mail
https://subscribe-workflows.vcec.cloud/integrations/oauth/callback/outlook-calendar
https://subscribe-workflows.vcec.cloud/integrations/oauth/callback/teams
```

### Add API Permissions (Delegated)

| Permission | Type | Admin Consent |
|---|---|---|
| `Mail.Read` | Delegated | No |
| `Calendars.Read` | Delegated | No |
| `Team.ReadBasic.All` | Delegated | No |
| `Channel.ReadBasic.All` | Delegated | No |
| `ChannelMessage.Read.All` | Delegated | **Yes** |
| `ChannelMessage.Send` | Delegated | No |
| `Channel.Create` | Delegated | **Yes** |
| `Chat.Read` | Delegated | No |
| `ChatMessage.Send` | Delegated | No |

**Already granted (no changes needed):** `openid`, `profile`, `email`, `offline_access`, `User.Read`, `Sites.Read.All`, `Files.Read.All`

### Admin Consent Action

After adding the permissions, an IT admin must click **"Grant admin consent for [tenant]"** in the Azure Portal → App Registration → API Permissions. This is required for the two admin-consent scopes (`ChannelMessage.Read.All`, `Channel.Create`).

---

## 16. Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| IT admin consent delayed | Teams integration blocked | File IT ticket immediately; ship Mail + Calendar first |
| Token refresh failures | Integration stops working | Existing refresh pattern proven with SharePoint; clear error messages guide user to reconnect |
| Teams rate limiting | Message send/read throttled | Implement retry-after header handling in `MsTeamsService`; document limits in system prompt |
| Email volume overwhelming | Large search results | Default limit of 25, max 50; `$select` to fetch only needed fields |
| Calendar timezone issues | Wrong meeting times | Use ISO 8601 with timezone; Graph API handles conversion |
| Write operation misuse | Unwanted Teams messages | System prompt safety rules; experimental flag; audit logging |
