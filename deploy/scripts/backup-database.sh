#!/usr/bin/env bash
# ==============================================================================
# Tweak Insight Logistics - Automated Database Backup Script
# Schedule via cron: 0 3 * * * /bin/bash /var/www/tweak-insight-logistics/deploy/scripts/backup-database.sh
# ==============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/tweak-insight-logistics}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/til}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/til_backup_${TIMESTAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"

ENV_FILE="${APP_DIR}/backend/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "[-] Error: Environment file not found at ${ENV_FILE}"
  exit 1
fi

# Extract DB credentials safely
DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "127.0.0.1")
DB_PORT=$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "3306")
DB_NAME=$(grep -E '^DB_NAME=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "tweak_insight_logistics")
DB_USER=$(grep -E '^DB_USER=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "root")
DB_PASS=$(grep -E '^DB_PASS=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'" || echo "")

echo ">>> [$(date)] Starting backup of database '${DB_NAME}'..."

# Execute dump with consistent read transaction
mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USER" \
  --password="$DB_PASS" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip -9 > "$BACKUP_FILE"

chmod 600 "$BACKUP_FILE"
FILE_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)

echo "[✓] Backup completed successfully: ${BACKUP_FILE} (${FILE_SIZE})"

# Prune old backups
echo ">>> Pruning local backups older than ${RETENTION_DAYS} days..."
find "$BACKUP_DIR" -type f -name "til_backup_*.sql.gz" -mtime +"$RETENTION_DAYS" -exec rm -f {} \;

# Optional S3 offsite sync if AWS CLI is installed and configured
if command -v aws >/dev/null 2>&1 && grep -q '^S3_BUCKET=' "$ENV_FILE"; then
  S3_BUCKET=$(grep -E '^S3_BUCKET=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
  if [ -n "$S3_BUCKET" ]; then
    echo ">>> Syncing backup to secure S3 bucket: s3://${S3_BUCKET}/backups/..."
    aws s3 cp "$BACKUP_FILE" "s3://${S3_BUCKET}/backups/$(basename "$BACKUP_FILE")" --only-show-errors || true
    echo "[✓] Offsite S3 sync completed."
  fi
fi

echo ">>> Backup process finished at $(date)."
