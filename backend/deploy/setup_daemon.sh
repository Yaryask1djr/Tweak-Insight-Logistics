#!/usr/bin/env bash
set -e

# ==============================================================================
#  Tweak Insight Logistics — Daemon Installer
#    Sets up (1) the queue-worker long-running daemon AND
#            (2) the nightly maintenance timer (systemd-timer or cron).
#
#  Usage:
#    sudo bash backend/deploy/setup_daemon.sh systemd        # systemd + timer (preferred)
#    sudo bash backend/deploy/setup_daemon.sh supervisor     # supervisor + crontab
# ==============================================================================

DEPLOY_MODE="${1:-systemd}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${APP_DIR}/backend"
WWW_USER="${WWW_USER:-www-data}"
WWW_GROUP="${WWW_GROUP:-www-data}"

echo ">>> Setting up Tweak Insight Logistics daemons [${DEPLOY_MODE}]..."

if [ "$EUID" -ne 0 ]; then
  echo "[-] Please run as root (or use sudo)."
  exit 1
fi

# Ensure the storage directory is writable by the daemon user
echo "[+] Verifying storage permissions..."
mkdir -p "${BACKEND_DIR}/storage/logs" \
         "${BACKEND_DIR}/storage/cache" \
         "${BACKEND_DIR}/storage/exports" \
         "${BACKEND_DIR}/storage/rate_limits" \
         "${BACKEND_DIR}/storage/client-kyc" \
         "${BACKEND_DIR}/storage/driver-documents"
chown -R "${WWW_USER}:${WWW_GROUP}" "${BACKEND_DIR}/storage"
chmod -R u+rwX,go-rwx "${BACKEND_DIR}/storage"

if [ "${DEPLOY_MODE}" == "systemd" ]; then
  # --------------------------------------------------------------------------
  #  1. Queue worker service
  # --------------------------------------------------------------------------
  echo "[+] Installing systemd queue-worker unit..."
  cp "${BACKEND_DIR}/deploy/systemd/til-queue-worker.service" /etc/systemd/system/til-queue-worker.service
  sed -i "s|/var/www/tweak-insight-logistics/backend|${BACKEND_DIR}|g" /etc/systemd/system/til-queue-worker.service
  sed -i "s|^User=www-data|User=${WWW_USER}|; s|^Group=www-data|Group=${WWW_GROUP}|" /etc/systemd/system/til-queue-worker.service

  # --------------------------------------------------------------------------
  #  2. Nightly maintenance service + timer
  # --------------------------------------------------------------------------
  echo "[+] Installing systemd nightly maintenance service + timer..."
  cp "${BACKEND_DIR}/deploy/systemd/til-maintenance.service" /etc/systemd/system/til-maintenance.service
  cp "${BACKEND_DIR}/deploy/systemd/til-maintenance.timer"   /etc/systemd/system/til-maintenance.timer
  sed -i "s|/var/www/tweak-insight-logistics/backend|${BACKEND_DIR}|g" /etc/systemd/system/til-maintenance.service
  sed -i "s|^User=www-data|User=${WWW_USER}|; s|^Group=www-data|Group=${WWW_GROUP}|" /etc/systemd/system/til-maintenance.service
  sed -i "s|ReadWritePaths=.*|ReadWritePaths=${BACKEND_DIR}/storage|"  /etc/systemd/system/til-maintenance.service

  systemctl daemon-reload
  systemctl enable  til-queue-worker.service til-maintenance.timer
  systemctl restart til-queue-worker.service
  systemctl start   til-maintenance.timer

  echo ""
  echo "[✓] systemd units installed."
  echo "    Worker status:  sudo systemctl status til-queue-worker.service"
  echo "    Worker logs :   sudo journalctl -u til-queue-worker.service -f"
  echo "    Maint. logs :   sudo journalctl -u til-maintenance.service   --since today"
  echo "    Timer list  :   sudo systemctl list-timers til-maintenance.timer --all"
  echo "    Run now (dry): sudo systemctl start til-maintenance.service"

elif [ "${DEPLOY_MODE}" == "supervisor" ]; then
  # --------------------------------------------------------------------------
  #  1. Queue worker program (supervisor)
  # --------------------------------------------------------------------------
  echo "[+] Installing supervisor queue-worker program..."
  SUPERVISOR_CONF_DIR="/etc/supervisor/conf.d"
  mkdir -p "${SUPERVISOR_CONF_DIR}"
  cp "${BACKEND_DIR}/deploy/supervisor/til-queue-worker.conf" "${SUPERVISOR_CONF_DIR}/til-queue-worker.conf"
  sed -i "s|/var/www/tweak-insight-logistics/backend|${BACKEND_DIR}|g" "${SUPERVISOR_CONF_DIR}/til-queue-worker.conf"

  # --------------------------------------------------------------------------
  #  2. Maintenance job + watchdog via system crontab for www-data
  # --------------------------------------------------------------------------
  echo "[+] Installing crontab entries for maintenance + watchdog..."
  CRONTAB_SRC="${BACKEND_DIR}/deploy/crontab/til-production.crontab"
  CRONTAB_TMP="$(mktemp)"
  cp "${CRONTAB_SRC}" "${CRONTAB_TMP}"
  sed -i "s|/var/www/tweak-insight-logistics/backend|${BACKEND_DIR}|g" "${CRONTAB_TMP}"
  # Install under the web-server user
  crontab -u "${WWW_USER}" "${CRONTAB_TMP}"
  rm -f "${CRONTAB_TMP}"

  supervisorctl reread || true
  supervisorctl update || true
  supervisorctl restart til-queue-worker:* || true

  echo ""
  echo "[✓] Supervisor + crontab installed."
  echo "    Worker status:  sudo supervisorctl status til-queue-worker:*"
  echo "    Worker logs :   sudo tail -f /var/log/supervisor/til-queue-worker.log"
  echo "    Maint  logs :   ${BACKEND_DIR}/storage/logs/maintenance.log"
  echo "    Watchdog logs:  ${BACKEND_DIR}/storage/logs/worker-watchdog.log"
  echo "    Crontab     :   sudo crontab -u ${WWW_USER} -l"

else
  echo "[-] Unknown deploy mode: ${DEPLOY_MODE}. Use 'systemd' or 'supervisor'."
  exit 1
fi

echo ""
echo "=========================================================================="
echo "  ⚠ Post-install checklist"
echo "=========================================================================="
echo "   1. Confirm backend/.env has APP_ENV=production + DB_USER=til_app (not root)"
echo "   2. Rotate the JWT_SECRET and DB_PASS values — the old ones were"
echo "      exposed in the originally-committed .env file."
echo "   3. Review storage owner:  ls -la ${BACKEND_DIR}/storage/logs"
echo "   4. Run purge scripts manually once to validate CLI works:"
echo "          sudo -u ${WWW_USER} php ${BACKEND_DIR}/scripts/run_all_maintenance.php"
echo "=========================================================================="
echo "[✓] Done."
