# ==============================================================================
# Stage 1: Build the React frontend SPA
# ==============================================================================
FROM node:20-alpine AS frontend-builder
WORKDIR /app/frontend

COPY frontend/package*.json ./
RUN npm ci

COPY frontend/ ./
ENV CI=false
ENV REACT_APP_API_BASE_URL=/api
RUN npm run build

# ==============================================================================
# Stage 2: Production FrankenPHP (Caddy + PHP 8.3)
# ==============================================================================
FROM dunglas/frankenphp:latest

# Install required PHP extensions for Tweak Insight Logistics
RUN install-php-extensions pdo_mysql bcmath opcache redis intl zip

WORKDIR /app

# Copy built frontend to /app/public
COPY --from=frontend-builder /app/frontend/build /app/public

# Copy backend files to /app/backend
COPY backend /app/backend

# Install PHP dependencies via Composer
RUN cd /app/backend && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader || composer install --no-dev --no-interaction

# Create PHP entrypoint in /app/public
RUN echo "<?php require_once '/app/backend/api/index.php';" > /app/public/index.php

# Copy Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Ensure storage directories exist and have open permissions
RUN mkdir -p /tmp/storage/logs /tmp/storage/cache /tmp/storage/uploads /tmp/storage/client-kyc /tmp/storage/driver-documents && \
    chmod -R 777 /tmp/storage

ENV PORT=80
ENV STORAGE_PATH=/tmp/storage
ENV QUEUE_DRIVER=mysql
ENV APP_ENV=production

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
