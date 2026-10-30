#!/usr/bin/env bash
# ==============================================================================
# Tweak Insight Logistics - Production Deployment Verification Suite
# Usage: sudo bash deploy/scripts/verify-deployment.sh
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

APP_DIR="${APP_DIR:-/var/www/tweak-insight-logistics}"
FAILURES=0

echo -e "${BLUE}====================================================================${NC}"
echo -e "${BLUE}   Tweak Insight Logistics - Production Health & Readiness Audit     ${NC}"
echo -e "${BLUE}====================================================================${NC}"

check_service() {
  local service_name="$1"
  local display_name="$2"
  if systemctl is-active --quiet "$service_name" 2>/dev/null; then
    echo -e " [${GREEN}PASS${NC}] Service: ${display_name} is RUNNING"
  else
    echo -e " [${RED}FAIL${NC}] Service: ${display_name} is INACTIVE / FAILED"
    FAILURES=$((FAILURES + 1))
  fi
}

echo -e "\n${YELLOW}>>> Checking System Services...${NC}"
check_service "nginx" "Nginx Web Server"
check_service "php8.2-fpm" "PHP 8.2-FPM Process Manager"
check_service "mysql" "MySQL 8 Database Server"
check_service "redis-server" "Redis In-Memory Broker"
check_service "til-queue-worker.service" "Background Queue Worker"
check_service "til-maintenance.timer" "Nightly Maintenance Timer"

echo -e "\n${YELLOW}>>> Checking Storage Permissions & Structure...${NC}"
STORAGE_DIR="$APP_DIR/backend/storage"
if [ -d "$STORAGE_DIR" ]; then
  for sub in logs cache exports rate_limits client-kyc driver-documents; do
    if [ -d "$STORAGE_DIR/$sub" ]; then
      echo -e " [${GREEN}PASS${NC}] Directory: storage/$sub exists"
    else
      echo -e " [${YELLOW}WARN${NC}] Directory: storage/$sub missing (creating now...)"
      mkdir -p "$STORAGE_DIR/$sub"
    fi
  done
  if [ -w "$STORAGE_DIR" ]; then
    echo -e " [${GREEN}PASS${NC}] Storage directory is writable"
  else
    echo -e " [${RED}FAIL${NC}] Storage directory is NOT writable by current user"
    FAILURES=$((FAILURES + 1))
  fi
else
  echo -e " [${RED}FAIL${NC}] Storage directory missing at $STORAGE_DIR"
  FAILURES=$((FAILURES + 1))
fi

echo -e "\n${YELLOW}>>> Checking Active Frontend Release Pointer...${NC}"
RELEASE_POINTER="$APP_DIR/backend/public/.active-release.json"
if [ -f "$RELEASE_POINTER" ]; then
  ACTIVE_ID=$(grep -oE '"current":"[^"]+"' "$RELEASE_POINTER" | cut -d':' -f2 | tr -d '"')
  echo -e " [${GREEN}PASS${NC}] Active Frontend Release: ${ACTIVE_ID}"
  if [ -d "$APP_DIR/backend/public/releases/$ACTIVE_ID" ]; then
    echo -e " [${GREEN}PASS${NC}] Release directory verified on disk"
  else
    echo -e " [${RED}FAIL${NC}] Release directory missing from releases folder"
    FAILURES=$((FAILURES + 1))
  fi
else
  echo -e " [${YELLOW}WARN${NC}] No .active-release.json found; fallback to legacy index.html"
fi

echo -e "\n${YELLOW}>>> Probing API Endpoints...${NC}"
if curl -s -f http://127.0.0.1/api/health >/dev/null 2>&1; then
  echo -e " [${GREEN}PASS${NC}] GET /api/health returned 200 OK"
else
  echo -e " [${YELLOW}WARN${NC}] GET /api/health failed or server redirected to HTTPS"
fi

echo -e "\n${BLUE}====================================================================${NC}"
if [ $FAILURES -eq 0 ]; then
  echo -e "${GREEN}   ALL DIAGNOSTIC CHECKS PASSED! SYSTEM IS PRODUCTION-READY.        ${NC}"
else
  echo -e "${RED}   ${FAILURES} CHECKS FAILED OR REQUIRE ATTENTION.                            ${NC}"
fi
echo -e "${BLUE}====================================================================${NC}"
