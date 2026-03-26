# Setup.sh Documentation

## Overview

The `setup.sh` script is a comprehensive initialization tool for the Workoflow Integration Platform that automates the entire application setup process. It handles everything from environment configuration to Docker container orchestration, making it easy to get the application running with a single command.

## Prerequisites

Before running the script, ensure you have:
- **Docker**: Container runtime environment
- **Docker Compose**: Multi-container orchestration tool
- **OpenSSL**: For generating secure keys (usually pre-installed on most systems)
- **Non-root user**: Script refuses to run as root for security reasons

## Usage

```bash
# For development environment
./setup.sh dev

# For production environment
./setup.sh prod
```

## What the Script Does

### 1. Environment Detection & Validation
- Checks that the script is not run as root (security best practice)
- Validates the environment argument (dev/prod)
- Verifies Docker and Docker Compose are installed

### 2. Environment Configuration (.env file)
If `.env` doesn't exist, the script:
- Copies `.env.dist` to `.env`
- Generates cryptographically secure random keys for:
  - `APP_SECRET` - Symfony application secret
  - `ENCRYPTION_KEY` - For encrypting integration credentials
  - `JWT_PASSPHRASE` - For JWT token signing
  - `MYSQL_ROOT_PASSWORD` - MariaDB root password
  - `MYSQL_PASSWORD` - Application database password
  - `MINIO_ROOT_PASSWORD` - MinIO S3 storage password

### 3. JWT Key Generation
Creates RSA key pair for API authentication:
- **Private Key**: `config/jwt/private.pem` (4096-bit RSA, AES256 encrypted)
- **Public Key**: `config/jwt/public.pem`
- Sets appropriate file permissions (600 for private, 644 for public)

### 4. Docker Container Management

#### Development Environment (`docker-compose.yml`)
```bash
docker-compose -f docker-compose.yml build  # Builds custom FrankenPHP image
docker-compose -f docker-compose.yml up -d  # Starts all containers
```

**Containers started:**
- `frankenphp` - PHP application server with hot-reload
- `mariadb` - Database server
- `redis` - Cache server
- `minio` - S3-compatible object storage

**Volumes created (Docker-managed):**
- `mariadb_data` - Database persistence
- `redis_data` - Cache persistence
- `caddy_data` - Web server data
- `caddy_config` - Web server configuration
- `minio_data` - Object storage

**Special Dev Feature:**
- Bind mount `./:/app` enables live code reloading

#### Production Environment (`docker-compose-prod.yml`)
```bash
# Creates external volumes for data persistence
docker volume create mariadb_data
docker volume create redis_data
docker volume create caddy_data
docker volume create caddy_config
docker volume create minio_data

# Updates .env for production
APP_ENV=prod
APP_DEBUG=false

# Uses production compose file
docker-compose -f docker-compose-prod.yml build
docker-compose -f docker-compose-prod.yml up -d
```

### 5. Application Setup

#### Development-Only Tasks
- **Composer Install**: Installs PHP dependencies with autoloader optimization
- **NPM Install**: Installs frontend dependencies
- **Asset Building**: Compiles frontend assets with Webpack (`npm run dev`)

#### Both Environments
- **Cache Operations**:
  - Clears cache to ensure new configurations load
  - Warms up cache for better performance

- **Database Schema**:
  - Runs `doctrine:schema:update --force`
  - Creates/updates tables based on Entity definitions
  - No migration files needed - entities are the source of truth

- **MinIO Setup**:
  - Creates default S3 bucket for file storage

- **File Permissions**:
  - Sets `www-data` ownership on `var/` directory
  - Sets 775 permissions for proper web server access

### 6. Service Readiness
- Waits 10 seconds for services to initialize
- Ensures database is accepting connections before schema updates

## Environment Differences

| Aspect | Development | Production |
|--------|------------|------------|
| **Compose File** | `docker-compose.yml` | `docker-compose-prod.yml` |
| **APP_ENV** | `dev` | `prod` |
| **APP_DEBUG** | `true` | `false` |
| **Volumes** | Local Docker volumes | External Docker volumes |
| **Code Mount** | Bind mount (`./:/app`) | Baked into image |
| **Dependencies** | Installed after container start | Installed during image build |
| **Assets** | Built with `npm run dev` | Built with `npm run build` |
| **Hot Reload** | Enabled via bind mount | Disabled |

## Generated Files & Directories

```
workoflow-integration-platform/
├── .env                      # Environment configuration (from .env.dist)
├── config/
│   └── jwt/
│       ├── private.pem       # RSA private key for JWT signing
│       └── public.pem        # RSA public key for JWT verification
└── var/                      # Symfony cache and logs (container only)
```

## Docker Volumes

The script creates several Docker volumes for data persistence:

| Volume | Purpose | Data Persisted |
|--------|---------|----------------|
| `mariadb_data` | Database storage | All application data, users, integrations |
| `redis_data` | Cache storage | Session data, application cache |
| `minio_data` | Object storage | Uploaded files, attachments |
| `caddy_data` | Web server data | SSL certificates, server state |
| `caddy_config` | Web server config | Caddy configuration |

## Port Mappings

After setup, services are available at:

| Service | Development | Production |
|---------|------------|------------|
| **Application (HTTP)** | http://localhost:3979 | http://localhost:3979 |
| **Application (HTTPS)** | https://localhost:443 | https://yourdomain.com |
| **MinIO Console** | http://localhost:9003 | Not exposed |
| **MariaDB** | localhost:3306 | Not exposed |
| **Redis** | localhost:6380 | Not exposed |

## Troubleshooting

### Common Issues

#### 1. Permission Denied
```bash
# Make script executable
chmod +x setup.sh
```

#### 2. Port Already in Use
```bash
# Check what's using the port
lsof -i :3979

# Stop conflicting service or change port in docker-compose.yml
```

#### 3. JWT Key Generation Fails
```bash
# Manually generate keys
JWT_PASS=$(grep JWT_PASSPHRASE .env | cut -d'=' -f2)
openssl genpkey -out config/jwt/private.pem -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -aes256 -pass pass:$JWT_PASS
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:$JWT_PASS
```

#### 4. Database Connection Issues
```bash
# Check database logs
docker-compose logs mariadb

# Verify credentials match .env
docker-compose exec mariadb mysql -u workoflow -p
```

#### 5. Clean Restart
```bash
# Stop everything
docker-compose down

# Remove volumes (WARNING: Deletes all data!)
docker-compose down -v

# Run setup again
./setup.sh dev
```

## Manual Operations

If you need to run individual setup steps:

```bash
# Just start containers (no setup)
docker-compose up -d

# Update database schema
docker-compose exec frankenphp php bin/console doctrine:schema:update --force

# Clear cache
docker-compose exec frankenphp php bin/console cache:clear

# Install dependencies
docker-compose exec frankenphp composer install
docker-compose exec frankenphp npm install

# Build assets
docker-compose exec frankenphp npm run dev    # Development
docker-compose exec frankenphp npm run build  # Production
```

## Security Considerations

1. **Generated Secrets**: All passwords and keys are cryptographically secure (32-byte random)
2. **Non-root Execution**: Script refuses root to prevent permission issues
3. **File Permissions**: JWT private key is restricted to 600 (owner read/write only)
4. **Production Isolation**: External volumes prevent data loss during container updates
5. **Environment Separation**: Dev uses `.env` directly, prod should use secrets management

## Next Steps

After successful setup:

1. **Configure Google OAuth**:
   - Add your `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` to `.env`
   - Update redirect URIs in Google Console

2. **Access the Application**:
   - Navigate to http://localhost:3979
   - Login with Google OAuth

3. **Development Workflow**:
   - Code changes are live-reloaded (no restart needed)
   - Database changes require schema update command
   - Asset changes require `npm run dev` or webpack watch

## Maintenance

```bash
# View logs
docker-compose logs -f frankenphp

# Enter container shell
docker-compose exec frankenphp bash

# Stop all services
docker-compose down

# Update and restart
git pull
./setup.sh dev
```