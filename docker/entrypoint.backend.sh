#!/usr/bin/env bash
set -e

# ==============================================================================
# Tweak Insight Logistics - Backend Entrypoint
# ==============================================================================

echo ">>> [TIL-BACKEND] Initializing container environment..."

APP_DIR="/var/www/html/backend"

# Ensure storage directories exist with secure permissions
mkdir -p "${APP_DIR}/storage/logs" \
         "${APP_DIR}/storage/cache" \
         "${APP_DIR}/storage/exports" \
         "${APP_DIR}/storage/rate_limits" \
         "${APP_DIR}/storage/client-kyc" \
         "${APP_DIR}/storage/driver-documents"

chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/public" 2>/dev/null || true
chmod -R 775 "${APP_DIR}/storage" 2>/dev/null || true

# Wait for MySQL if DB_HOST is defined
if [ -n "${DB_HOST}" ]; then
  echo ">>> [TIL-BACKEND] Waiting for database at ${DB_HOST}:${DB_PORT:-3306}..."
  MAX_TRIES=30
  COUNT=0
  until php -r '
    $host = getenv("DB_HOST");
    $port = getenv("DB_PORT") ?: 3306;
    $db   = getenv("DB_NAME") ?: "tweak_insight_logistics";
    $user = getenv("DB_USER") ?: "root";
    $pass = getenv("DB_PASS") ?: "";
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
        exit(0);
    } catch (\Throwable $e) {
        exit(1);
    }
  ' 2>/dev/null; do
    COUNT=$((COUNT + 1))
    if [ $COUNT -ge $MAX_TRIES ]; then
      echo "[-] [TIL-BACKEND] Warning: Database connection timeout. Continuing startup..."
      break
    fi
    sleep 2
  done
  if [ $COUNT -lt $MAX_TRIES ]; then
    echo "[+] [TIL-BACKEND] Database connection verified."
  fi
fi

# Run migrations if enabled
if [ "${RUN_MIGRATIONS}" = "true" ] || [ "${RUN_MIGRATIONS}" = "1" ]; then
  echo ">>> [TIL-BACKEND] Executing database migrations..."
  php "${APP_DIR}/scripts/migrate_operational_architecture.php" || true
  php "${APP_DIR}/scripts/migrate_atomic_booking.php" || true
  php "${APP_DIR}/scripts/migrate_delivery_payments.php" || true
  php "${APP_DIR}/scripts/migrate_spatial_capabilities.php" || true
  php "${APP_DIR}/scripts/migrate_performance_indexes.php" || true
  echo "[+] [TIL-BACKEND] Database migrations complete."
fi

# Seed demo data if requested
if [ "${SEED_DEMO}" = "true" ] || [ "${SEED_DEMO}" = "1" ]; then
  echo ">>> [TIL-BACKEND] Seeding demonstration accounts..."
  php "${APP_DIR}/scripts/seed_demo_accounts.php" || true
fi

echo ">>> [TIL-BACKEND] Startup complete. Handing over to: $@"
exec "$@"
