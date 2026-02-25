# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## 2026-02-25

### Added
- **Remote MCP Server integration** — Connect any MCP-compatible server as a skill source. Tools are discovered dynamically and available via the API alongside native integrations
- **Platform field in Prompt Vault** — Tag prompts with the AI platform they are designed for (ChatGPT, Claude, Copilot, Cursor, Gemini, and more). Filter prompts by platform in the UI and via API (`?platform=chatgpt`) to quickly find prompts for the tools you use
- **Platform filter in Prompt API** — Use `?platform=chatgpt` to filter prompts by target AI platform via the API
- **Platform column in CSV import** — The prompt import now supports a "platform" column to assign prompts to AI platforms during bulk import
- **Code coverage reporting** — Test coverage is now tracked via Codecov with a badge displayed in the README
- **Multi-client MCP configuration** — The Skills page now shows copy-paste configurations for 7 AI clients: Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, Gemini CLI, and Codex
- **Automated test suite** — Integration tests, smoke tests, and API tests now run fully without external service credentials
- **Smoke tests** — Critical pages (Skills, My Agent, Prompts, Release Notes, Health) are verified on every CI run
- **API endpoint tests** — Skills API and MCP API endpoints are now tested for authentication and responses

### Fixed
- **CI pipeline** — Tests now run reliably in GitHub Actions without requiring external secrets
- **Integration setup forms** — Fixed a crash when opening Jira/Confluence setup pages caused by a Symfony 8 compatibility issue

### Changed
- **Symfony 8 upgrade** — Migrated the entire platform from Symfony 7.3 to Symfony 8.0
- **PHP 8.5** — Updated runtime from PHP 8.4 to PHP 8.5
- **Major dependency upgrades** — Upgraded Doctrine DBAL to v4, Doctrine Bundle to v3, Doctrine Migrations Bundle to v4, Predis to v3, PhpSpreadsheet to v5, PHPUnit to v13, and PHPStan phpdoc-parser to v2
- **Database upgrade** — Updated MariaDB from 11.2 to 12
- **Security updates** — firebase/php-jwt to v7 (CVE-2025-45769), PHPUnit to 13 (CVE-2026-24765)
- **Infrastructure** — Updated Redis to 8 in both development and CI environments
- **CI/CD pipeline** — Updated GitHub Actions to latest versions (checkout v6, cache v5, setup-node v6)

## 2026-02-24

### Changed
- **Updater Agent** now uses the shared reusable workflow from the Claude Code Marketplace, supporting Docker image updates, GitHub Actions version pins, and configurable major/minor version control

## 2026-02-23

### Added
- **Skill filter in Prompt Vault** — Filter prompts by skill/integration (e.g., Jira, Confluence, GitLab) to quickly find prompts designed for a specific tool
- **Skill dropdown in prompt create/edit forms** — Optionally link a prompt to a specific skill/integration when creating or editing
- **Skill column in CSV import** — The prompt import now supports a "skill" column to assign prompts to integrations during bulk import
- **Skill filter in Prompt API** — Use `?skill=jira` to filter prompts by skill via the API

### Changed
- **Category is now optional for prompts** — Prompts no longer require a category, making it easier to create general-purpose prompts
- **API response includes skill field** — The prompt API now returns a `skill` field for each prompt

### Added
- **Tool Access Modes** — Choose between Read Only, Standard, and Full access to control what your AI agents can do. Read Only mode restricts agents to search and read operations. Standard mode adds create and update capabilities. Full mode enables all operations including delete
- **Tool access mode selector on Profile page** — Switch your tool access mode from your profile with a single click. New users default to Read Only for safety
- **Tool category badges on Skills setup** — Each tool now shows a Read, Write, or Delete badge so you can see at a glance what each tool does. Tools outside your current access mode appear grayed out
- **Activity Overview on My Agent dashboard** — See key usage metrics at a glance: agent sessions, tool executions, API calls, Prompt Vault activity, active skills, and tool types used over the last 30 days
- **Stat card tooltips** — Hover over any metric on the Activity Overview to see a short explanation of what it measures
- **Most Used Tools section** — The dashboard now shows your top 3 most frequently used tools to help you understand how your agent is being utilized

### Removed
- **Candis Invoice Management skill** — The Candis integration has been removed from the platform

## 2026-02-18

### Changed
- **Projektron skill renamed to "BCS (Projektron)"** — The skill now displays as "BCS (Projektron)" throughout the platform, reflecting that Projektron is the vendor and BCS (Business Coordination Software) is the product name

### Fixed
- **Flash messages now display translated text** — Status messages (e.g., after changing a member's role) now show properly translated text instead of raw translation keys like "organisation.member.role_updated"

### Removed
- **Duplicate skill chips bar on dashboard** — The "Your agent can work with" section has been removed since it duplicated the Recent Skills section below
- **Technical API details from dashboard** — The collapsible API & Technical Details section has been moved off the dashboard for a cleaner experience

### Changed
- **Dashboard redesigned as "My Agent"** — The dashboard now focuses on your AI agent's skills instead of technical details, showing active skills at a glance with visual skill chips
- **New onboarding experience** — New users see a welcoming prompt to add their first skill, guiding them to get started quickly
- **Technical details collapsed by default** — API URLs and curl commands are now tucked behind an expandable section to reduce clutter
- **"General" renamed to "My Agent"** — The navigation item now reflects the agent-centric purpose of the dashboard
- **Integration logos are now defined per skill** — Each skill type provides its own logo path, eliminating hardcoded logo maps and making the system easier to extend

### Added
- **Manually add data to Files** — Create text files directly from the Files page without uploading from your device. Click "Manually add data", paste or type your content, and save it as a .txt file for your AI agent to use
- **Files page header with explanation** — The Files page now includes a descriptive header explaining that uploaded files are used to train the AI agent via RAG, matching the style of the Skills page header
- **Tenant Settings API** — New `/api/tenant/{uuid}/settings` endpoint allows the bot to fetch tenant-specific webhook URLs and configuration dynamically, enabling true multi-tenancy instead of a single shared webhook URL
- **Organisation selector on Invite Member** — The Invite Member dialog now lets you choose which organisation to add the member to, instead of always using the currently active one

### Changed
- **"Channel" renamed to "Tenant Settings"** — The Channel page is now called "Tenant Settings" throughout the UI in all four languages (EN, DE, LT, RO) to better reflect its purpose. URLs have also been updated from `/channel` to `/tenant`
- **N8N API Key repositioned** — The N8N API Key field is now placed directly below the N8N Visualization URL for better grouping of visualization-related settings
- **System Prompt is now read-only** — The System Prompt field on the Tenant Settings page now displays the platform's main agent prompt as a read-only reference; it is no longer editable per tenant
- **System Prompt available via Tenant API** — The Tenant Settings API now supports `?system_prompt=true` to include the main agent system prompt in the response

### Fixed
- **Dashboard "Recent Integrations" now shows most recently used** — The dashboard overview now correctly displays the 5 most recently accessed integrations instead of sorting alphabetically by type
- **Prompt import now works across multiple tenants** — Default prompts can now be imported into each tenant independently; previously importing into a second tenant would fail
- **Admin can now change member roles in multi-organisation setups** — Previously, clicking "Make Admin" or "Make Member" would fail with a permission error when both users belonged to multiple organisations

### Removed
- **MS Teams Configuration section** — Removed the Microsoft App Type, App ID, App Password, and Tenant ID fields from the Tenant Settings page; these per-bot-deployment settings belong in the bot's environment configuration
- **Organisation Type dropdown** — Removed the Channel Type selector (Common / eCommerce / MS Teams) as it is no longer needed

## 2026-02-17

### Added
- **Azure credential health check** — The `/health` endpoint now validates Azure client credentials so monitoring can detect expired or rotated secrets before users encounter errors with Microsoft integrations (SharePoint, MS Teams, Outlook Mail, Outlook Calendar)

### Fixed
- **Microsoft integrations no longer break after client secret rotation** — SharePoint, MS Teams, Outlook Mail, and Outlook Calendar integrations now use the current server credentials when refreshing tokens, so existing users won't get errors after an Azure secret update

## 2026-02-13

### Fixed
- SharePoint search now returns relevant internal policy documents instead of unrelated business contracts when asking about travel expenses, vacation policies, or other internal processes
- Improved search result ranking: policy and guideline documents are now prioritized over documents that only incidentally mention search keywords
- Fixed issue where city names in company addresses caused irrelevant results to appear (e.g., searching for "Mannheim travel" no longer returns every document with the company's Mannheim address)

## 2026-02-10

### Added
- **Search filter in "Add Personalized Skill" dropdown** — Type to instantly filter skills by name. Experimental skills are now hidden by default; click "..." at the bottom to reveal them.

### Fixed
- **Outlook Mail, Outlook Calendar, and MS Teams integrations now connect with correct permissions** — Previously, clicking "Connect with Microsoft" for these new integrations used the SharePoint OAuth flow, which requested the wrong permissions. Now each integration requests only the permissions it needs (e.g., Mail.Read for Outlook Mail, Calendars.Read for Calendar, Teams scopes for MS Teams). If you previously connected one of these integrations, please disconnect and reconnect it.
- **"Test Connection" for Microsoft integrations now verifies actual permissions** — Previously, Test Connection only checked if a token existed. Now it also verifies that the required API scopes are granted (e.g., testing mail folder access for Outlook Mail, calendar access for Calendar, team membership for MS Teams) and provides specific error messages if permissions are missing.
- **API no longer crashes if one integration fails to load** — If a single integration has an issue (e.g., expired token, missing permissions), the API now returns the remaining integrations normally instead of returning an error for the entire request.

### Added
- **Workoflow Metrics repository added to footer and release notes** — The open-source Grafana monitoring dashboards repo is now linked in the site footer and its changelog appears on the Release Notes page
- **New Outlook Mail integration (experimental)** — AI agents can now search and read your Outlook emails on your behalf. Supports keyword search, folder browsing, and reading full email content including attachments list. Read-only access.
- **New Outlook Calendar integration (experimental)** — AI agents can now query your Outlook calendar, search events, and check availability for scheduling. Supports date range queries, recurring event expansion, and free/busy checks for colleagues. Read-only access.
- **New MS Teams integration (experimental)** — AI agents can now read and send messages in your MS Teams channels and chats. Supports listing teams/channels, reading channel messages, posting to channels, browsing 1:1/group chats, and creating new channels. Requires IT admin consent for some permissions.

### Changed
- **Experimental integrations now show a flask icon in the "Add Personalized Skill" dropdown** — Makes it easier to identify which integrations are still experimental before adding them

### Improved
- **Jira AI agent responds faster with lower token usage** — Removed duplicate anti-hallucination rules (previously repeated 11 times across 5 sections), consolidated examples from 7 to 3, and streamlined enforcement sections (~20% prompt reduction) with no behavioral changes
- **Jira AI agent handles vague queries better** — When you ask a broad or ambiguous question like "show me stuff about the project", the AI agent now asks for clarification with specific suggestions (project key, sprint name, ticket key) instead of running a blind search
- **SharePoint AI agent handles vague queries better** — When you ask a broad or ambiguous question like "what do you find about Mannheim", the AI agent now asks for clarification with possible interpretations instead of blindly searching and returning irrelevant results
- **SharePoint AI agent responds faster with lower token usage** — Removed redundant tool documentation and duplicate instructions from the AI agent's system prompt (~14% reduction), following the same optimization already applied to the Jira agent

### Fixed
- **SharePoint AI agent no longer presents irrelevant search results** — When all search results are off-topic (e.g., "Mannheim" only appearing in company address blocks, not in document content), the agent now retries with different search terms and clearly reports "no relevant documents found" instead of listing unrelated documents with a disclaimer

## 2026-02-09

### Added
- **Candis.io invoice management integration** — New integration for managing invoices, reimbursements, and accounting master data in Candis with OAuth2 connection. AI agents can search and view invoices, track payment status, manage employee expenses (travel, mileage, per diem), import cost centers, GL accounts, and contacts, and export data for ERP integration through 9 specialized tools.
- **Romanian and Lithuanian language support** — The platform is now available in Romanian (RO) and Lithuanian (LT) in addition to German and English, with full translations for all UI elements and a language switcher with country flags

### Changed
- **Candis integration marked as experimental** — Candis is now flagged as experimental while it undergoes further testing
- **Wrike integration is no longer experimental** — Wrike is now a fully supported skill
- **Streamlined AI assistant routing** — The main AI assistant prompt was optimized for faster responses and lower token usage. Adding new integrations no longer requires updating the assistant's routing configuration. No user-facing behavior changes.

## 2026-02-06

### Fixed
- **Skill dropdown icons now match the style of built-in icons** — Wrike, Projektron, and SAP icons in the "Add New Skill" dropdown are now monochrome and properly sized, consistent with the other dropdown entries
- **Projektron and SAP now use official brand logos in the skills table** — Replaced placeholder logos with the proper Projektron and SAP brand assets for a more polished appearance

## 2026-02-05

### Added
- **Wrike agent now available in main AI assistant routing** — The main AI assistant can now recognize Wrike-related requests and automatically route them to the Wrike Agent. Ask about tasks, folders, projects, or time tracking in Wrike and the assistant will delegate to the specialized Wrike agent.

### Fixed
- **AI assistant now correctly copies file download URLs** — Fixed an issue where generated file URLs (for PDFs and PowerPoints) could have corrupted characters in the download link, causing "file not found" errors. The AI assistant now preserves URLs exactly as received.
- **Fixed icon spacing in "Add Personalized Skill" dropdown** — Icons for SAP Analytics Cloud, SAP Cloud for Customer, Wrike, and Projektron no longer overlap with the skill name text.

## 2026-02-02

### Fixed
- **SharePoint AI agent no longer picks the wrong sub-site when searching** — Previously, the AI agent would guess which SharePoint sub-site a document lived on and restrict its search to that site, causing it to miss documents on other sites and sometimes read/summarize the wrong document. The agent now searches across all sites by default, only restricting to a specific site when you explicitly ask.

### Added
- **SharePoint tenant selection** — When setting up SharePoint, you can now enter your SharePoint URL (e.g. valanticgroup.sharepoint.com) to connect to a specific Microsoft tenant. This allows access to SharePoint content on tenants where you are a guest, not just your home tenant.
- **SharePoint site discovery** — AI agents can now discover all accessible SharePoint sites before searching, so they no longer need to guess site URLs. This prevents searches from failing when content lives on a different hostname than expected.
- **SharePoint tenant URL shown in AI tool descriptions** — AI agents can now see which SharePoint tenant each tool belongs to (e.g. "valanticmore.sharepoint.com") directly in the tool list, eliminating guesswork about which tenant to search

### Changed
- **SharePoint URL is now required when setting up SharePoint** — The SharePoint URL field is now mandatory for new SharePoint integrations, ensuring the AI agent always knows which tenant to search without wasting API calls on site discovery
- **SharePoint search results ranked by relevance** — Search results are now scored and sorted by how well they match your original query (based on title, filename, and content relevance), so the most important results appear first.
- **SharePoint search automatically broadens when site filter returns no results** — If a search restricted to a specific SharePoint site returns nothing, the system now automatically retries across all sites instead of reporting "no results found."

### Fixed
- **SharePoint document reading now works for files in non-default drives** — AI agents now pass the drive ID when reading documents found through search, fixing "resource not found" errors for files stored in SiteAssets, secondary document libraries, or other non-default drives
- **SharePoint AI agent no longer searches for wrong terms** — Fixed an issue where hardcoded example URLs in the AI agent's instructions caused it to confuse example site names with the user's actual SharePoint tenant, leading to irrelevant search results
- **SharePoint no longer re-authenticates with Microsoft on every API call** — Refreshed access tokens are now saved back to the database, so subsequent requests reuse the valid token instead of requesting a new one each time
- **AI agent no longer falsely lists all integrations as connected** — When asked "Which tools do you have?" or similar questions, the AI agent now correctly explains that Workoflow is a configurable platform with skill categories, and directs you to manage your active Skills at the configuration portal instead of incorrectly listing all possible integrations as available
- **SharePoint search now respects specific site names** — When you mention a specific SharePoint site (e.g., "valanticgroup sharepoint"), the AI agent now correctly restricts search results to that site instead of searching across all sites and potentially returning results from the wrong one
- **SharePoint search no longer returns zero results for existing pages** — Fixed three compounding issues that caused SharePoint searches to fail: the site filter now uses the correct full-URL syntax required by Microsoft Graph API, the agent now uses resilient keyword search instead of fragile exact phrases (which fail on typos), and the agent now automatically retries with broader queries instead of immediately giving up when no results are found
- **SharePoint system prompt template no longer fails on example placeholders** — Fixed a rendering error where example placeholder syntax in the AI agent's instructions was incorrectly interpreted as template variables

## 2026-01-30

### Fixed
- **More accurate employee skill search results** — When searching for employees with specific skills (e.g., "n8n"), the AI agent now clearly distinguishes between employees who explicitly list that skill and those with only related skills. Exact matches are shown first, and the agent transparently indicates when no exact match was found.
- **Smarter verification of search results** — When asked to verify employee search results, the AI agent now checks the existing data instead of redundantly re-fetching each profile, providing faster and more accurate responses.

### Changed
- Repository moved to official valantic organization: valantic-CEC-Deutschland-GmbH
- Added proprietary license — usage requires a valid commercial license from valantic CEC Deutschland GmbH
- Updated all GitHub links in footer and release notes to point to the new valantic organization
- **Members, Channel, and Files pages are now admin-only** — Only administrators can access these pages; non-admin users will no longer see them in the navigation menu

## 2026-01-26

### Added
- **Wrike Project Management integration (experimental)** - New integration for managing tasks, folders, and projects in Wrike with OAuth2 authentication. AI agents can search, create, and update tasks, navigate folder hierarchies, add comments, and track time through 13 specialized tools.

## 2026-01-22

### Added
- **Custom fields support for Jira tools** - AI agents can now request specific custom fields using the `additionalFields` parameter. Custom field values are returned in a `_rawFields` property alongside the standard flat fields.
- **New jira_get_fields tool** - AI agents can discover all available fields (system and custom) in a Jira instance, making it easy to find the right field IDs for `additionalFields`.

### Improved
- **Faster Jira and Confluence responses for AI agents** - API responses are now significantly smaller and more readable. Jira issues now return flat, easy-to-read objects instead of deeply nested structures. Confluence pages now return plain text content instead of raw storage markup.

## 2026-01-21

### Added
- **Workoflow MCP in footer and release notes** - Added MCP Server project link to the footer's Open Source section and added Workoflow MCP release notes to the Release Notes page with automatic changelog fetching from GitHub

### Changed
- **User Delegation is now the default for SAP C4C and SAP SAC** - When adding new SAP skills, User Delegation (via Azure AD) is now pre-selected as the recommended authentication method for better security and user attribution.

### Fixed
- **SAP C4C connection test now shows detailed errors** - The "Test Connection" button for SAP C4C integrations now displays specific error messages (authentication failed, access forbidden, network errors) instead of a generic "Connection failed" message.


## 2026-01-19

### Improved
- **SAP C4C and SAC user delegation alignment** - Both SAP C4C and SAP SAC integrations now use consistent "User Delegation" naming and require SAP OAuth credentials for the SAML2 Bearer flow. Setup instructions updated with clear 3-step process.

## 2026-01-16

### Added
- **SAP Analytics Cloud integration (experimental)** - New integration for querying analytics data from SAP SAC. AI agents can discover models, query sales/revenue data with filters, and access dashboards through 6 specialized tools.
- **HubSpot CRM integration (experimental)** - New integration for managing contacts, companies, and deals in HubSpot CRM with OAuth2 authentication. AI agents can now search, view, create, and update HubSpot records through 12 specialized tools.
- **SAP C4C and SAC User Delegation** - SAP C4C and SAP SAC now support user delegation via Azure AD. When configured, actions are attributed to the actual user instead of a technical service account. Setup instructions are shown on the integration configuration page.

### Improved
- **SAP C4C agent now provides clickable links** - When asking for a link to an opportunity, account, contact, or lead, the agent now returns the SAP C4C web interface URL instead of API/XML links
- **SAP C4C agent checks for duplicate accounts** - Before creating a new account, the agent now searches for existing accounts with similar names and asks for confirmation if matches are found
- **SAP C4C agent requests required fields** - When creating opportunities, accounts, contacts, or leads with incomplete information, the agent now clearly lists which fields are required vs recommended
- **Integration setup instructions** - Integrations can now display detailed setup instructions on their configuration page to guide users through complex setup requirements

## 2026-01-15

### Added
- **MCP Server Configuration UI** - Skills page "API Access" button now shows Claude Desktop MCP configuration instead of curl examples
- **Copy-paste ready MCP config** - Users can copy JSON configuration with their personal access token pre-filled for Claude Desktop setup
- **Token requirement for MCP config** - Users without a personal access token see a prompt to generate one in their profile before viewing MCP configuration
- **MCP tools documentation** - Skills page now lists available MCP tools (workoflow_list_tools, workoflow_execute, workoflow_refresh) with descriptions

### Improved
- **Projektron task names now include project hierarchy** - Tasks returned by `projektron_get_all_tasks` now display the full path including project and subproject names (e.g., "Incubator AI > Meeting" instead of just "Meeting"), making it easier to identify and book time to the correct task
- **Projektron worklog now includes booking descriptions** - Time entries returned by `projektron_get_worklog` now contain individual booking comments/descriptions, making it easier for AI agents to understand what work was done
- **AI agent anti-hallucination improvements** - All 7 sub-agents (Projektron, Jira, Confluence, SharePoint, GitLab, Trello, SAP C4C) now have strengthened prompts that enforce tool calls before responding with user data, reducing cases where agents would invent information instead of fetching it from APIs

### Changed
- **SAP C4C marked as experimental** - SAP C4C integration now shows experimental badge in the UI to indicate it's still under development

### Fixed
- **MCP API authentication** - Added dedicated `mcp_api` firewall for `/api/mcp` routes using `PromptApiTokenAuthenticator` - previously requests fell through to the generic `api` firewall which used JWT authentication, causing valid X-Prompt-Token headers to be rejected with 401
- **Projektron worklog duplicate entries** - Fixed issue where the same booking appeared multiple times with wrong dates when fetching weekly time entries

## 2026-01-14

### Added
- **MCP API endpoints** - New `/api/mcp/tools` and `/api/mcp/execute` endpoints for MCP server integration with token-based authentication
- **Prompt Vault hero banner** - Added description section explaining what the Prompt Vault is and how personal vs team prompts work
- **Skills API helper** - Copy-able curl commands on the Skills page for listing tools, executing tools, and filtering by type

### Changed
- **Platform Skills management redesign** - Replaced card-based layout with compact table view showing all tools at a glance with toggle switches on the right

---

## 2026-01-13

### Added
- **Prompt Vault** - New feature for managing and organizing AI prompts at personal and team level with categories, upvotes, and comments
- **Prompt import command** - Console command to import default prompts from CSV into organisations
- **Prompt API** - REST API for AI agents to retrieve prompts using personal access tokens
- **Prompt sorting** - Sort prompts by newest, oldest, most popular, alphabetically, or by category
- **API helper section** - Copy-able curl commands on the Prompts page for easy API access
- **Jira user filtering** - AI agents can now filter sprint, board, and Kanban issues by assignee (e.g., "show my assigned issues") and apply custom JQL filters (e.g., "issues completed yesterday")

### Fixed
- **Copy button feedback** - All copy buttons now show green success indicator when clicked

## 2025-12-16

### Fixed
- **SAP C4C search filtering now works correctly** - AI agents now use the correct OData V2 filter syntax for partial name searches (substringof instead of contains) and correct field names for opportunity filters (ProspectPartyID instead of AccountPartyID)

## 2025-12-12

### Added
- **Experimental skill banner** - Skills marked as experimental now display a warning banner on their setup page, informing users that features may change
- **Projektron session expiration notice** - Projektron skill setup page now shows a note near the JSESSIONID and CSRF token fields explaining that these values expire after a day

## 2025-12-05

### Added
- **Projektron absence tracking** - AI agents can now view vacation, sickness, and other absences for any year with details including dates, duration, status (approved/pending/rejected), and vacation balance summary
- **Projektron worklog debug script** - New helper script (`scripts/projektron_add_worklog_helper.sh`) for testing and debugging Projektron time bookings from the command line
- **Integration connection status tracking** - Integrations now track their connection status and display when credentials need to be renewed

### Fixed
- **Projektron absence details now parse correctly** - Fixed a bug where vacation entries showed null values for duration, end date, and status; multi-day vacations now correctly display their full date range (e.g., Dec 22-31 instead of just Dec 22)
- **Projektron time entries now book to correct date** - Fixed a bug where time entries were being booked to the wrong date (one day earlier than intended) due to an incorrect date adjustment in the booking logic

## 2025-12-04

### Added
- **Execution ID tracking in Audit Log** - API calls can now be grouped by execution_id parameter, making it easier to trace all actions from a single AI agent workflow execution
- **Audit Log grouped view** - New toggle between flat and grouped view modes, allowing you to see related API calls collapsed under their execution_id
- **Execution ID filtering** - Filter audit log entries by specific execution_id to trace a single workflow execution

### Changed
- **Audit Log now shows last 15 minutes by default** - For better performance and focus, the audit log initially displays only the last 15 minutes of entries; use the date filters to view older logs
- **Audit Log table layout optimized** - Removed User column to make room for Data column on smaller screens

### Fixed
- **Audit Log expand details now scrollable** - Large JSON data in expanded log details is now scrollable instead of expanding infinitely

## 2025-12-03

### Added
- **SAP C4C metadata discovery tool** - AI agents can now discover available entity properties before searching, preventing filter errors from invalid property names like "SalesPhaseCode" that don't exist in the OData API
- **SAP C4C related data expansion** - AI agents can now include related collections (parties, items, notes) in opportunity queries with a single request, reducing API calls
- **SAP C4C automatic date format correction** - AI agent now automatically converts simple dates (e.g., "2026-03-03") to SAP's required ISO 8601 format with time component ("2026-03-03T00:00:00") without asking for user confirmation

### Improved
- **Multi-language response consistency** - Main AI agent now correctly responds in the same language as the user's input (English input → English response, German input → German response)

### Fixed
- **SAP C4C opportunity creation 403 error** - Fixed issue where creating opportunities failed with "permission denied" due to session cookies being lost during CSRF token fetch redirect; CSRF token endpoint now avoids redirect for reliable session handling
- **SAP C4C opportunity creation with account/contact links** - Fixed issue where linking opportunities to accounts and contacts failed due to wrong SAP field names (AccountPartyID→ProspectPartyID, MainContactPartyID→PrimaryContactPartyID) and wrong ID types; now correctly uses AccountID/ContactID instead of ObjectID
- **SAP C4C account/contact lookup from opportunities** - Fixed issue where agent incorrectly used PartyID values to fetch accounts and contacts, resulting in 404 errors; agent now uses name-based search for reliable lookups

## 2025-12-02

### Added
- **SAP C4C Opportunity management** - AI agents can now create, search, update, and list sales opportunities in SAP Cloud for Customer with support for linking to accounts and contacts
- **SAP C4C Account management** - AI agents can now manage corporate accounts including address and contact information, with optional expansion to show linked contacts
- **SAP C4C Contact management** - AI agents can now create and manage contact persons with support for linking to corporate accounts

### Fixed
- **Projektron time booking now works correctly** - Fixed issue where worklog entries appeared to save successfully but were not actually created; added missing required form fields (effortWorkingTimeType, effortChargeability, edit_form_data_submitted) that Projektron expects

### Changed
- **Projektron time booking requires CSRF token** - Users must now provide their CSRF_Token cookie alongside JSESSIONID for worklog booking; this improves reliability by using the browser's existing token instead of trying to extract it from HTML

## 2025-12-01

### Added
- **Release Notes page** - New page displaying changelogs from all Workoflow components (Bot, Platform, Tests, Load Tests, Infrastructure) with GitHub links and project descriptions
- Accessible via Settings dropdown → Release Notes
- Content cached for 1 hour for performance
- **Projektron worklog retrieval** - AI agents can now fetch time entries from Projektron by specifying a date, returning the full week's bookings with task names and hour breakdowns
- **Projektron time booking** - AI agents can now book time entries directly to Projektron tasks with support for hours, minutes, description, and billable/non-billable classification

## 2025-11-27

### Fixed
- **Agent response display issues** - Fixed encoding problems that could cause garbled characters (like corrupted checkmarks) in AI agent responses

### Improved
- **Simpler Projektron setup** - Removed CSRF token requirement from Projektron integration; only JSESSIONID cookie is now needed for authentication
- **Better AI agent compatibility** - Streamlined agent instructions for improved performance with newer AI models including GPT-5

## 2025-11-26

### Fixed
- **Jira agent HTTP tool confusion** - Fixed issue where Jira AI agent incorrectly passed system prompt data to HTTP tools; simplified prompt to match working Confluence pattern
- **Skills API user isolation** - Fixed security issue where Skills API could return integration data from all users in an organisation; now requires workflow_user_id parameter and properly filters by user
- **Cleaner magic link login experience** - Removed unnecessary success notification banner after logging in via magic link for a smoother user experience

### Added
- **Smart document format selection** - AI agent now intelligently chooses between PDF and PowerPoint based on user request
- **Explicit format override support** - When you explicitly mention "als PDF" or "PDF Datei", the agent respects your choice even when asking for a "Präsentation"
- **Format clarification prompts** - For ambiguous requests like "Erstelle Unterlagen", the agent asks which format you prefer instead of guessing

### Fixed
- **PDF/PowerPoint format confusion** - Fixed issue where requesting a "Präsentation in Form einer PDF Datei" would incorrectly create a PowerPoint instead of respecting the explicit PDF request

### Improved
- **Detailed API error messages** - Tool execution errors now include specific error details, HTTP status codes, context (tool_id, tool_name, integration_type), and actionable hints for troubleshooting
- **Credential validation** - Clear error message when integration credentials are missing or invalid

## 2025-11-25

### Added
- **Jira reporter field support** - AI agents can now set themselves as the reporter when creating Jira issues using their own account ID
- **Jira get myself tool** - New tool to retrieve the current authenticated user's account information, enabling proper reporter assignment during issue creation

### Changed
- **Complete README rewrite** - Professional documentation with logo, feature overview, architecture diagram, quick start guide, API examples, and development instructions

## 2025-11-24

### Changed
- **Compact navigation** - Grouped navigation items into two dropdown menus (Workspace and Settings) with icons for a cleaner header

### Added
- **Projektron integration** - New integration for Projektron time tracking and project management system
- **Projektron task discovery** - View all available projects and tasks from your Projektron account with direct booking URLs for quick time entry access

### Changed
- Workflow visualization reverted back to cloud-hosted n8n service for better reliability and maintenance-free operation
- Experimental integrations indicator moved from separate badge to flask icon in type column with hover tooltip for cleaner table layout

### Fixed
- Fixed Audit Log table not responding to clicks - sorting by column headers and JSON data modal now work correctly
- Fixed Projektron task fetching error in AI workflows - resolved issue where n8n agent would fail with "schema validation error" when trying to get project and task lists
- Fixed Projektron integration not working in n8n workflows - simplified system prompt to match working Jira pattern by removing overly detailed tool schema documentation that was confusing the AI agent
- Fixed issue where Jira project metadata wasn't returning available issue types, preventing AI agents from creating Jira issues
- Fixed language selector not displaying flag emojis - flags now render properly instead of showing country codes like "GB"

### Added
- **Audit Log sorting** - Click column headers to sort audit logs by timestamp, action, user, or IP address in ascending or descending order
- **Audit Log JSON viewer** - Click any data cell to open a formatted JSON viewer with syntax highlighting, search functionality, and copy-to-clipboard button for easy debugging
- **Audit Log page** - New page showing complete history of all activities in your account and organization including user actions, integration changes, file operations, and tool executions
- **Audit Log search and filtering** - Search by action name and filter by date range to quickly find specific activities
- **Audit Log detailed view** - Click to expand any log entry to see full details including complete data, IP addresses, and user agent information
- **Tool execution tracking** - All API tool executions are now logged in the audit trail, providing visibility into what integrations are being used and when
- **SAP Cloud for Customer (C4C) integration** - New integration for managing sales leads in SAP C4C with AI assistance
- **SAP C4C lead creation** - Create new leads with customizable fields including contact information, company details, qualification levels, and custom SAP fields
- **SAP C4C lead search** - Search leads using powerful filters (by status, company, qualification level, etc.) with support for complex queries
- **SAP C4C lead management** - View detailed lead information, update lead fields, and track lead status changes through natural language
- **SAP C4C pagination support** - Browse through large lead lists with automatic pagination for better performance
- **Jira issue creation** - Create new Jira tickets (stories, bugs, tasks) directly from chat with automatic field discovery that adapts to your Jira configuration
- **Jira issue updates** - Update any field on existing issues including summary, description, priority, assignee, labels, and custom fields
- **Jira bulk operations** - Create up to 50 issues at once for faster sprint planning and task setup
- **Jira user management** - Search for team members and assign issues with automatic user lookup
- **Jira issue linking** - Link related issues together (blocks, relates to, duplicates) to show relationships between work items
- **Jira priority and metadata discovery** - Automatically discover available priorities, components, and required fields for your specific Jira projects
- **Jira custom field support** - Full support for custom fields across all operations, automatically handling different field types and validation rules
- **GitLab merge request workflow completion** - AI agents can now merge merge requests, approve/unapprove changes, check approval status, assign reviewers, trigger merge request pipelines, and view all pipelines for a merge request - enabling fully automated code review workflows from review to merge
- **GitLab CI/CD pipeline and job control** - AI can now retry or cancel failed pipelines and jobs, trigger new pipelines on any branch, and download job artifacts for analysis - giving you complete control over your continuous integration workflows
- **GitLab repository file management** - AI agents can now create, update, and delete files directly in repositories with commit messages, compare branches to see what changed between any two points, and protect branches with access control rules - enabling automated code modifications and repository management
- **GitLab branch and tag operations** - AI can now create and delete branches for feature development, create and delete tags for releases (with automatic version detection for semantic versioning), enabling release automation workflows like "create tag v2.4.0 from main branch"
- **GitLab issue collaboration** - AI agents can now add comments to issues, list all issue discussions, assign users to issues, and search issues across all your projects - making issue management and team collaboration easier
- **GitLab merge request discussions** - AI can now create discussion threads on merge requests, resolve discussions when feedback is addressed, and reply to existing threads - improving code review collaboration
- **GitLab project organization** - AI agents can now list project labels, list project milestones with filtering by state, and view all issues in a milestone - helping you track project progress and organize work
- **GitLab team collaboration** - AI can now list project members with their access levels and search for users by name or username - making team management and user lookup easier
- GitLab agent can now identify specific failing jobs in CI/CD pipelines - AI can tell you exactly which build, test, or deployment step failed instead of just showing "pipeline failed"
- GitLab agent can now read actual error logs from failed jobs - AI analyzes error messages, stack traces, and console output to explain what went wrong and suggest fixes
- GitLab pipeline troubleshooting now includes detailed job information - see job names, stages, failure reasons, and execution times for comprehensive debugging
- GitLab agent can now list your merge requests across all projects - see all MRs assigned to you or created by you without specifying individual projects, supports filtering by state (opened/closed/merged) and searching across your entire GitLab workspace

### Improved
- **Audit Log now captures complete request payloads** - See exactly what parameters were sent to each tool execution with automatic redaction of sensitive fields like passwords and API keys for security
- **Audit Log now stores response data** - View what data was returned by tool executions (up to 5KB per response) for debugging and compliance purposes
- **Audit Log tabbed interface** - Easier navigation between request and response data with separate tabs, making it simple to inspect API calls and their results
- GitLab agent provides more actionable pipeline debugging - instead of "pipeline failed, check the logs", AI now says "PHPStan job failed due to type error in UserController.php line 45"
- GitLab job trace analysis now faster and more efficient - shows only the last 500 lines where errors typically appear, speeding up AI diagnostics while reducing processing overhead
- GitLab integration now covers complete software development lifecycle - from creating branches and modifying code, to reviewing and merging changes, to managing CI/CD pipelines and creating release tags - all available through natural language commands
- **API tools now include tool_id field** - Tools endpoint response now provides both "name" and "tool_id" fields with the same value, improving consistency between tool discovery and tool execution endpoints for AI agents

## 2025-11-21

### Changed
- Simplified SharePoint search to single endpoint - AI agents now build KQL (Keyword Query Language) queries directly instead of using multiple search tools
- SharePoint search now accepts full KQL syntax including wildcards, field filters (author:, filename:, filetype:), date ranges, and boolean operators for more powerful searches
- Reduced SharePoint agent prompt complexity by 50% - cleaner, faster, more maintainable AI instructions

### Improved
- SharePoint search results now always include clickable links to documents and pages for faster access
- Main AI agent now preserves clickable links from SharePoint and other integrations - links are no longer stripped during response formatting, ensuring you can directly access documents, pages, and resources
- SharePoint agent now intelligently filters search results by relevance - only shows documents actually about your topic instead of overwhelming you with documents that just mention keywords
- Search results are now ranked by relevance - documents with matching titles appear first, followed by documents with relevant content
- SharePoint agent now explains when results were filtered (e.g., "found 4 relevant documents out of 25 total results")
- SharePoint search results now include complete drive information - improved reliability when accessing documents found through search

### Fixed
- SharePoint search now returns only actual documents from your environment - fixed critical issue where AI agent could show example/placeholder documents instead of real search results from SharePoint API
- SharePoint document reading now works reliably - fixed error where AI agents couldn't open documents found through search, now successfully accesses files in SharePoint document libraries
- SharePoint search now returns comprehensive results - fixed issue where "filename:" prefix in queries caused post-filtering that excluded valid results
- SharePoint agent now correctly uses the primary search tool (search_pages) for comprehensive document discovery matching native SharePoint search behavior

## 2025-11-20

### Added
- New Skills API endpoint (`/api/skills/`) for retrieving AI agent system prompts dynamically - AI workflows can now fetch integration-specific instructions and tool documentation on demand
- System prompts now visible on skill configuration pages - users can review AI agent instructions for each connected integration (Jira, Confluence, SharePoint, Trello, GitLab) directly in the web interface
- Skill request feature - users can now request new integrations directly from the Skills page by clicking "Request New Skill" button and filling out a form with service name, description, API documentation URL, and priority level

### Changed
- System prompts now managed within skill definitions instead of separate XML files - prompts are now part of the codebase for easier version control and maintenance
- Improved integration architecture with Platform Skills and Personalized Skills distinction - clearer separation between system-level tools (file sharing, PDF generation) and user-specific integrations (Jira, Confluence)

### Fixed
- Skills API endpoint now accessible without authentication errors - resolved HTTP 500 error preventing platform from loading
- System prompt section on skill edit pages now displays correctly with proper translations and dark theme styling - replaced placeholder text and white backgrounds with formatted XML display
- Fixed critical system prompt incompleteness for all PersonalizedSkill integrations - AI agents now receive complete instructions matching production specifications for SharePoint (6%→100%), GitLab (5.5%→100%), Trello (7%→100%), Confluence (24%→100%), and Jira (72%→100%), including all workflow guidelines, tool definitions, example interactions, best practices, and safety rules that were previously missing

## 2025-11-19

### Added
- Kanban board support for Jira - AI agents can now work with Kanban boards in addition to Scrum boards (previously only Scrum was supported)
- New Jira board detection tool - AI agents can now check if a board is Scrum or Kanban before choosing the right approach
- Universal Jira board tool - automatically detects board type and retrieves issues (active sprint for Scrum, all issues for Kanban)

### Improved
- Enhanced GitLab integration tool descriptions with detailed return value documentation - AI agents now know exactly what data they'll receive from all 24 GitLab tools (project details, MR states, issue info, commit data, pipeline status, package versions, file content, branch info) making it easier to search code, review merge requests, track issues, monitor CI/CD pipelines, and manage repositories
- Enhanced Jira integration tool descriptions with detailed return value documentation - AI agents now know exactly what data they'll receive from each Jira tool (issue keys, statuses, assignees, sprint details, transition options, comment metadata) making it easier to search issues, manage workflows, track sprints, and update tickets
- Enhanced Trello integration tool descriptions with detailed return value documentation - AI agents now know exactly what data they'll receive from each Trello tool (board names, card details, list positions, comments, checklists) making it easier to search boards, manage cards, and track tasks
- Enhanced SharePoint integration tool descriptions with detailed return value documentation - AI agents now know exactly what data they'll receive from each SharePoint tool (file names, sizes, URLs, timestamps, author info) making document search, file listings, and page reading more reliable
- Enhanced Confluence integration tool descriptions with detailed return value documentation - AI agents now know exactly what data fields they'll receive (page IDs, titles, URLs, content, version info) making it easier to work with Confluence pages, comments, and search results
- Better error messages for Kanban boards - when trying to get sprints from a Kanban board, the system now explains that Kanban boards don't have sprints and suggests using the correct tool instead
- SharePoint search now finds significantly more results using intelligent multi-keyword search - the system automatically searches with all relevant variations (synonyms, German+English translations, acronyms) in a single query
- SharePoint search results are now grouped by type (Files, Sites, Pages, Lists, Drives) matching SharePoint's native search interface - making it easier to find what you're looking for
- Simplified SharePoint search workflow - agents now make one comprehensive search instead of multiple attempts, providing faster results
- Better error messages when SharePoint search fails - now includes troubleshooting tips about permissions, token validity, and Search API configuration

### Fixed
- Fixed Kanban board returning no issues - AI agents can now successfully retrieve all issues from Kanban boards (the tool was using an invalid query that always returned empty results)
- Fixed n8n Main Agent workflow errors - corrected parameter schema configuration that was preventing multi-agent workflows from executing properly
- Fixed Jira integration failing with HTTP 410 errors - updated to use Jira's current API after Atlassian removed the deprecated endpoint, restoring full functionality for searching issues and retrieving Kanban board data
- Fixed Jira search returning HTTP 400 errors - switched to standard Jira API endpoint that works more reliably with all query types
- Improved error messages when Jira operations fail - now shows detailed Jira error descriptions instead of generic "HTTP 400" messages, making it easier to understand and fix issues like invalid queries, missing permissions, or incorrect board/issue IDs
- Fixed "Sprints not supported" error when working with Kanban boards - system now detects board type and uses the appropriate method to get issues
- Fixed SharePoint search returning no results for multilingual queries - system now automatically includes both German and English keywords for better coverage in bilingual workspaces
- Fixed missing clickable links in search results across all agents - SharePoint, Confluence, Jira, and Trello search results now include direct URLs to access documents, pages, issues, and cards with a single click

## 2025-11-18

### Added
- Comprehensive multi-agent AI system documentation including n8n workflow configurations and system prompts for JIRA, Confluence, SharePoint, GitLab, and Trello integrations
- n8n workflow JSON files for Main Agent (orchestrator) and JIRA Agent with detailed import and configuration guides
- System prompt templates for all specialized agents with tool definitions, workflow guidelines, and best practices

### Improved
- Deployment reliability enhanced with automatic asset verification - production deployments now automatically rebuild frontend assets if they're missing, preventing 500 errors after Docker cleanup operations
- Production deployments now use clean builds to ensure all components are properly compiled and up-to-date

### Fixed
- Fixed header overlap issue on homepage for visitors - navigation now displays cleanly without visual overlap

## 2025-11-13

### Added
- Multiple integration type filtering in REST API - you can now request tools from several integrations at once using comma-separated values (e.g., `tool_type=jira,confluence,sharepoint`)
- This enables more flexible tool queries for AI agents and automation platforms that need access to multiple service types simultaneously

### Improved
- Simplified and optimized API integration code for better maintainability and performance
- API tool filtering is now more efficient when handling requests for multiple integration types
- Improved AI agent tool selection by adding domain information to integration descriptions - AI agents can now automatically identify the correct integration when you mention a URL (e.g., when asked about https://company.atlassian.net/browse/TICKET-123, the agent knows to use the matching Jira instance)

## 2025-10-30

### Changed
- Redesigned Skills page from card layout to table layout for better overview and information density
- Improved mobile responsiveness with adaptive table design that transforms into stacked cards on small screens
- Modernized architecture using component-based design (Twig Components, Stimulus controllers) for better maintainability
- Skills page now loads faster and provides better visual hierarchy for managing multiple integrations
- Simplified Skills page by removing search functionality - all integrations now displayed in a clean, organized table without search distractions
- Simplified Personalized Skills management - removed toggle switches from skills list, simply delete skills you no longer need
- Unified table design - both Personalized Skills and Platform Skills now use the same consistent column layout for easier navigation
- Platform Skills table now matches the look and feel of Personalized Skills with consistent columns: Instance Name, Type, Status, Last Accessed, and Actions
- "Manage Platform Skills" button moved to prominent header position with orange styling for better visibility

### Improved
- Cleaner action buttons layout in table format with consistent spacing and alignment
- Better use of screen space with condensed table view showing more integrations at once
- Streamlined interface reduces clutter while keeping all functionality easily accessible
- Jira and Confluence integration icons now display larger and clearer for better visibility
- GitLab integration icon simplified to show only the tanuki logo for better consistency with Jira and Confluence icon styles
- Last accessed dates now appear more compact and fit better on screen without line breaks
- Delete button now has proper styling with consistent appearance across all integrations
- Test Connection modal now displays properly centered on screen instead of appearing at bottom-left
- Action buttons (Edit, Test Connection, Delete) now stay on the same line without wrapping for cleaner table layout
- Simpler workflow for managing skills - delete integrations you no longer use instead of toggling them on and off
- Connection test results modal redesigned with modern minimalist style, larger animated icons, and color-matched close buttons for better visual feedback
- Modal dialogs now feature smooth animations, enhanced shadows, and status-based color accents (green left border for success, red for errors)

### Fixed
- "Add Personalized Skill" dropdown now displays correctly as a vertical menu instead of showing skill badges in a horizontal row
- Platform badge translation now displays correctly without missing translation key errors
- Platform Skills configuration is now user-specific - each user can now independently enable/disable platform tools for their own account without affecting other users in the organization
- Integration type badges on General page now display with orange styling matching the Skills page
- Notification bar positioning - status messages now correctly appear below the top navigation bar instead of covering it
- Test Connection modal positioning - connection test results now appear in the center of the screen as expected
- Integration icons for Jira and Confluence now show clearly at proper size without text overflow

### Changed (Earlier)
- Simplified notification messages - status messages now appear as a clean, full-width bar directly under the navigation instead of an animated overlay, making them less disruptive and easier to read
- Notifications now require manual dismissal - messages stay visible until you close them with the X button, ensuring you don't miss important information
- Improved skill management workflow - tool toggles for Personalized Skills moved to dedicated edit pages, allowing you to manage skill tools when editing credentials instead of from the skills list page
- Enhanced Platform Skills management - all platform tool settings now managed through a dedicated "Manage Tools" page, providing a centralized location to enable or disable built-in capabilities for your organization

## 2025-10-29

### Added
- Channel Type selection for organizations - choose between Common, eCommerce, or MS Teams to categorize your channel's purpose
- Microsoft Teams bot configuration - configure MS Teams bot integration with App Type (MultiTenant/SingleTenant), App ID, App Password, and Tenant ID
- Workflow URL field in Channel settings - separate field specifically for n8n workflow visualization, with clear placeholder examples showing the difference between webhook URLs and workflow URLs
- Help text explaining URL differences - tooltips now clarify that Webhook URLs are for AI agents to trigger workflows, while Workflow URLs are for viewing the workflow diagram
- Enlarge button for workflow visualization - users can now view workflows in a fullscreen modal (90% of screen size) for better visibility of complex workflows with many nodes
- Workflow User ID in Profile page - users can now easily view their personal workflow identifier in their profile

### Changed
- Enhanced security for sensitive credentials - N8N API keys and Microsoft Teams app passwords are now encrypted when stored, providing better protection for your integration credentials
- Footer GitHub links now point to the official Workoflow organization repositories
- Channel page now organized with "Channel Settings" section - basic channel configuration fields including Webhook URL are now grouped under a clear section headline for easier navigation
- Agent Type field renamed from "Webhook Type" - clearer naming that better reflects the purpose of selecting how your AI agent communicates
- Profile page now displays Workflow User ID - moved from Channel settings to personal profile for better organization
- Workflow visualization now shows complete n8n workflows - the Channel page displays all nodes and connections from your n8n workflow, giving you a full view of your automation
- Faster workflow preview loading - workflow diagrams now appear more quickly with improved performance
- Workflow visualization uses official n8n component - ensures compatibility with all n8n workflow features and updates
- Improved error messages for workflow visualization - more specific feedback when workflow URLs are misconfigured
- Workflow visualization now displays in dark mode - matches the application's dark theme for a consistent visual experience
- Larger workflow canvas - increased from 600px to 800px height for better visibility of complex workflows
- Cleaner workflow view - removed instructional text overlay for a more professional, streamlined appearance
- Workflow viewer supports full interaction - users can pan, zoom, and explore node details for better workflow understanding

### Fixed
- Production workflow URLs now work correctly - workflow visualization no longer incorrectly adds port numbers to production domain URLs, resolving "Idle timeout" errors on hosted environments
- Status messages now appear at the top of the page - success, error, and info messages are now displayed consistently right below the navigation bar across all pages
- Added close button to status messages - users can now dismiss messages by clicking the X button
- Status messages auto-dismiss after 5 seconds - messages automatically fade out for better user experience
- Improved status message styling - all message types (success, error, warning, info) now have consistent, modern styling with smooth animations
- Workflow visualization loading error resolved - fixed browser module resolution issue that prevented the N8N workflow viewer from rendering
- Workflow visualization now loads properly on all browsers - resolved "module specifier" error that appeared in browser console
- All workflow connections now visible - node connections in the workflow visualization are now displayed properly

## 2025-10-28

### Added
- Intelligent JIRA status transitions - users can now simply say "set ticket to Done" and the system automatically navigates through the entire workflow path
- JIRA workflow transition support - AI agents can now change ticket statuses following proper workflow paths
- Automatic workflow path finding - system intelligently determines required intermediate steps to reach target status
- Loop detection and prevention - safely handles complex workflows without getting stuck in infinite loops
- Status change capability with automatic workflow validation - prevents invalid status transitions that would normally fail
- Available transitions discovery - bots can ask which status changes are possible for any ticket
- End-to-end tests for JIRA integration - automated tests now validate real JIRA API connections and data retrieval
- JIRA ticket content validation in tests - ensures issue summaries, story points, and acceptance criteria are correctly retrieved

### Fixed
- Skills page header layout - the heading and explanation text now display properly without overlapping, with improved spacing and readability
- GitLab "Test Connection" button now works correctly - the connection test feature is now accessible and functional
- Improved GitLab connection test error messages - users now see detailed diagnostics when GitLab connection fails, including specific error reasons and helpful suggestions
- Fixed API URL in dashboard - the API integration URL now uses the correct endpoint path

## 2025-10-27

### Changed
- Renamed "Tools" to "Skills" - the feature for managing integrations is now called "Skills" throughout the interface for better clarity about AI agent capabilities
- Updated navigation menu from "Tools" to "Skills" - access your AI agent skills from the main navigation
- Skills page now includes explanation section - helpful information about personalized and platform skills appears at the top of the Skills page
- "System Tools" renamed to "Platform Skills" - built-in capabilities that don't require external authentication are now called Platform Skills
- "Add Personal Integration" renamed to "Add Personalized Skill" - clearer terminology when connecting external services with your credentials
- Skills page URL changed from /tools to /skills - bookmarks and links will need to be updated

### Fixed
- Test suite now properly validates English translations - all automated tests updated to work with the English interface

### Changed
- Renamed "Instructions" page to "Channel" - the navigation menu and page title now reflect the new name for better clarity

### Added
- Channel creation button on Channel page - administrators can now create multiple channels directly from the Channel page
- Multi-channel support for administrators - admin users can manage multiple separate channels with independent configurations
- Comprehensive Channel documentation - detailed guide explaining how channels work as API gateways for third-party integrations
- GitLab integration for source code management - AI agents can now access GitLab repositories, merge requests, issues, and CI/CD pipelines
- Browse GitLab projects - view all accessible projects or filter by membership
- Access repository content - read files, browse directory trees, and navigate branches and tags
- View commit history - see detailed commit information and track code changes over time
- Manage merge requests - search, view, create, update, and comment on merge requests with full diff support
- Work with issues - search, view, create, and update issues within projects
- Monitor CI/CD pipelines - check pipeline status, view execution details, and track deployment progress
- Access package registry - search and view packages published to GitLab's package registry
- Full API integration - all GitLab tools available through REST API for automation platforms
- Trello integration for project management - AI agents can now access and manage Trello boards, lists, and cards
- Search across all Trello boards and cards - AI agents can find specific cards using keywords or criteria
- View all accessible Trello boards - get a complete overview of available project boards
- Read board structure - see all lists and cards organized on any board
- Extract card details - access card titles, descriptions, members, labels, due dates, and attachments
- Read card comments - see all discussion threads on cards
- Access card checklists - view todo items and their completion status within cards
- Create new cards - AI agents can add tasks to Trello lists based on user requests
- Update existing cards - modify card details, move between lists, or change due dates
- Add comments to cards - AI agents can post updates and responses directly to Trello cards
- Full workflow automation support - all Trello tools available through REST API for n8n and other automation platforms

### Changed
- Channel name editing centralized to Instructions page - channel names can now only be changed from the Instructions page for better organization

## 2025-10-24

### Fixed
- Jira connection test now works correctly and no longer shows false permission errors when credentials are valid

### Improved
- Connection test error messages now provide detailed explanations instead of generic "Connection failed" messages
- Each error includes specific suggestions on how to fix the problem (e.g., "Check your API token", "Verify the URL is correct")
- Test connection results now show technical details about what went wrong (authentication failed, network timeout, wrong permissions, etc.)
- Error messages help troubleshoot integration issues faster with clear guidance
- Both Jira and Confluence integrations now provide comprehensive error feedback during connection testing

## 2025-10-25

### Fixed
- Connection test modal now displays proper titles instead of technical translation keys
- Icons now display correctly throughout the application (previously missing due to Font Awesome not being installed)

## 2025-10-24

### Fixed
- Confluence page creation now works correctly when using space keys (like "AT", "PROJ", "IT") - previously failed with "Space not found" errors
- Confluence API connections now work correctly - fixed incorrect API endpoint paths that caused "404 Not Found" errors
- All Confluence features (search, page retrieval, comments, page creation) now use correct API paths

### Improved
- Confluence URL input now provides instant validation feedback with helpful visual indicators
- Clear guidance on expected URL format prevents configuration errors (base URL without /wiki path)
- Real-time validation shows green checkmark for correct URLs and red error for invalid formats
- Validation now prevents users from entering URLs with /wiki path (which causes API errors)
- "Test Connection" button now performs comprehensive API testing for Confluence integrations
- Connection tests verify both v1 and v2 API endpoints to ensure all features work correctly
- Detailed error messages explain exactly what went wrong (authentication failed, wrong permissions, network issues, etc.)
- Each error includes specific suggestions on how to fix the problem (e.g., "Create a new API token at...")
- Credential validation during setup now performs actual API calls instead of just checking if fields are filled
- Test Connection results now display in a beautiful modal dialog instead of browser alerts
- Modal includes animated success/error icons with smooth transitions for better visual feedback
- Error messages are now easier to read with better formatting and color coding
- Modal can be closed by clicking outside, pressing ESC key, or clicking the close button

## 2025-10-23

### Fixed
- Tool toggle buttons on integration management page now work correctly - fixed incorrect API endpoint URLs

## 2025-10-21

### Added
- Language switcher in header - easily switch between German and English with flag indicators
- Tagline "EFFICIENCY IS YOURS" displayed under platform title for brand reinforcement

### Changed
- Default language changed from German to English for wider accessibility

### Fixed
- Instructions page workflow visualization no longer gets stuck on "Loading..." when N8N webhook URL is not configured - now shows helpful message to configure the webhook URL
- Non-admin users on Instructions page now see clear guidance to contact an administrator when workflow configuration is needed
- Language switching now works properly across all pages - selected language persists when navigating between pages
- All navigation menu items now properly translate when switching languages (Instructions, Profile, Members, Login, Logout)
- Instructions page content now fully translates including labels and section headings
- Test suite now works correctly after integration management URL structure update

## 2025-10-20 (Latest)

### Improved
- Workflow visualization now shows only essential nodes for clearer understanding - displays webhooks, AI agent with connected tools, and response nodes in an organized layout
- N8N workflow visualization automatically filters out intermediate processing nodes to focus on the main workflow structure
- Workflow nodes are now arranged vertically for better readability with tools grouped below the AI agent
- Docker compatibility for N8N API connections - workflow visualizations now work correctly when running in containerized environments

## 2025-10-20

### Added
- New Instructions page for managing AI agent configuration and workflow settings
- Agent personality customization through system prompts - define how your AI agent responds
- Webhook integration support for connecting external workflow systems (Common and N8N)
- Visual workflow viewer for N8N integrations - see your automation workflows at a glance
- N8N API key authentication for secure workflow data access
- Organization-wide agent settings management
- Workflow user ID display on Instructions page for easy reference

### Changed
- Button to add integrations now labeled "Persönliche Integration" to better distinguish user integrations from system tools
- Profile page layout improved to a cleaner 2-column grid design for better readability

### Fixed
- Workflow visualization on Instructions page now displays correctly - interactive controls are visible with proper dark theme styling
- User registration API now correctly uses the real email address from external systems (like Microsoft Teams) instead of generating placeholder emails
- Magic link authentication now correctly redirects to the general overview page after successful login
- Magic link authentication now correctly identifies users when their display name changes - prevents duplicate accounts and authentication failures

## 2025-10-18

### Added
- Jira integration can now add comments to issues - AI agents can post comments directly to Jira tickets

## 2025-10-17

### Added
- New command to export all integration tools to XML format - provides complete documentation of all available tools with descriptions and parameters
- Tool export command supports filtering by category (system tools or user integrations) for targeted documentation
- New channel system allowing users to belong to multiple channels for future feature expansion
- Channel association during user registration - external systems can now assign users to specific channels when creating accounts
- Automatic channel creation through the registration API - channels are created on-demand when users are registered
- Optional channel parameters in registration API for seamless channel assignment during onboarding
- New automated user registration API for seamless onboarding - external systems can now create users instantly via API call
- Registration API returns magic links for immediate user authentication - no waiting for users to click links first
- Users and organizations are now created immediately when registration API is called - improves integration with external workflow systems
- Comprehensive API documentation for the new registration endpoint with examples in multiple programming languages
- Comprehensive documentation about magic link authentication lifecycle and session management
- Confluence page creation capability for AI agents - can now create new pages directly in Confluence spaces
- Confluence page update capability for AI agents - can now update existing pages with new content while handling version conflicts automatically
- Support for multiple content formats when creating or updating Confluence pages - AI agents can use familiar markdown, plain text, or HTML instead of Confluence's native format
- Intelligent space identification for Confluence pages - accepts both human-readable space keys (like 'PROJ') and technical space IDs
- AI-friendly error messages for Confluence operations - provides clear guidance when page creation or update fails, helping AI agents correct and retry
- Automatic content format conversion - markdown and plain text are automatically converted to Confluence's storage format
- Version conflict detection and handling for page updates - automatically retrieves current version to prevent update conflicts
- MinIO service now included in automated testing workflows to prevent test failures

### Fixed
- Application startup error that prevented the platform from loading properly
- Authentication system configuration issue that blocked user login functionality
- Login page styling for Magic Link authentication instructions box
- Corrected magic link validity display from "15 minutes" to actual "24 hours" on login page
- Fixed incorrect "single-use" claim - magic links can be used multiple times within validity period
- Database field size for channel UUIDs increased to support Microsoft Teams channel IDs
- Removed deprecated database update option to prepare for future compatibility
- Fixed git repository permission warnings in Docker container environment
- Updated frontend dependencies to fix security vulnerability
- File storage service startup issues that prevented application from loading when MinIO was unavailable
- Automated test workflow now builds frontend assets before running tests

### Changed
- Login page now displays instructions for Magic Link authentication instead of Google login
- Users are directed to use Magic Links sent via Microsoft Teams or other connected chat applications
- Magic link authentication now supports both immediate registration (new API flow) and deferred registration (existing flow) for backward compatibility
- User creation logic centralized into reusable service for consistent behavior across registration methods
- Extended user session duration from default (~24 minutes) to 24 hours for better user experience
- Sessions now remain active for full 24 hours regardless of user activity
- **Simplified database management** - removed migration files in favor of automatic schema updates directly from entity definitions
- Database updates now use automatic schema synchronization for faster development workflow

### Improved
- Login page layout now uses wider design for better readability
- Magic Link authentication channels displayed side-by-side for cleaner presentation

## 2025-10-15

### Added
- Server-side validation for integration forms - prevents saving integrations with empty or duplicate names
- Clear error messages displayed when integration name is missing or already in use
- Form error messages now properly translated in both German and English

### Improved
- JIRA connection test now validates credentials with actual API calls - immediately detects invalid tokens or incorrect URLs
- Integration setup forms now preserve entered data when validation fails for easier corrections
- Editing integrations is now faster - changing only the name no longer requires re-validating credentials with external APIs
- URLs are automatically normalized when saving integrations - trailing slashes are removed for consistency
- Form validation errors now display with clear bullet points and proper spacing for better readability

## 2025-10-09

### Added
- SAP Commerce and Shopify added to the integration showcase on homepage - demonstrating platform's expanded e-commerce capabilities

### Fixed
- Jira search now uses the correct API v3 endpoint - resolves issue where search queries were failing with recent Jira Cloud instances

## 2025-10-08

### Fixed
- Integration management now properly isolated per user - users can no longer view or modify other users' integrations within the same organization
- Integration editing, deletion, and tool toggling now require user ownership verification

## 2025-09-25

### Added
- New Omnichannel AI Agent promotion section on landing page highlighting platform's ability to create channel-specific AI agents
- Visual hub-and-spoke diagram showing how the platform connects to multiple channels (Teams, E-Commerce, WhatsApp, etc.)
- Three key platform benefits prominently displayed: Channel-Specific Intelligence, Maximum Reusability, and Connected Ecosystem

### Fixed
- Fixed duplicate slashes in Jira and Confluence API URLs that caused 404 errors
- Jira and Confluence integrations now properly validate and format URLs before making API calls
- Added helpful error messages when integration URLs are incorrectly formatted
- Fixed file sharing tool that was failing when uploading files - now properly handles base64 encoded files
- File uploads now work correctly with PDF and other document formats
- Updated Confluence URL placeholder to include /wiki path for better user guidance

## 2025-09-24

### Improved
- SharePoint document reading now works with PDF, Word, and Excel files stored in SharePoint
- AI agents can now extract and understand text content from PDF documents, eliminating previous "cannot extract text" errors
- Excel spreadsheet content is now fully readable by AI agents, including all sheets and cell data
- Word document content extraction now works properly, allowing AI agents to analyze document text
- Documents that previously failed with "unsupported format" errors can now be processed successfully

### Fixed
- SharePoint document reading now correctly identifies and uses site information from search results
- AI agents can now successfully read SharePoint documents after finding them through search
- Improved site identification when reading documents stored in different SharePoint locations
- Personal OneDrive documents can now be accessed and read by AI agents - previously failed with "site not found" errors

## 2025-09-24

### Added
- New document reading capability for SharePoint integration - AI agents can now extract and understand content from Word documents, Excel files, PowerPoint presentations, and PDFs
- Smart handling of large documents - automatically extracts the most relevant portions when files exceed size limits

### Changed
- Improved SharePoint tool descriptions to better guide AI agents on when and how to use each tool
- Search results now include content summaries, helping AI agents identify relevant documents without reading them fully
- Less frequently used SharePoint tools (file browsing, download links, technical list access) are now disabled by default for new integrations to streamline the AI agent experience

### Fixed
- SharePoint integration now properly supports content extraction from Office documents, enabling AI agents to answer questions about document contents rather than just finding files

## 2025-09-23

### Fixed
- Removed deprecated Integration entity system and migrated fully to IntegrationConfig
- Fixed SharePoint authentication by properly using the new IntegrationConfig system
- Resolved database relationship errors preventing user authentication

### Changed
- Consolidated integration management to use single IntegrationConfig system instead of dual Integration/IntegrationConfig
- Updated all commands and controllers to use the new IntegrationConfig repository

## 2025-09-19

### Changed
- Improved integration configuration to use organization-based workflow user IDs for better access control
- Integration configurations no longer store workflow user IDs directly - now properly linked through user organization relationships

### Fixed
- Integration status badge now correctly shows "Inactive" (yellow) when integration is disabled, instead of always showing "Connected" (green)
- Disabled integrations no longer provide tools through the API - all tools are hidden when integration is deactivated
- Fixed integration toggle to work with individual integration instances, not just integration types
- SharePoint tools now correctly appear in API responses when filtered by tool type
- Fixed tool toggle functionality for user integrations - now properly updates existing configurations instead of creating duplicates
- Tool enable/disable toggles now persist correctly and are properly filtered in API responses
- Fixed integration configuration creation to properly track the user who created each configuration
- Fixed workflow user filtering in the API to use the workflow_user_id from user organization relationships
- Fixed organization members page error by correcting database query associations
- Removed workflow user ID field from integration setup forms - now automatically uses the organization's assigned workflow user ID
- Restored SharePoint integration Microsoft authentication functionality
- Fixed edit and delete buttons to work with multiple integration instances

### Changed
- SharePoint and OAuth-based integrations now require immediate authentication - clicking save redirects directly to Microsoft login
- OAuth authentication is now mandatory for OAuth-based integrations - configurations cannot be saved without acquiring access token
- Improved OAuth cancellation handling - temporary configs are automatically cleaned up if user cancels authorization

## 2025-09-19

### Changed
- Reduced button sizes by 50% on the integrations page for a more compact interface
- System tools now grouped into a single full-width card for better organization
- System tools card spans the entire width for improved visibility and cleaner layout
- Integration cards now display user-defined names with badges on the next line for improved readability

## 2025-09-19

### Added
- 12 new AI agent system tools for enhanced automation capabilities:
  - Content learning from uploaded files (CSV, PDF, TXT)
  - Content search across platform data
  - File reading and processing
  - Employee search and profile lookup
  - PDF and PowerPoint generation
  - Web search and page reading
  - Company events management
  - Conversation memory management
  - Issue reporting system
- All system tools can be individually enabled or disabled per user

## 2025-09-19 (Earlier)

### Changed
- System integration "File Sharing" renamed to "Standard" for better clarity
- System badge positioning moved above integration name for improved visual hierarchy
- Reorganized integrations page layout - System Tools section now appears below External Integrations for better visual hierarchy

### Fixed
- API response filtering based on `tool_type` parameter now works correctly
  - `tool_type=jira` returns only Jira integration tools
  - `tool_type=confluence` returns only Confluence integration tools
  - `tool_type=sharepoint` returns only SharePoint integration tools
  - `tool_type=system` returns all system tools (file sharing, etc.)
  - `tool_type=system.share_file` returns specific system integration tools
  - No parameter returns all available tools (user integrations and system tools)
  - Enables precise tool selection for AI agents based on integration type

### Added
- Edit functionality for integrations allowing users to update names and credentials
- Table-style layout for integration tools providing better organization
- Icons for all action buttons (Edit, Test Connection, Delete)
- System Tools section showing all platform built-in tools (like file sharing)
- Enable/disable toggles for individual tools within each integration
- Visual separation between System Tools and External Integrations
- Automatic availability of new system tools without user login required
- Setup wizard for configuring external service credentials
- Support for multiple instances of the same integration (e.g., multiple Jira servers, SharePoint sites)
- Instance naming to identify different connections (e.g., "Production Jira", "Dev SharePoint")
- "Add Another Instance" button to easily connect additional services

### Changed
- Improved integration card layout with better visual structure and no overlapping elements
- Action buttons now contained within integration cards for cleaner interface
- Enhanced visual hierarchy with proper spacing between integration elements
- Complete redesign of integration management system
- All integrations now defined in code - instant availability of new features
- Simplified database structure - only stores user preferences and credentials
- System tools enabled by default for all users and organisations
- External integrations (Jira, Confluence) now have individual tool toggles
- Integration page now groups tools by type (System vs External)
- API now includes instance names in tool descriptions for clarity

### Removed
- Complex database relationships for integration management
- Need for manual database updates when adding new system tools

---

## 2025-09-19 (Earlier)

### Added
- Footer with legal information links (Legal Notice and Data Protection Declaration) available in both German and English
- Footer with links to Workoflow's open source repositories on GitHub (Hosting, Tests, Bot, Integration Platform)
- Global footer display across all pages, whether logged in or not
- System tools management for platform features - all users now have system integrations automatically
- Platform tools section in integration management page with distinct visual styling
- Per-user configuration for system tools - each user can enable/disable individual tools
- Plugin-based integration system for easier management
- Dynamic integration management UI - new integrations automatically appear in web interface
- Central integration registry for better organization

### Changed
- API authentication variables renamed from MCP_AUTH to API_AUTH for clarity
- System tools now excluded from API by default - use `tool_type=system` to include them
- Integrations now use flexible plugin architecture
- Web forms for adding integrations are now generated automatically
- System integrations cannot be deleted, only individual tools can be toggled

### Fixed
- Improved styling for OAuth authentication sections in integration forms to match dark theme
- Fixed visual consistency issues with credential input areas for SharePoint and other OAuth-based integrations
- Integration creation dropdown now properly displays available integration types (Jira, Confluence, SharePoint)
- Fixed service configuration to correctly register and tag integration services

### Improved
- Better separation between platform tools and external service integrations
- Clearer documentation about tool types and authentication requirements
- More maintainable code structure following design principles

## 2025-09-18

### Added
- Enhanced "How It Works" section with comprehensive enterprise-focused explanations
- Channel-based architecture explanation for isolated business areas
- Real-world examples showcasing customer chatbot, admin backend, and internal team use cases
- Enterprise benefits highlighting data sovereignty and precision control
- Visual improvements with color-coded example cards and gradient backgrounds

### Added
- Coming soon landing page with redesigned user interface
- Email waitlist functionality for early access signups
- Modern navigation bar with smooth scrolling
- Showcase of upcoming integrations (Teams, Shopware, Spryker, Magento, WhatsApp)
- Interactive waitlist signup form with real-time feedback
- Multi-tenant support for SharePoint integration - any Microsoft organization can now use the platform

### Changed
- SharePoint OAuth configuration now uses multi-tenant endpoints allowing access from any organization
- SharePoint integration automatically extracts and stores tenant ID from access tokens
- Added consent prompt option for SharePoint connection to force re-authorization when needed

## 2025-09-18

### Added
- Workflow user ID management moved to user-organization level for better multi-tenant support
- Copy curl command button on dashboard for easy API testing
- Direct workflow user ID display on dashboard for quick reference
- Link styling in integration function descriptions for better navigation
- Tool type filtering for API endpoints - can now filter tools by type (jira, confluence, sharepoint, system)

### Changed
- Workflow user IDs are now managed per user-organization relationship instead of per integration
- Enhanced dashboard UI with improved workflow user ID visibility

### Fixed
- FrankenPHP runtime configuration for proper Symfony integration

## 2025-09-09

### Fixed
- SharePoint page search now uses Microsoft Graph Search API for better results
- SharePoint page reading now accepts page names/titles in addition to GUIDs - automatically resolves to correct page ID
- Added detailed logging to help diagnose search issues

### Added
- Enhanced SharePoint search to include SharePoint pages and site pages
- New dedicated SharePoint pages search function for targeted page searches
- Automatic synchronization of integration functions when editing existing integrations
- Inline organization name editing for administrators directly on dashboard
- Organization filtering for integrations list and dashboard views
- Automatic SharePoint site name resolution when accessing pages - system now accepts site names in addition to site IDs
- SharePoint page content extraction - AI agents can now read the actual content of SharePoint pages, not just metadata

### Improved
- SharePoint document search now searches within site-specific content
- Search results now include page metadata and site information
- SharePoint integration error messages now provide detailed information for troubleshooting
- SharePoint page access is now more flexible, accepting both site names and technical site IDs
- SharePoint page reading now includes the actual page content (up to 1000 characters) for AI processing

### Fixed
- API integration validation now correctly checks the integration's assigned organization
- Integrations now properly filtered by selected organization
- Dashboard now only shows integrations for the current organization
- Resolved system deprecation warnings for improved compatibility with future versions
- SharePoint page reading now works correctly with standard Microsoft Graph API
- SharePoint file operations now include error handling for better reliability
- SharePoint pages can now be accessed using site display names instead of requiring technical site IDs

## 2025-09-08

### Removed
- Email functionality and mail server requirements - application no longer sends emails
- MailHog development mail catcher service

### Added
- Unified deployment script for production environments (`./deploy.sh prod`)
- Health check endpoint for monitoring application status
- SharePoint integration with OAuth2 authentication
- Document search and file management for SharePoint libraries
- SharePoint page reading and list item access
- Automatic token refresh for SharePoint connections
- Magic link authentication for automatic user login from external applications
- Display saved integration credentials in edit form for easier updates  
- Documentation translated to English for international users
- Automatic user creation and organization assignment via secure links
- Support for Microsoft Teams bot integration through signed URLs
- Support for multiple organizations per user with many-to-many relationship
- Name-based authentication without requiring email addresses
- Organization switcher dropdown in header for users with multiple organizations
- Session-based organization selection that persists across page navigation
- Automated nightly releases with GitHub Actions workflow

### Fixed
- File viewing and uploading functionality restored
- Integration credentials now visible when editing for verification
- Missing translations for SharePoint OAuth authentication interface

### Changed
- API token fields changed from password to text input for better usability

## 2025-07-09

### Added
- Getting started guide for new users

## 2025-07-07

### Added
- Static assets for improved user interface
- Configurable MinIO public bucket name through environment variables

### Changed
- MinIO bucket configuration now uses environment variables for flexibility

## 2025-07-03

### Added
- Initial release of Workoflow Integration Platform
- OAuth2 Google authentication for secure user access
- Multi-tenant organization management
- Integration management for Jira and Confluence
- REST API for AI agent access
- File management with MinIO S3 storage
- Comprehensive audit logging
- Multi-language support (German and English)
- Automated setup scripts for development and production
- Docker containerization for easy deployment

### Fixed
- Production Docker build with multi-stage asset compilation
- OAuth redirect URI configuration
- HTTPS redirect URLs with trusted proxy support
- MinIO port configuration for better compatibility
- File sharing MIME type detection

### Changed
- Simplified OAuth configuration by removing unused redirect URI parameter
- Optimized production deployment with proper volume mounts
- Improved build process with integrated npm asset building

### Security
- JWT token authentication for API access
- Encrypted credential storage using Sodium encryption
- Basic authentication for REST API endpoints
