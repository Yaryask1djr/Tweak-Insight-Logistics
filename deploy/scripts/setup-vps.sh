#!/usr/bin/env bash
# ==============================================================================
# Tweak Insight Logistics - Automated Cloud VPS Setup Script
# Target OS: Ubuntu 22.04 LTS / Debian 12 (x86_64 or ARM64)
# Run as: sudo bash deploy/scripts/setup-vps.sh
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}====================================================================${NC}"
echo -e "${BLUE}   Tweak Insight Logistics - Production Cloud VPS Provisioner       ${NC}"
echo -e "${BLUE}====================================================================${NC}"

if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[-] Error: This script must be run as root (or with sudo).${NC}"
  exit 1
fi

APP_DIR="${APP_DIR:-/var/www/tweak-insight-logistics}"
WWW_USER="${WWW_USER:-www-data}"
WWW_GROUP="${WWW_GROUP:-www-data}"
DOMAIN="${DOMAIN:-logistics.tweakinsight.com}"

echo -e "\n${YELLOW}>>> Step 1: Updating System Repositories...${NC}"
apt-get update -y
apt-get install -y software-properties-common curl git ufw fail2ban unzip zip tar wget

echo -e "\n${YELLOW}>>> Step 2: Adding Ondřej Surý PHP PPA & NodeSource Node 20...${NC}"
if ! grep -q "^deb .*ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null; then
  add-apt-repository -y ppa:ondrej/php
fi

if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
fi

apt-get update -y

echo -e "\n${YELLOW}>>> Step 3: Installing Nginx, PHP 8.2-FPM & Required Extensions...${NC}"
apt-get install -y \
  nginx \
  certbot \
  python3-certbot-nginx \
  nodejs \
  redis-server \
  mysql-server \
  php8.2-fpm \
  php8.2-cli \
  php8.2-mysql \
  php8.2-redis \
  php8.2-bcmath \
  php8.2-opcache \
  php8.2-intl \
  php8.2-zip \
  php8.2-gd \
  php8.2-curl \
  php8.2-xml \
  php8.2-mbstring

echo -e "\n${YELLOW}>>> Step 4: Installing Composer...${NC}"
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo -e "\n${YELLOW}>>> Step 5: Configuring PHP 8.2-FPM & OPcache for High Concurrency...${NC}"
PHP_INI="/etc/php/8.2/fpm/php.ini"
PHP_FPM_POOL="/etc/php/8.2/fpm/pool.d/www.conf"

sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 25M/' "$PHP_INI"
sed -i 's/^post_max_size = .*/post_max_size = 25M/' "$PHP_INI"
sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
sed -i 's/^max_execution_time = .*/max_execution_time = 60/' "$PHP_INI"
sed -i 's/^expose_php = .*/expose_php = Off/' "$PHP_INI"

# OPcache settings
cat > /etc/php/8.2/fpm/conf.d/99-til-opcache.ini << 'EOF'
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.max_wasted_percentage=5
opcache.validate_timestamps=0
opcache.revalidate_freq=0
opcache.save_comments=1
opcache.fast_shutdown=1
EOF

systemctl restart php8.2-fpm
systemctl enable php8.2-fpm

echo -e "\n${YELLOW}>>> Step 6: Configuring Redis Broker & Cache...${NC}"
systemctl enable redis-server
systemctl restart redis-server

echo -e "\n${YELLOW}>>> Step 7: Configuring UFW Firewall...${NC}"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo -e "\n${YELLOW}>>> Step 8: Preparing Application Directory Structure...${NC}"
mkdir -p "$APP_DIR"
mkdir -p "$APP_DIR/backend/storage/logs" \
         "$APP_DIR/backend/storage/cache" \
         "$APP_DIR/backend/storage/exports" \
         "$APP_DIR/backend/storage/rate_limits" \
         "$APP_DIR/backend/storage/client-kyc" \
         "$APP_DIR/backend/storage/driver-documents" \
         "/var/www/certbot" \
         "/var/backups/til"

chown -R "$WWW_USER:$WWW_GROUP" "$APP_DIR/backend/storage" "/var/backups/til"
chmod -R 775 "$APP_DIR/backend/storage"

echo -e "\n${YELLOW}>>> Step 9: Linking Nginx Configuration...${NC}"
if [ -f "$APP_DIR/deploy/nginx/tweak-insight.conf" ]; then
  cp "$APP_DIR/deploy/nginx/tweak-insight.conf" /etc/nginx/sites-available/tweak-insight.conf
elif [ -f "$APP_DIR/backend/deploy/nginx/til.conf" ]; then
  cp "$APP_DIR/backend/deploy/nginx/til.conf" /etc/nginx/sites-available/tweak-insight.conf
fi

if [ -f /etc/nginx/sites-available/tweak-insight.conf ]; then
  ln -sf /etc/nginx/sites-available/tweak-insight.conf /etc/nginx/sites-enabled/
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx || echo "Nginx reload pending SSL certificate setup."
fi

echo -e "\n${YELLOW}>>> Step 10: Setting Up Systemd Background Daemons...${NC}"
if [ -f "$APP_DIR/backend/deploy/setup_daemon.sh" ]; then
  bash "$APP_DIR/backend/deploy/setup_daemon.sh" systemd || true
fi

echo -e "\n${GREEN}====================================================================${NC}"
echo -e "${GREEN}   Tweak Insight Logistics VPS Environment Provisioned!            ${NC}"
echo -e "${GREEN}====================================================================${NC}"
echo -e "Next steps to complete deployment:"
echo -e " 1. Configure ${BLUE}$APP_DIR/backend/.env${NC} with production database & keys."
echo -e " 2. Obtain Let's Encrypt SSL certificate:"
echo -e "    ${YELLOW}sudo certbot --nginx -d $DOMAIN -d www.$DOMAIN${NC}"
echo -e " 3. Run initial release deployment script:"
echo -e "    ${YELLOW}sudo bash $APP_DIR/deploy/scripts/deploy-release.sh${NC}"
echo -e " 4. Run verification diagnostics:"
echo -e "    ${YELLOW}sudo bash $APP_DIR/deploy/scripts/verify-deployment.sh${NC}"
