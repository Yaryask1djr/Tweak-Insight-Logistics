#!/usr/bin/env bash
set -e

# ==============================================================================
# Tweak Insight Logistics - Queue Worker Daemon Entrypoint
# ==============================================================================

echo ">>> [TIL-WORKER] Initializing queue worker daemon..."

APP_DIR="/var/www/html/backend"

# Ensure storage directories exist
mkdir -p "${APP_DIR}/storage/logs"
chown -R www-data:www-data "${APP_DIR}/storage" 2>/dev/null || true

# Wait for Redis if REDIS_HOST is set
if [ -n "${REDIS_HOST}" ]; then
  echo ">>> [TIL-WORKER] Checking Redis broker at ${REDIS_HOST}:${REDIS_PORT:-6379}..."
  MAX_TRIES=30
  COUNT=0
  until php -r '
    $host = getenv("REDIS_HOST");
    $port = (int)(getenv("REDIS_PORT") ?: 6379);
    $pass = getenv("REDIS_PASSWORD") ?: null;
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if (!$fp) exit(1);
    fclose($fp);
    exit(0);
  ' 2>/dev/null; do
    COUNT=$((COUNT + 1))
    if [ $COUNT -ge $MAX_TRIES ]; then
      echo "[-] [TIL-WORKER] Redis timeout reached. Attempting worker boot..."
      break
    fi
    sleep 2
  done
  if [ $COUNT -lt $MAX_TRIES ]; then
    echo "[+] [TIL-WORKER] Redis broker connected."
  fi
fi

QUEUE_NAME="${QUEUE_NAME:-default}"
SLEEP_SECS="${WORKER_SLEEP:-2}"
MEMORY_LIMIT="${WORKER_MEMORY:-128}"
MAX_JOBS="${WORKER_MAX_JOBS:-1000}"

echo ">>> [TIL-WORKER] Starting queue worker on [${QUEUE_NAME}] (memory-limit: ${MEMORY_LIMIT}MB, max-jobs: ${MAX_JOBS})..."
exec php "${APP_DIR}/scripts/queue_worker.php" \
  --queue="${QUEUE_NAME}" \
  --sleep="${SLEEP_SECS}" \
  --memory-limit="${MEMORY_LIMIT}" \
  --max-jobs="${MAX_JOBS}"
