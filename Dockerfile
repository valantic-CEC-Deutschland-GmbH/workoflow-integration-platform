# Build stage
FROM dunglas/frankenphp:php8.5 AS builder

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libzip-dev \
    zip \
    unzip \
    nodejs \
    npm

# Install PHP extensions (including zip for PHPOffice libraries)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock symfony.lock ./

# Install PHP dependencies
RUN composer install --optimize-autoloader --no-scripts

# Copy package files for npm
COPY package.json package-lock.json webpack.config.js ./

# Install npm dependencies
RUN npm ci --no-audit

# Copy rest of application
COPY . .

# Build assets
RUN npm run build

# Final stage
FROM dunglas/frankenphp:php8.5

# Install runtime dependencies only
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libzip-dev \
    git \
    zip \
    unzip \
    nodejs \
    npm \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (including zip for PHPOffice libraries)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl zip

# Install Composer in final stage for runtime operations
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Fix git ownership warning
RUN git config --global --add safe.directory /app

# Copy application from builder
COPY --from=builder --chown=www-data:www-data /app /app

# Remove unnecessary files
RUN rm -rf node_modules .git .env.* docker* tests

EXPOSE 80 443
