#!/bin/bash
# DigiTracker - Application Startup Script
# Location: /var/app/script/startup.sh
# Called by: systemd digitracker.service (ExecStart)

set -uo pipefail

LOG_DIR="/var/app/logs"
LOG="${LOG_DIR}/service.log"
APP_DIR="/var/www/html/digitracker"
DB_NAME="digitracker"
DB_USER="digiuser"
DB_PASS="Digi@2026"

# ── Bootstrap log directory ────────────────────────────────
mkdir -p "$LOG_DIR"
chmod 755 "$LOG_DIR"
chown www-data:www-data "$LOG_DIR" 2>/dev/null || true

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] [STARTUP] $*"
    echo "$msg" | tee -a "$LOG"
}

log "========================================================="
log "DigiTracker startup initiated"
log "Host: $(hostname) | Kernel: $(uname -r) | PHP: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"

# ── Wait for MySQL (up to 60s) ─────────────────────────────
log "Waiting for MySQL..."
MYSQL_READY=0
for i in $(seq 1 60); do
    if mysqladmin ping --silent 2>/dev/null; then
        log "MySQL: READY (after ${i}s)"
        MYSQL_READY=1
        break
    fi
    sleep 1
done
if [[ $MYSQL_READY -eq 0 ]]; then
    log "ERROR: MySQL not ready after 60s — startup aborted"
    exit 1
fi

# ── Wait for Apache (up to 30s) ───────────────────────────
log "Waiting for Apache2..."
APACHE_READY=0
for i in $(seq 1 30); do
    if systemctl is-active --quiet apache2; then
        log "Apache2: READY (after ${i}s)"
        APACHE_READY=1
        break
    fi
    sleep 1
done
if [[ $APACHE_READY -eq 0 ]]; then
    log "ERROR: Apache2 not ready after 30s — startup aborted"
    exit 1
fi

# ── HTTP health check ──────────────────────────────────────
sleep 1
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --connect-timeout 5 http://localhost/ 2>/dev/null || echo "000")
if [[ "$HTTP" == "200" || "$HTTP" == "302" ]]; then
    log "HTTP health check: PASSED (HTTP $HTTP)"
else
    log "WARNING: HTTP health check returned HTTP $HTTP — app may not be serving"
fi

# ── DB app-user connectivity check ────────────────────────
DB_ROWS=$(mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "SELECT COUNT(*) FROM users;" 2>/dev/null | tail -1 || echo "")
if [[ -n "$DB_ROWS" && "$DB_ROWS" =~ ^[0-9]+$ ]]; then
    log "DB connectivity (${DB_USER}@${DB_NAME}): OK — ${DB_ROWS} user(s)"
else
    log "WARNING: DB app-user check failed — run migrate_v2.sql if new install"
fi

# ── Ensure upload directories exist and are writable ──────
for D in \
    "${APP_DIR}/uploads" \
    "${APP_DIR}/uploads/invoices" \
    "${APP_DIR}/uploads/warranties"; do
    mkdir -p "$D"
    chown www-data:www-data "$D"
    chmod 775 "$D"
    log "Upload dir: $D — OK"
done

# ── Ensure app log dir is writable by www-data ────────────
chown www-data:www-data "$LOG_DIR"
chmod 775 "$LOG_DIR"
log "App log dir: ${LOG_DIR} — OK"

# ── Record system snapshot ─────────────────────────────────
MEM_FREE=$(free -m | awk '/Mem:/{print $4}')
DISK_USE=$(df -h "$APP_DIR" | awk 'NR==2{print $5}')
log "System: mem_free=${MEM_FREE}MB disk_used=${DISK_USE}"

log "DigiTracker startup COMPLETE"
log "========================================================="
exit 0
