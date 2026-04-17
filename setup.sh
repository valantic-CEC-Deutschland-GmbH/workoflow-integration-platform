#!/bin/bash

# Workoflow Integration Platform Setup Script
# This script sets up the application on a production server

set -e

echo "========================================="
echo "Workoflow Integration Platform Setup"
echo "========================================="

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   echo "This script should not be run as root for security reasons."
   echo "Please run as a regular user with sudo privileges."
   exit 1
fi

# Detect environment
if [ -z "$1" ]; then
    echo "Usage: ./setup.sh [dev|prod]"
    exit 1
fi

ENVIRONMENT=$1
echo "Setting up for environment: $ENVIRONMENT"

# Check dependencies
echo ""
echo "Checking dependencies..."
command -v docker >/dev/null 2>&1 || { echo "Docker is required but not installed. Aborting." >&2; exit 1; }

if docker compose version >/dev/null 2>&1; then
    COMPOSE_CMD=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD=(docker-compose)
else
    echo "Docker Compose is required but not installed. Aborting." >&2
    exit 1
fi

# Create .env file if not exists
if [ ! -f .env ]; then
    echo ""
    echo "Creating .env file from .env.dist..."
    cp .env.dist .env
    
    # Generate random keys
    APP_SECRET=$(openssl rand -hex 16)
    ENCRYPTION_KEY=$(openssl rand -hex 16)
    JWT_PASSPHRASE=$(openssl rand -hex 16)
    MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)
    MYSQL_PASSWORD=$(openssl rand -hex 16)
    MINIO_ROOT_PASSWORD=$(openssl rand -hex 16)
    
    # Update .env with generated values
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        sed -i '' "s/your_app_secret_here/$APP_SECRET/" .env
        sed -i '' "s/your_32_char_encryption_key_here/$ENCRYPTION_KEY/" .env
        sed -i '' "s/your_jwt_passphrase/$JWT_PASSPHRASE/" .env
        sed -i '' "s/your_root_password/$MYSQL_ROOT_PASSWORD/" .env
        sed -i '' "s/your_mysql_password/$MYSQL_PASSWORD/" .env
        sed -i '' "s/workoflow123/$MINIO_ROOT_PASSWORD/" .env
    else
        # Linux
        sed -i "s/your_app_secret_here/$APP_SECRET/" .env
        sed -i "s/your_32_char_encryption_key_here/$ENCRYPTION_KEY/" .env
        sed -i "s/your_jwt_passphrase/$JWT_PASSPHRASE/" .env
        sed -i "s/your_root_password/$MYSQL_ROOT_PASSWORD/" .env
        sed -i "s/your_mysql_password/$MYSQL_PASSWORD/" .env
        sed -i "s/workoflow123/$MINIO_ROOT_PASSWORD/" .env
    fi
    
    echo "Generated secure random keys in .env"
else
    echo ".env file already exists, skipping..."
fi

# Production specific setup
if [ "$ENVIRONMENT" == "prod" ]; then
    echo ""
    echo "Setting up production environment..."
    
    # Update .env for production
    sed -i "s/APP_ENV=dev/APP_ENV=prod/" .env
    sed -i "s/APP_DEBUG=true/APP_DEBUG=false/" .env
    
    # Create external volumes
    echo "Creating external Docker volumes..."
    docker volume create mariadb_data || true
    docker volume create redis_data || true
    docker volume create caddy_data || true
    docker volume create caddy_config || true
    docker volume create minio_data || true
    
    COMPOSE_FILE="docker-compose-prod.yml"
else
    COMPOSE_FILE="docker-compose.yml"
fi

# Generate JWT keys
echo ""
echo "Generating JWT keys..."
mkdir -p config/jwt
if [ ! -f config/jwt/private.pem ]; then
    # Extract JWT passphrase with proper trimming
    JWT_PASS=$(grep "^JWT_PASSPHRASE=" .env | cut -d '=' -f2- | tr -d ' ' | tr -d '"' | tr -d "'")
    
    if [ -z "$JWT_PASS" ]; then
        echo "ERROR: JWT_PASSPHRASE not found in .env file"
        exit 1
    fi
    
    echo "Generating private key..."
    openssl genpkey -out config/jwt/private.pem -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -aes256 -pass "pass:${JWT_PASS}" 2>&1
    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to generate private key"
        exit 1
    fi
    
    echo "Generating public key..."
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin "pass:${JWT_PASS}" 2>&1
    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to generate public key"
        exit 1
    fi
    
    chmod 644 config/jwt/public.pem
    chmod 600 config/jwt/private.pem
    echo "JWT keys generated successfully"
else
    echo "JWT keys already exist, skipping..."
fi

# Build and start containers
echo ""
echo "Building and starting Docker containers..."
if [ "$ENVIRONMENT" != "dev" ]; then
    # Force clean build in production/stage to ensure all assets are properly built
    "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" build
else
    "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" build
fi
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" up -d

# Wait for services to be ready
echo ""
echo "Waiting for services to be ready..."
sleep 10

# Clear cache first to ensure new configurations are loaded
echo ""
echo "Clearing cache to load new configurations..."
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp php bin/console cache:clear --env="$ENVIRONMENT" || true

# Install dependencies (dev only - prod installs during Docker build)
if [ "$ENVIRONMENT" != "prod" ]; then
    echo ""
    echo "Installing PHP dependencies..."
    "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp composer install --optimize-autoloader
fi

# Install npm dependencies and build assets
if [ "$ENVIRONMENT" == "dev" ]; then
    # Dev: Install dependencies and build for development
    echo ""
    echo "Installing npm dependencies..."
    "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp npm install

    echo ""
    echo "Building frontend assets..."
    "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp npm run dev
else
    # Production/Stage: Verify assets were built during Docker image creation
    echo ""
    echo "Verifying frontend assets..."
    if ! "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp test -f /app/public/build/entrypoints.json; then
        echo "Assets not found, building them now..."
        "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp npm install
        "${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp npm run build
    else
        echo "Frontend assets verified successfully"
    fi
fi

# Update database schema from entities
echo ""
echo "Updating database schema from entity definitions..."
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp php bin/console doctrine:schema:update --force

# Clear and warm up cache
echo ""
echo "Clearing and warming up cache..."
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp php bin/console cache:clear --env="$ENVIRONMENT"
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp php bin/console cache:warmup --env="$ENVIRONMENT"

# Create MinIO bucket
echo ""
echo "Setting up MinIO bucket..."
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp php bin/console app:create-bucket || true

# Set proper permissions
echo ""
echo "Setting file permissions..."
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp chown -R www-data:www-data var/
"${COMPOSE_CMD[@]}" -f "$COMPOSE_FILE" exec -T frankenphp chmod -R 775 var/

echo ""
echo "========================================="
echo "Setup completed successfully!"
echo ""
echo "Application is running at:"
echo "- HTTP: http://localhost:3979"
echo "- HTTPS: https://localhost"
echo ""
echo "Other services:"
echo "- MinIO Console: http://localhost:9001"
echo ""
if [ "$ENVIRONMENT" == "dev" ]; then
    echo "Development credentials:"
    echo "- MCP Auth: workoflow / workoflow"
    echo ""
fi
echo "IMPORTANT: Please configure your Google OAuth credentials in .env"
echo "========================================="
