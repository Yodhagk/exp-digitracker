#!/bin/bash
# DigiTracker - Comprehensive Health Check
# Location: /var/app/script/healthcheck.sh
# Usage: healthcheck.sh [--quiet] [--log]
# Exit: 0=healthy, 1=degraded(warnings), 2=critical(failures)

APP_DIR="/var/www/html/digitracker"
LOG_DIR="/var/app/logs"
LOG="${LOG_DIR}/service.log"
DB_NAME="digitracker"
DB_USER="digiuser"
DB_PASS="Digi@2026"
QUIET=0; DO_LOG=0

for arg in "$@"; do
    [[ "$arg" == "--quiet" ]] && QUIET=1
    [[ "$arg" == "--log"   ]] && DO_LOG=1
done

stamp() { date '+%Y-%m-%d %H:%M:%S'; }
log_file() { [[ $DO_LOG -eq 1 ]] && echo "[$(stamp)] [HEALTH] $*" >> "$LOG"; }

PASS=0; WARN=0; FAIL=0

out() { [[ $QUIET -eq 0 ]] && echo "$1"; }
ok()  { out "  [OK]   $1"; ((PASS++)); }
warn(){ out "  [WARN] $1"; log_file "WARN: $1"; ((WARN++)); }
fail(){ out "  [FAIL] $1"; log_file "FAIL: $1"; ((FAIL++)); }

out ""
out "╔══════════════════════════════════════════════════╗"
out "║  DigiTracker Health Check — $(stamp)  ║"
out "╚══════════════════════════════════════════════════╝"
out ""
out "  ── Services ──────────────────────────────────────"

# Apache2
if systemctl is-active --quiet apache2; then
    VER=$(apache2 -v 2>/dev/null | head -1 | awk '{print $3}')
    ok "Apache2 running ($VER)"
else
    fail "Apache2 NOT running"
fi

# MySQL
if systemctl is-active --quiet mysql; then
    MYSQL_VER=$(mysql --version 2>/dev/null | awk '{print $3}')
    ok "MySQL running ($MYSQL_VER)"
else
    fail "MySQL NOT running"
fi

out ""
out "  ── Connectivity ───────────────────────────────────"

# HTTP response
HTTP=$(curl -s -o /dev/null -w '%{http_code}' --connect-timeout 5 http://localhost/ 2>/dev/null || echo "000")
if [[ "$HTTP" == "200" || "$HTTP" == "302" ]]; then
    ok "HTTP endpoint (localhost → HTTP $HTTP)"
else
    fail "HTTP endpoint returned HTTP ${HTTP:-timeout}"
fi

# DB app-user
DB_CHECK=$(mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "SELECT COUNT(*) FROM users;" 2>/dev/null | tail -1 || echo "")
if [[ -n "$DB_CHECK" && "$DB_CHECK" =~ ^[0-9]+$ ]]; then
    ok "DB app user (${DB_USER}@${DB_NAME} — ${DB_CHECK} users)"
else
    fail "DB app user ${DB_USER}@${DB_NAME} unreachable"
fi

# DB root ping
if mysqladmin ping --silent 2>/dev/null; then
    ok "MySQL root ping"
else
    warn "MySQL root ping failed"
fi

out ""
out "  ── Application ────────────────────────────────────"

# PHP version & mbstring
PHP_VER=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "unknown")
ok "PHP version: $PHP_VER"

if php -r "exit(extension_loaded('mbstring')?0:1);" 2>/dev/null; then
    ok "PHP mbstring extension loaded"
else
    warn "PHP mbstring missing — install php8.3-mbstring"
fi

# App files
for F in config.php index.php dashboard.php expenses.php loans.php \
          invoices.php warranties.php upcoming.php; do
    if [[ -f "${APP_DIR}/${F}" ]]; then
        ok "File present: $F"
    else
        fail "File MISSING: $F"
    fi
done

# Upload directories — check www-data ownership (set by deploy-app.sh chown)
for D in uploads/invoices uploads/warranties; do
    FULL="${APP_DIR}/${D}"
    if [[ -d "$FULL" ]]; then
        OWNER=$(stat -c '%U' "$FULL" 2>/dev/null || echo "unknown")
        if [[ "$OWNER" == "www-data" ]]; then
            ok "Upload dir owned by www-data: $D"
        else
            warn "Upload dir owner is '$OWNER' (expected www-data): $D"
        fi
    else
        fail "Upload dir MISSING: $D"
    fi
done

out ""
out "  ── System Resources ───────────────────────────────"

# Disk space
DISK_PCT=$(df "${APP_DIR}" | awk 'NR==2{gsub(/%/,"",$5); print $5}')
DISK_FREE=$(df -h "${APP_DIR}" | awk 'NR==2{print $4}')
if [[ "$DISK_PCT" -lt 80 ]]; then
    ok "Disk space: ${DISK_PCT}% used (${DISK_FREE} free)"
elif [[ "$DISK_PCT" -lt 90 ]]; then
    warn "Disk space high: ${DISK_PCT}% used (${DISK_FREE} free)"
else
    fail "Disk space critical: ${DISK_PCT}% used (${DISK_FREE} free)"
fi

# Memory
MEM_FREE=$(free -m | awk '/Mem:/{print $4}')
if [[ "$MEM_FREE" -gt 256 ]]; then
    ok "Memory free: ${MEM_FREE}MB"
elif [[ "$MEM_FREE" -gt 64 ]]; then
    warn "Memory low: ${MEM_FREE}MB free"
else
    fail "Memory critical: ${MEM_FREE}MB free"
fi

# Log directory — existence check only (written by services, not CI runner)
if [[ -d "$LOG_DIR" ]]; then
    LOG_SIZE=$(du -sh "$LOG_DIR" 2>/dev/null | awk '{print $1}')
    ok "App log dir exists (${LOG_SIZE} total)"
else
    warn "App log dir missing: ${LOG_DIR}"
fi

out ""
out "╔══════════════════════════════════════════════════╗"
printf "║  PASS: %-3d  WARN: %-3d  FAIL: %-3d              ║\n" "$PASS" "$WARN" "$FAIL"
out "╚══════════════════════════════════════════════════╝"
out ""

if [[ $FAIL -gt 0 ]]; then
    log_file "Status: CRITICAL — ${FAIL} failures, ${WARN} warnings, ${PASS} passed"
    exit 2
elif [[ $WARN -gt 0 ]]; then
    log_file "Status: DEGRADED — ${WARN} warnings, ${PASS} passed"
    exit 1
else
    log_file "Status: HEALTHY — all ${PASS} checks passed"
    exit 0
fi
