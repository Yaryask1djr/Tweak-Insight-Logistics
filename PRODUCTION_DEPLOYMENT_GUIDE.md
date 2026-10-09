# Production Deployment & Hardening Guide: Tweak Insight Logistics

This guide details the exact steps for deploying Tweak Insight Logistics to production (e.g. on Ubuntu/Debian Linux VPS) with SSL/TLS, Nginx, and systemd daemons.

---

## 1. Directory Structure on Production Server
Deploy the repository into `/var/www/tweak-insight-logistics`:
```bash
sudo git clone https://github.com/Yaryask1djr/Tweak-Insight-Logistics.git /var/www/tweak-insight-logistics
cd /var/www/tweak-insight-logistics
```

Set appropriate ownership:
```bash
sudo chown -R www-data:www-data /var/www/tweak-insight-logistics/backend/storage
sudo chmod -R 750 /var/www/tweak-insight-logistics/backend/storage
```

---

## 2. Environment Configuration (`backend/.env`)
Create the production environment file at `/var/www/tweak-insight-logistics/backend/.env`:

```env
# Application Settings
APP_NAME="Tweak Insight Logistics"
APP_ENV=production
APP_DEBUG=false
BASE_URL=https://logistics.tweakinsight.com

# Server Configuration
SERVER_PORT=8000
ALLOWED_ORIGINS=https://logistics.tweakinsight.com

# Production Database (MySQL 8)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=tweak_insight_logistics_prod
DB_USER=til_app
DB_PASS=YOUR_STRONG_DATABASE_PASSWORD_HERE

# Cryptographic Keys (Generate random 64-character hex strings)
JWT_SECRET=YOUR_64_CHAR_HEX_JWT_SECRET_HERE

# Storage
STORAGE_PATH=storage

# High-Concurrency Queue (Redis)
QUEUE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# External SMS & WhatsApp Gateway (Termii for Nigeria / Twilio international)
SMS_PROVIDER=termii
WHATSAPP_PROVIDER=termii
TERMII_API_KEY=YOUR_LIVE_TERMII_API_KEY_HERE
TERMII_SENDER_ID=TweakLog

# Payment Gateway (Paystack Nigeria)
PAYSTACK_SECRET_KEY=sk_live_...
PAYSTACK_PUBLIC_KEY=pk_live_...
```

> **Security Note**: When `APP_ENV=production`, `SecurityConfig::productionEnvironmentError()` automatically blocks server boot if `APP_DEBUG=true` or if weak credentials are used.

---

## 3. Web Server (Nginx) & SSL Certificate Setup

### 3.1 Install Nginx & Certbot
```bash
sudo apt update
sudo apt install -y nginx certbot python3-certbot-nginx php8.2-fpm php8.2-mysql php8.2-redis redis-server
```

### 3.2 Obtain Free SSL/TLS Certificate (Let's Encrypt)
```bash
sudo certbot certonly --webroot -w /var/www/certbot -d logistics.tweakinsight.com -d www.logistics.tweakinsight.com
```

### 3.3 Link Production Nginx Configuration
```bash
sudo cp /var/www/tweak-insight-logistics/backend/deploy/nginx/til.conf /etc/nginx/sites-available/til.conf
sudo ln -s /etc/nginx/sites-available/til.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

---

## 4. Background Daemons (Queue Worker & Maintenance)

Install the systemd worker service and maintenance timer:
```bash
sudo bash /var/www/tweak-insight-logistics/backend/deploy/setup_daemon.sh systemd
```

### Verifying Daemons
```bash
# Check queue worker status
sudo systemctl status til-queue-worker.service

# Check nightly maintenance timer
sudo systemctl status til-maintenance.timer
```

---

## 5. Security & Verification Checks

Run the automated verification suite before opening public traffic:
```bash
cd /var/www/tweak-insight-logistics
php backend/tests/verify_credential_configuration.php
php backend/tests/verify_cors_policy.php
php backend/tests/verify_authorization_policy.php
php backend/tests/verify_external_notification_gateway.php
```

All requests will now be secured with:
* `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
* `X-Frame-Options: DENY` (Clickjacking prevention)
* `Cross-Origin-Opener-Policy: same-origin` (COOP)
* `Cross-Origin-Resource-Policy: same-origin` (CORP)
* Content Security Policy with trusted OpenStreetMap geocoding
