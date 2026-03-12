<p align="center">
  <img src="assets/logo_orig_large.png" alt="Workoflow Logo" width="360px">
</p>

<p align="center">
  <a href="https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-integration-platform/actions/workflows/tests.yml"><img src="https://github.com/valantic-CEC-Deutschland-GmbH/workoflow-integration-platform/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://codecov.io/gh/valantic-CEC-Deutschland-GmbH/workoflow-integration-platform"><img src="https://codecov.io/gh/valantic-CEC-Deutschland-GmbH/workoflow-integration-platform/branch/main/graph/badge.svg" alt="codecov"></a>
</p>

# Workoflow Integration Platform

> AI-first enterprise integration hub for orchestrating tools and data across multiple channels

## Overview

Workoflow is a production-ready integration platform designed to serve as a unified hub for managing and orchestrating AI agents across multiple channels and enterprise systems. It provides a central point of control for deploying tailored AI agents with granular permission management and data sovereignty.

The platform solves a critical challenge in modern enterprises: connecting AI agents to business tools (Jira, Confluence, SharePoint, etc.) while maintaining security, audit trails, and multi-tenant isolation. Unlike traditional platforms, Workoflow is built with AI agents as first-class citizens, providing dynamic tool discovery and execution via REST API.

Key differentiators:
- **Data Sovereignty** - Self-hosted, on-premise deployment keeps all data under your control
- **Channel-Specific Intelligence** - Deploy different AI agents for different business functions
- **Granular Permissions** - Define exactly which tools each channel/agent can access
- **Enterprise-Grade Security** - Encrypted credentials, JWT authentication, comprehensive audit logging

## Key Features

- **Multi-Tenant Organization Management** - Workspace isolation with UUID-based organization identities and role-based access control
- **Plugin-Based Integration Architecture** - Extensible system with 7+ user integrations and 13+ system tools
- **REST API with Dynamic Tool Discovery** - AI agents can discover and execute tools at runtime
- **OAuth2 Authentication** - Google and Azure AD authentication support
- **Encrypted Credential Storage** - Sodium (libsodium) encryption for all user credentials
- **Comprehensive Audit Logging** - All critical actions logged with IP, user agent, and sanitized data
- **Multi-Language Support** - German (DE) and English (EN) translations
- **Docker-Based Deployment** - FrankenPHP runtime with Caddy server

## Technology Stack

| Component | Technology |
|-----------|------------|
| Backend | PHP 8.4, Symfony 7.2 |
| Runtime | FrankenPHP (Caddy) |
| Database | MariaDB 11.2 |
| Cache | Redis 7 |
| Storage | MinIO (S3-compatible) |
| Container | Docker & Docker Compose |

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        AI Agents / Bots                          │
│                  (MS Teams, WhatsApp, Slack, etc.)               │
└─────────────────────────────┬───────────────────────────────────┘
                              │ REST API (Basic Auth / JWT)
┌─────────────────────────────▼───────────────────────────────────┐
│                   Workoflow Integration Platform                 │
├─────────────────────────────────────────────────────────────────┤
│  IntegrationRegistry ──► Tool Discovery & Execution             │
│  EncryptionService   ──► Credential Management (Sodium)         │
│  AuditLogService     ──► Activity Logging                       │
├─────────────────────────────────────────────────────────────────┤
│  User Integrations          │  System Tools                      │
│  ├── Jira                   │  ├── File Sharing                  │
│  ├── Confluence             │  ├── PDF Generator                 │
│  ├── SharePoint             │  ├── Web Search                    │
│  ├── GitLab                 │  ├── Knowledge Query               │
│  ├── Trello                 │  └── ...                           │
│  └── ...                    │                                    │
└─────────────────────────────────────────────────────────────────┘
```

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Git
- Google OAuth2 credentials (for user authentication)

### Development Setup

```bash
# 1. Clone repository
git clone <repository-url>
cd workoflow-promopage-v2

# 2. Run setup script
./setup.sh dev

# 3. Configure environment variables in .env
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
ENCRYPTION_KEY=your_32_character_encryption_key

# 4. Access the application
# Application: http://localhost:3979
# MinIO Console: http://localhost:9001 (admin/workoflow123)
```

### Production Deployment

```bash
# 1. As non-root user with Docker access
./setup.sh prod

# 2. Configure SSL certificates
# 3. Update domain in .env
# 4. Configure Google OAuth redirect URIs

# Deploy updates
git pull
./setup.sh prod
```

## Integration System

Workoflow uses a plugin-based architecture where all integrations implement a common `IntegrationInterface`. Integrations are auto-discovered and registered via Symfony's dependency injection.

### User Integrations

External service integrations that require user-specific API credentials:

- **Jira** - Issue tracking, sprint management, workflow transitions
- **Confluence** - Wiki pages, content management, CQL search
- **SharePoint** - Enterprise document management, KQL search
- **GitLab** - Repository management, merge requests, pipelines
- **Trello** - Board and card management
- **SAP C4C** - Customer relationship management
- **Projektron** - Project and task management

### System Tools

Platform-internal tools that don't require external credentials:

- File sharing and management
- PDF and PowerPoint document generation
- Web search and page reading
- Knowledge base management
- Employee directory queries
- Memory management for AI agents

## API Overview

The REST API enables AI agents to discover and execute integration tools dynamically.

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/integrations/{org-uuid}` | List available tools for organization |
| POST | `/api/integrations/{org-uuid}/execute` | Execute a tool |
| GET | `/api/skills` | List all available skills |
| POST | `/api/register` | Register new user/organization |

### Authentication

```bash
# Basic Auth header required
Authorization: Basic base64(username:password)
```

### Example: List Tools

```bash
curl -X GET "http://localhost:3979/api/integrations/{org-uuid}?workflow_user_id=user-123" \
  -H "Authorization: Basic $(echo -n 'workoflow:workoflow' | base64)"
```

### Example: Execute Tool

```bash
curl -X POST "http://localhost:3979/api/integrations/{org-uuid}/execute" \
  -H "Authorization: Basic $(echo -n 'workoflow:workoflow' | base64)" \
  -H "Content-Type: application/json" \
  -d '{
    "tool_id": "jira_search_42",
    "workflow_user_id": "user-123",
    "parameters": {
      "jql": "project = PROJ AND status = Open"
    }
  }'
```

For detailed API documentation, see [CLAUDE.md](CLAUDE.md).

## Security

### Authentication Layers

| Layer | Mechanism | Use Case |
|-------|-----------|----------|
| Web UI | Google OAuth2 / Azure AD | User login |
| REST API | Basic Auth | Machine-to-machine |
| API Tokens | JWT (RSA 4096-bit) | Token-based auth |

### Credential Encryption

User integration credentials are encrypted using Sodium (libsodium) with a 256-bit key. Credentials are:
- Encrypted at rest in the database
- Decrypted only at execution time
- Never exposed in API responses or logs

### Audit Logging

All critical actions are logged with:
- User and organization context
- IP address and user agent
- Sanitized request/response data
- Stored in `/var/log/audit.log`

## Development

### Code Quality

```bash
# Run all checks (PHPStan + PHP CodeSniffer)
docker-compose exec frankenphp composer code-check

# Static analysis (level 6)
docker-compose exec frankenphp composer phpstan

# Check coding standards (PSR-12)
docker-compose exec frankenphp composer phpcs

# Auto-fix coding standard violations
docker-compose exec frankenphp composer phpcbf
```

### Database Schema

```bash
# View pending schema changes
docker-compose exec frankenphp php bin/console doctrine:schema:update --dump-sql

# Apply schema updates
docker-compose exec frankenphp php bin/console doctrine:schema:update --force

# Clear cache
docker-compose exec frankenphp php bin/console cache:clear
```

### Adding New Integrations

1. Create a class implementing `IntegrationInterface` in `src/Integration/`
2. Place in `SystemTools/` (platform-internal) or `UserIntegrations/` (external services)
3. Define tools via `ToolDefinition` objects
4. The integration is auto-registered via `config/services/integrations.yaml`

```php
class MyIntegration implements IntegrationInterface
{
    public function getType(): string { return 'mytype'; }
    public function getName(): string { return 'My Integration'; }
    public function getTools(): array { /* return ToolDefinition[] */ }
    public function executeTool($name, $params, $creds): array { /* logic */ }
    public function requiresCredentials(): bool { return false; }
}
```

## Directory Structure

```
src/
├── Command/              # CLI Commands
├── Controller/           # HTTP Controllers
│   └── Api/              # REST API endpoints
├── Entity/               # Doctrine ORM Entities
├── Integration/          # Plugin-based Integration System
│   ├── IntegrationInterface.php
│   ├── IntegrationRegistry.php
│   ├── SystemTools/      # Platform-internal tools
│   └── UserIntegrations/ # External service integrations
├── Repository/           # Data Access Layer
├── Security/             # Authentication & Authorization
└── Service/              # Business Logic
```

## Environment Variables

Key configuration in `.env`:

```bash
# Application
APP_ENV=dev|prod
APP_SECRET=<random>
APP_URL=http://localhost:3979

# Database
DATABASE_URL=mysql://user:pass@mariadb:3306/workoflow_db

# OAuth
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...

# MinIO S3
MINIO_ENDPOINT=http://minio:9000
MINIO_BUCKET=workoflow-files

# Security
ENCRYPTION_KEY=<32-character-key>
JWT_PASSPHRASE=<passphrase>
API_AUTH_USER=workoflow
API_AUTH_PASSWORD=workoflow
```

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Ensure code passes quality checks (`composer code-check`)
4. Commit your changes (`git commit -m 'Add amazing feature'`)
5. Push to the branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

## License

Proprietary - All rights reserved.

---

<p align="center">
  Built with Symfony 7.2 and FrankenPHP
</p>
