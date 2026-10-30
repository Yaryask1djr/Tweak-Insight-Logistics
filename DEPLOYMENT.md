# Tweak Insight Logistics — Production Deployment & Operations Runbook

This comprehensive guide covers all standardized deployment architectures for **Tweak Insight Logistics**:
* **Option A:** [Docker Compose Containerization](#option-a-docker-compose-deployment) (Fastest for cloud container hosts, staging, and local replication)
* **Option B:** [Production Cloud VPS / Bare Metal](#option-b-production-cloud-vps-deployment-ubuntu-2204-lts) (Ubuntu 22.04 LTS / Debian 12 with Nginx, PHP 8.2-FPM, MySQL 8, Redis)
* **Option C:** [Traditional LAMP Stack](#option-c-traditional-lamp-stack-apache-24) (Apache 2.4 with `mod_proxy_fcgi` and `.htaccess`)
* **Zero-Downtime Releases:** [Atomic Deployment Pipeline](#zero-downtime-atomic-release-pipeline)
* **Backups & Disaster Recovery:** [Database Backup & Restoration](#database-management--automated-backups)
* **Security Hardening:** [Production Security Checklist](#production-security--hardening-checklist)

---

## System Architecture

```
                       [ Internet Users / Drivers / Clients ]
                                         │
                                         ▼ HTTPS (443)
                        ┌─────────────────────────────────┐
                        │   Nginx / Apache Reverse Proxy  │
                        │   (SSL/TLS 1.3, Rate Limiting,  │
                        │    CSP, Security Headers, Gzip) │
                        └──────────────┬──────────────────┘
                                       │
            ┌──────────────────────────┴──────────────────────────┐
            ▼                                                     ▼
┌───────────────────────────────┐                     ┌───────────────────────────────┐
│     Frontend Web Assets       │                     │       PHP 8.2-FPM API         │
│  (React 18 SPA, Immutable     │                     │ (Strict Types, JWT Auth,      │
│   Release Pointer /releases/) │                     │  REST Controllers, Geocoding) │
└───────────────────────────────┘                     └──────────────┬────────────────┘
                                                                     │
                                     ┌───────────────────────────────┼──────────────────────────────┐
                                     ▼                               ▼                              ▼
                      ┌────────────────────────────┐  ┌────────────────────────────┐  ┌────────────────────────────┐
                      │      MySQL 8.0 Server      │  │       Redis 7 Broker       │  │   Background Daemon        │
                      │ (Spatial Points, Geo Dist, │  │ (Rate Limits, Telemetry    │  │ (Queue Worker: SMS/Email/  │
                      │  ACID Delivery Bookings)   │  │  Sliding Buffer, Queues)   │  │  Push & Order Processing)  │
                      └────────────────────────────┘  └────────────────────────────┘  └────────────────────────────┘
```

---

## Option A: Docker Compose Deployment

The containerized stack orchestrates 5 isolated services:
1. **`backend`**: Hardened PHP 8.2-FPM Alpine container with OPcache, BCMath, MySQL, Redis, and PCNTL.
2. **`worker`**: Background queue worker container handling asynchronous notifications and telemetry.
3. **`web`**: Production Nginx container serving optimized static assets and routing API requests.
4. **`db`**: MySQL 8.0 container pre-configured with UTF-8 (`utf8mb4_unicode_ci`) and persistent volumes.
5. **`redis`**: Redis 7 Alpine container for high-throughput rate limiting and message broker queues.

### 1. Quickstart (Staging / Development)

```bash
# 1. Clone repository
git clone https://github.com/Yaryask1djr/Tweak-Insight-Logistics.git
cd Tweak-Insight-Logistics

# 2. Configure environment file
cp docker/.env.docker.example .env

# 3. Build and launch all containers in detached mode
docker compose up -d --build

# 4. Check status and healthchecks
docker compose ps
```

The system will now be live:
* **Web & API:** [http://localhost](http://localhost) (or port configured in `PORT`)
* **Health Check:** `http://localhost/api/health`

### 2. Production Docker Launch with Resource Limits & SSL

For production environments, layer the `docker-compose.prod.yml` configuration:

```bash
# Start with production overrides (restart policies, logging limits, certbot)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### 3. Container Management Commands

```bash
# View aggregated live logs
docker compose logs -f

# View backend API logs
docker compose logs -f backend

# View queue worker logs
docker compose logs -f worker

# Execute bash inside backend container
docker compose exec backend bash

# Run manual database migration
docker compose exec backend php scripts/migrate_operational_architecture.php

# Stop stack gracefully
docker compose down
```

---

## Option B: Production Cloud VPS Deployment (Ubuntu 22.04 LTS)

Recommended for AWS EC2, DigitalOcean Droplets, Linode, Hetzner, or Vultr servers.

### 1. Automated Provisioning (One Command)

We have provided an automated provisioning script in `deploy/scripts/setup-vps.sh` that installs and configures:
* Nginx Web Server
* PHP 8.2-FPM with all extensions and OPcache optimization
* MySQL 8.0 Server
* Redis Server
* Node.js 20 LTS & Composer
* UFW Firewall & Fail2Ban
* Systemd Queue Worker & Maintenance Timers

```bash
# Run on clean Ubuntu 22.04 LTS as root:
sudo bash deploy/scripts/setup-vps.sh
```

---

### 2. Manual Step-by-Step VPS Setup

If you prefer to configure the server manually:

#### Step 2.1: Update & Install Core Dependencies
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git ufw fail2ban unzip zip tar nginx certbot python3-certbot-nginx

# Add Ondřej Surý PHP PPA
sudo add-apt-repository -y ppa:ondrej/php

# Add NodeSource Node 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -

sudo apt update
sudo apt install -y \
  php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-bcmath \
  php8.2-opcache php8.2-intl php8.2-zip php8.2-gd php8.2-curl \
  php8.2-xml php8.2-mbstring mysql-server redis-server nodejs
```

#### Step 2.2: Install Composer
```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
```

#### Step 2.3: Clone Application & Configure Directories
```bash
sudo mkdir -p /var/www/tweak-insight-logistics
sudo git clone https://github.com/Yaryask1djr/Tweak-Insight-Logistics.git /var/www/tweak-insight-logistics
cd /var/www/tweak-insight-logistics

# Create private storage subdirectories
sudo mkdir -p backend/storage/{logs,cache,exports,rate_limits,client-kyc,driver-documents}
sudo chown -R www-data:www-data backend/storage backend/public
sudo chmod -R 775 backend/storage
```

#### Step 2.4: Configure Production Database
```bash
sudo mysql -u root
```
```sql
CREATE DATABASE tweak_insight_logistics_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'til_app'@'127.0.0.1' IDENTIFIED BY 'YOUR_STRONG_PASSWORD_HERE';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER, REFERENCES ON tweak_insight_logistics_prod.* TO 'til_app'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

#### Step 2.5: Configure Production Environment (`backend/.env`)
```bash
sudo cp backend/.env.example backend/.env
sudo nano backend/.env
```
Ensure the following keys are set:
* `APP_ENV=production`
* `APP_DEBUG=false`
* `BASE_URL=https://logistics.tweakinsight.com`
* `DB_HOST=127.0.0.1`
* `DB_NAME=tweak_insight_logistics_prod`
* `DB_USER=til_app`
* `DB_PASS=YOUR_STRONG_PASSWORD_HERE`
* `JWT_SECRET=YOUR_64_CHAR_HEX_KEY` (Generate with `openssl rand -hex 32`)
* `REDIS_HOST=127.0.0.1`

#### Step 2.6: Configure Let's Encrypt SSL & Nginx
```bash
# 1. Copy production Nginx server block
sudo cp deploy/nginx/tweak-insight.conf /etc/nginx/sites-available/tweak-insight.conf
sudo ln -sf /etc/nginx/sites-available/tweak-insight.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

# 2. Obtain SSL Certificate
sudo certbot --nginx -d logistics.tweakinsight.com -d www.logistics.tweakinsight.com

# 3. Test and reload Nginx
sudo nginx -t
sudo systemctl reload nginx
```

#### Step 2.7: Install Systemd Queue Worker & Maintenance Timer
```bash
sudo bash backend/deploy/setup_daemon.sh systemd
```

Verify services are active:
```bash
sudo systemctl status til-queue-worker.service
sudo systemctl status til-maintenance.timer
```

---

## Option C: Traditional LAMP Stack (Apache 2.4)

For hosting environments running Apache 2.4 (e.g., cPanel, Dedicated LAMP):

### 1. Enable Required Apache Modules
```bash
sudo a2enmod proxy proxy_fcgi ssl rewrite headers deflate
```

### 2. Install VirtualHost Configuration
```bash
sudo cp deploy/apache/tweak-insight.conf /etc/apache2/sites-available/tweak-insight.conf
sudo a2ensite tweak-insight.conf
sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### 3. Key Apache `.htaccess` Protection
The committed `backend/public/.htaccess` includes rules that:
1. Deny direct access to dotfiles (`.env`, `.git`)
2. Block direct HTTP requests to `/storage/` and KYC uploads
3. Route all API requests (`/api/`, `/auth/`, `/deliveries/`) to `api.php`
4. Forward SPA requests to `index.php` to load the active immutable release

---

## Zero-Downtime Atomic Release Pipeline

Tweak Insight Logistics utilizes an immutable atomic release architecture. Instead of overwriting files in-place during user activity, releases are compiled into timestamped directories (`backend/public/releases/{release-id}/`), verified for cryptographic integrity, and activated via an atomic symlink/pointer switch (`.active-release.json`).

### Executing a Deployment
Run the automated release script:
```bash
sudo bash deploy/scripts/deploy-release.sh
```

What `deploy-release.sh` does automatically:
1. Pulls latest Git commits.
2. Runs `composer install --no-dev --optimize-autoloader`.
3. Executes any pending database migrations.
4. Compiles the React frontend into an immutable release package.
5. Verifies SHA-256 asset checksums.
6. Atomically updates the `.active-release.json` pointer.
7. Reloads PHP-FPM (instantly resetting OPcache bytecode).
8. Restarts background queue workers gracefully.
9. Probes the `/api/health` endpoint to verify system availability.

### Instant Rollback (Zero Downtime)
If an unexpected regression is identified in a newly deployed frontend:
```bash
cd /var/www/tweak-insight-logistics/frontend

# List recent releases
ls -la ../backend/public/releases/

# Roll back atomically to previous release ID
node scripts/deploy-backend-build.js --rollback=20261015125600-abcdef123456
```

---

## Database Management & Automated Backups

### Running Migrations
To manually run database schema updates:
```bash
cd /var/www/tweak-insight-logistics/backend
php scripts/migrate_operational_architecture.php
php scripts/migrate_atomic_booking.php
php scripts/migrate_delivery_payments.php
php scripts/migrate_spatial_capabilities.php
php scripts/migrate_performance_indexes.php
```

### Automated Nightly Backups
A production backup script is provided at `deploy/scripts/backup-database.sh`.

To automate backups nightly at 03:00 UTC, add to root crontab:
```bash
sudo crontab -e
```
Add:
```cron
0 3 * * * /bin/bash /var/www/tweak-insight-logistics/deploy/scripts/backup-database.sh >> /var/log/til_backup.log 2>&1
```

Features of `backup-database.sh`:
* Creates a single-transaction, consistent dump (`--single-transaction --quick --routines --triggers --hex-blob`)
* Compresses using `gzip -9`
* Stores backups in `/var/backups/til/` with `0600` permissions
* Automatically prunes local backups older than 14 days
* Automatically syncs to private AWS S3 / Cloudflare R2 bucket if `S3_BUCKET` is configured

### Restoring from Backup
```bash
gunzip < /var/backups/til/til_backup_YYYYMMDD_HHMMSS.sql.gz | mysql -u til_app -p tweak_insight_logistics_prod
```

---

## Production Security & Hardening Checklist

| Security Control | Implementation | Verification Command |
| :--- | :--- | :--- |
| **Firewall (UFW)** | Only ports 22 (SSH), 80 (HTTP), 443 (HTTPS) open | `sudo ufw status verbose` |
| **MySQL Isolation** | Bound to `127.0.0.1`, root remote login disabled | `netstat -tuln \| grep 3306` |
| **Redis Isolation** | Bound to `127.0.0.1`, protected mode enabled | `netstat -tuln \| grep 6379` |
| **Directory Privacy** | Document root is strictly `backend/public` | Verify Nginx `root` directive |
| **KYC File Protection** | Denied in web server; streamed via auth controller | Probe `curl -I https://domain/storage/` (Expect 403/404) |
| **Security Headers** | HSTS (`max-age=31536000`), CSP, COOP, CORP, X-Frame-Options | Inspect response headers with `curl -I https://domain` |
| **Rate Limiting** | Nginx `limit_req` zones (30 req/s API, 5 req/s Auth) | Check `deploy/nginx/tweak-insight.conf` |
| **JWT Revocation** | `token_version` column in database invalidates tokens on logout | Tested via auth refresh tests |

---

## Health Verification & Diagnostics

Run the automated diagnostic suite at any time:
```bash
sudo bash deploy/scripts/verify-deployment.sh
```

To run the application credential and configuration unit tests:
```bash
cd /var/www/tweak-insight-logistics/backend
php tests/verify_credential_configuration.php
php tests/verify_cors_policy.php
php tests/verify_authorization_policy.php
```
