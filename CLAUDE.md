# Workoflow Integration Platform

## Overview
The Workoflow Integration Platform is a production-ready Symfony 8.0 application that enables users to manage various integrations (Jira, Confluence) and provide them via REST API for AI agents.
If you have read this file, greet me with "Hey Workoflow Dev"

### Development Rules
1. **CHANGELOG.md Updates**:
    - Update CHANGELOG.md with user-facing changes only (features, fixes, improvements)
    - Write for end-users and basic technical users, NOT developers
    - DO NOT mention: function calls, function names, file paths, code implementation details
    - DO mention: what changed from user perspective, UI improvements, workflow changes, bug fixes
    - Group changes by date, use sections: Added, Changed, Fixed, Removed
    - Write concise bullet points focusing on the "what" and "why", not the "how"
    - Example: ✅ "Removed address confirmation step for faster returns"
    - Example: ❌ "Modified processReturn function to skip confirmAddress parameter"

2. **Code Quality Verification**:
    - **ALWAYS** run `docker-compose exec frankenphp composer code-check` after code changes
    - This runs both PHPStan (static analysis) and PHP CodeSniffer (coding standards)
    - Available commands:
        - `composer phpstan` - Run PHPStan analysis (level 6)
        - `composer phpcs` - Check coding standards (PSR-12)
        - `composer phpcbf` - Auto-fix coding standard violations
        - `composer code-check` - Run both PHPStan and PHPCS
    - Ensure code passes both checks before considering task complete

3. **llms.txt Maintenance**:
    - `/public/llms.txt` serves AI agents and provides platform overview for LLM assistants
    - Update llms.txt when making these changes:
        - Adding new integration type (e.g., GitHub, Slack, MS Teams)
        - Changing API endpoints or authentication methods
        - Adding major documentation files
        - Updating tool counts or capabilities
        - Modifying architecture (e.g., new authentication mechanism)
    - Keep content concise and AI-friendly (avoid jargon, use clear structure)
    - Verify all links in llms.txt work correctly
    - Test changes by asking Claude/GPT-4 questions about the platform
    - Purpose: Enable AI assistants to understand and explain the platform without human documentation

4. **UI/Styling Guidelines**:
    - **ALWAYS** reference `CONCEPT/SYMFONY_IMPLEMENTATION_GUIDE.md` for UI tasks
    - This guide contains design system patterns, component structures, and styling conventions
    - Add new CSS styles to `assets/styles/app.scss`, NOT inline in templates
    - Follow existing alert patterns (`.alert-*`) for notification components
    - Use CSS custom properties (`var(--space-md)`, `var(--text-sm)`, etc.) for consistency
    - Run `docker-compose exec frankenphp npm run build` after SCSS changes

### Main Features
- OAuth2 Google Login
- Multi-Tenant Organisation Management
- Integration Management (Jira, Confluence)
- REST API for AI Agent access
- File Management with MinIO S3
- Audit Logging
- Multi-language support (DE/EN/RO/LT)

## Architecture

### Tech Stack
- **Backend**: PHP 8.5, Symfony 8.0, FrankenPHP
- **Database**: MariaDB 11.2
- **Cache**: Redis 7
- **Storage**: MinIO (S3-compatible)
- **Container**: Docker & Docker Compose

### Design Principles
- **SOLID**: Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **KISS**: Keep It Simple, Stupid - prioritize simplicity and clarity

### Directory Structure
```
workoflow-promopage-v2/
├── config/           # Symfony Configuration
│   └── services/    # Service definitions
│       └── integrations.yaml # Integration registry config
├── public/          # Web Root
├── src/
│   ├── Command/     # Console Commands
│   ├── Controller/  # HTTP Controllers
│   ├── Entity/      # Doctrine Entities
│   ├── Integration/ # Plugin-based Integration System
│   │   ├── IntegrationInterface.php      # Core contract
│   │   ├── IntegrationRegistry.php       # Central registry
│   │   ├── ToolDefinition.php            # Tool metadata
│   │   ├── SystemTools/                  # Platform-internal tools (no external credentials needed)
│   │   │   └── ShareFileIntegration.php  # File sharing
│   │   └── UserIntegrations/             # External service integrations (require API keys/tokens)
│   │       ├── JiraIntegration.php
│   │       ├── ConfluenceIntegration.php
│   │       └── SharePointIntegration.php
│   ├── Repository/  # Entity Repositories
│   ├── Security/    # Auth & Security
│   └── Service/     # Business Logic
├── templates/       # Twig Templates
├── translations/    # i18n Files
├── docker/         # Docker Configs
└── tests/          # Test Suite
```

## Setup

### Development Environment
```bash
# 1. Clone repository
git clone <repository-url>
cd workoflow-promopage-v2

# 2. Run setup
./setup.sh dev

# 3. Configure Google OAuth in .env
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
```

### Production Environment
```bash
# 1. As non-root user with Docker access
./setup.sh prod

# 2. Configure SSL certificates
# 3. Adjust domain in .env
# 4. Update Google OAuth Redirect URI
```

### Access
- **Application**: http://localhost:3979
- **MinIO Console**: http://localhost:9001 (admin/workoflow123)

## Integration System

### Plugin Architecture
All integrations (user & system) follow a unified plugin-based architecture:

1. **Add new integration**:
   - Create class implementing `IntegrationInterface` in `src/Integration/`
   - Place in `SystemTools/` (platform-internal) or `UserIntegrations/` (external services)
   - Auto-tagged via `config/services/integrations.yaml`

2. **Key Components**:
   - `IntegrationInterface`: Define type, tools, execution logic
   - `ToolDefinition`: Tool metadata (name, description, parameters)
   - `IntegrationRegistry`: Central DI-managed registry

3. **System Tools**:
   - Platform-internal functionality (file sharing, data processing, etc.)
   - Don't require external service credentials (Jira tokens, API keys, etc.)
   - Still protected by API Basic Auth authentication
   - Excluded by default from API, included with `tool_type=system`
   - Example: `ShareFileIntegration`

4. **User Integrations**:
   - Connect to external services (Jira, Confluence, SharePoint)
   - Require user-specific external credentials (stored encrypted in DB)
   - Credentials managed per user/organization
   - Example: Jira, Confluence, SharePoint

### Adding New Tools
```php
// 1. Create integration class
class MyIntegration implements IntegrationInterface {
    public function getType(): string { return 'mytype'; }
    public function getTools(): array { /* return ToolDefinition[] */ }
    public function executeTool($name, $params, $creds): array { /* logic */ }
    public function requiresCredentials(): bool { return false; } // true for user integrations
}

// 2. Register in config/services/integrations.yaml
App\Integration\SystemTools\MyIntegration:
    autowire: true
    tags: ['app.integration']
```

## API Reference

### REST API Endpoints
```
GET /api/integrations/{org-uuid}?workflow_user_id={workflow-user-id}
Authorization: Basic xxxx==
```

The REST API dynamically provides tools based on activated user integrations.

### Available Tools

#### Jira Tools
- `jira_search_{integration_id}` - JQL Search
- `jira_get_issue_{integration_id}` - Get Issue Details
- `jira_get_sprints_from_board_{integration_id}` - Board Sprints
- `jira_get_sprint_issues_{integration_id}` - Sprint Issues
- `jira_add_comment_{integration_id}` - Add Comment to Issue

#### Confluence Tools
- `confluence_search_{integration_id}` - CQL Search
- `confluence_get_page_{integration_id}` - Get Page
- `confluence_get_comments_{integration_id}` - Get Comments

## Entities & Data Model

### User
- Authentication via Google OAuth2
- Roles: ROLE_ADMIN, ROLE_MEMBER
- 1:1 Relationship to Organisation
- 1:N Relationship to Integrations

### Organisation
- UUID for REST API URL
- 1:N Relationship to Users
- Audit Logging at Org level

### Integration
- Types: jira, confluence
- Encrypted Credentials (Sodium)
- Workflow User ID for filtering
- 1:N Relationship to IntegrationFunctions

### IntegrationFunction
- Defines available API functions
- Can be activated/deactivated
- Contains tool descriptions for AI

## Security

### Authentication
- Google OAuth2 for User Login
- Basic Auth for REST API
- JWT Tokens for API
- X-Test-Auth-Email GET Parameter for Tests

### Encryption

The platform uses two separate encryption mechanisms:

#### 1. JWT Token Encryption (API Authentication)
- **Purpose**: Generation and validation of access tokens for API access
- **Files**: 
  - `config/jwt/private.pem` - RSA Private Key (4096-bit) for signing JWT tokens
  - `config/jwt/public.pem` - RSA Public Key for verifying JWT tokens
- **Encryption**: RSA 4096-bit with AES256 password protection
- **Configuration**: Lexik JWT Authentication Bundle
- **Token Lifetime**: 3600 seconds (1 hour)

#### 2. Integration Credentials Encryption (User Secrets)
- **Purpose**: Secure storage of user-integration credentials (Jira/Confluence API Keys)
- **Encryption**: Sodium (libsodium) - modern, secure encryption
- **Key**: 32-character ENCRYPTION_KEY from .env
- **Service**: `App\Service\EncryptionService`
- **Storage**: Encrypted credentials in the `encryptedCredentials` field of the Integration Entity
- **Workflow**:
  1. User enters API credentials
  2. EncryptionService encrypts with Sodium and ENCRYPTION_KEY
  3. Encrypted blob is stored in database
  4. Credentials are decrypted on API access

### Audit Logging
- All critical actions logged
- IP address and User Agent
- Monolog with different channels

## Testing

### Test User Setup
```php
// GET Parameter for test authentication
?X-Test-Auth-Email=test@example.com

// Preconfigured test users
puppeteer.test1@example.com (Admin)
puppeteer.test2@example.com (Member)
```

### REST API Tests
- Puppeteer for UI tests
- MariaDB for database tests

## Deployment

### Production Docker Compose
**CRITICAL**: Always use `docker-compose-prod.yml` for production operations:
```bash
# Correct - uses external volumes with production data
docker-compose -f docker-compose-prod.yml up -d
docker-compose -f docker-compose-prod.yml restart frankenphp

# WRONG - creates new prefixed volumes, loses production data!
docker-compose up -d
docker-compose restart frankenphp
```

The production compose file uses `external: true` volumes that reference existing data volumes (`mariadb_data`, `redis_data`, etc.). Using the default `docker-compose.yml` will create new prefixed volumes and disconnect from production data.

### Environment Variables
All critical configurations via .env:
- Database Credentials
- OAuth2 Settings
- S3/MinIO Config
- Encryption Keys

### Database Schema Management
- **Automatic Schema Updates**: Database schema is updated directly from entity definitions
- **No Migration Files**: Simplified workflow using `doctrine:schema:update --force`
- **Single Source of Truth**: PHP Entities define the database structure

### Monitoring
- Audit Logs in `/var/log/audit.log`
- API Access Logs
- Error Tracking via Monolog

## Maintenance

### Backup
- MariaDB Database
- MinIO S3 Bucket
- Environment Files

### Updates
```bash
# Update dependencies
docker-compose exec frankenphp composer update

# Update database schema from entities
docker-compose exec frankenphp php bin/console doctrine:schema:update --force

# View pending schema changes (without applying)
docker-compose exec frankenphp php bin/console doctrine:schema:update --dump-sql

# Clear cache
docker-compose exec frankenphp php bin/console cache:clear

# rebuild assets
docker-compose exec frankenphp npm run build
```

## Changelog

### Version 1.0.0 (02.07.2025)
- Initial Release
- OAuth2 Google Login
- Organisation Management
- Jira/Confluence Integrations
- REST API Implementation
- File Management
- Multi-Language Support (DE/EN)

