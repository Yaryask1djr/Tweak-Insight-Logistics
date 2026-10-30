#!/usr/bin/env bash
# ==============================================================================
# Tweak Insight Logistics - Zero-Downtime Atomic Release Deployer
# Usage: sudo bash deploy/scripts/deploy-release.sh
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

APP_DIR="${APP_DIR:-/var/www/tweak-insight-logistics}"
WWW_USER="${WWW_USER:-www-data}"
WWW_GROUP="${WWW_GROUP:-www-data}"

echo -e "${BLUE}====================================================================${NC}"
echo -e "${BLUE}   Tweak Insight Logistics - Executing Zero-Downtime Deployment     ${NC}"
echo -e "${BLUE}====================================================================${NC}"

cd "$APP_DIR"

# 1. Update source code if inside git worktree
if [ -d .git ]; then
  echo -e "\n${YELLOW}>>> Step 1: Pulling latest commits from Git...${NC}"
  git fetch --all --prune
  git reset --hard HEAD
  git pull origin main || git pull
fi

# 2. Install backend dependencies
echo -e "\n${YELLOW}>>> Step 2: Optimizing Composer Autoloader & Dependencies...${NC}"
cd "$APP_DIR/backend"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 3. Execute database migrations
echo -e "\n${YELLOW}>>> Step 3: Running Database Schema Migrations...${NC}"
php scripts/migrate_operational_architecture.php
php scripts/migrate_atomic_booking.php
php scripts/migrate_delivery_payments.php
php scripts/migrate_spatial_capabilities.php
php scripts/migrate_performance_indexes.php

# 4. Compile frontend and prepare immutable release
echo -e "\n${YELLOW}>>> Step 4: Compiling Frontend Production Bundle & Immutable Assets...${NC}"
cd "$APP_DIR/frontend"
npm ci --legacy-peer-deps || npm install

PREPARE_OUTPUT=$(node scripts/deploy-backend-build.js --prepare)
echo "$PREPARE_OUTPUT"

# Extract release ID (Format: YYYYMMDDHHMMSS-12hex)
RELEASE_ID=$(echo "$PREPARE_OUTPUT" | grep -oE '[0-9]{14}-[a-f0-9]{12}' | head -n 1)

if [ -z "$RELEASE_ID" ]; then
  echo -e "${RED}[-] Error: Failed to extract new Release ID from build output.${NC}"
  exit 1
fi

echo -e "\n${YELLOW}>>> Step 5: Atomically Activating Release [${RELEASE_ID}]...${NC}"
node scripts/deploy-backend-build.js --activate="$RELEASE_ID"

echo -e "\n${YELLOW}>>> Step 6: Pruning Stale Releases (>30 days old)...${NC}"
node scripts/deploy-backend-build.js --prune || true

# 5. Fix permissions
echo -e "\n${YELLOW}>>> Step 7: Verifying Storage & Public Permissions...${NC}"
cd "$APP_DIR"
chown -R "$WWW_USER:$WWW_GROUP" "$APP_DIR/backend/storage" "$APP_DIR/backend/public"
chmod -R 775 "$APP_DIR/backend/storage"

# 6. Reload PHP-FPM (clears OPcache without dropping connections)
echo -e "\n${YELLOW}>>> Step 8: Reloading PHP-FPM & Restarting Queue Workers...${NC}"
if systemctl is-active --quiet php8.2-fpm; then
  systemctl reload php8.2-fpm
fi

if systemctl is-active --quiet til-queue-worker.service; then
  systemctl restart til-queue-worker.service
fi

# 7. Verification probe
echo -e "\n${YELLOW}>>> Step 9: Running Health Check Probe...${NC}"
HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/api/health 2>/dev/null || echo "000")
if [ "$HEALTH_STATUS" = "200" ] || [ "$HEALTH_STATUS" = "301" ] || [ "$HEALTH_STATUS" = "302" ]; then
  echo -e "${GREEN}[✓] Health probe passed! HTTP Status: ${HEALTH_STATUS}${NC}"
else
  echo -e "${YELLOW}[!] Warning: Health probe returned HTTP ${HEALTH_STATUS}. Check logs.${NC}"
fi

echo -e "\n${GREEN}====================================================================${NC}"
echo -e "${GREEN}   Deployment Successfully Completed: Release ${RELEASE_ID} is LIVE! ${NC}"
echo -e "${GREEN}====================================================================${NC}"
