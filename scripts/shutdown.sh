#!/bin/bash
# DigiTracker - Application Shutdown Script
# Location: /var/app/script/shutdown.sh
# Called by: systemd digitracker.service (ExecStop)

LOG_DIR="/var/app/logs"
LOG="${LOG_DIR}/service.log"

mkdir -p "$LOG_DIR"

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] [SHUTDOWN] $*"
    echo "$msg" | tee -a "$LOG"
}

log "========================================================="
log "DigiTracker shutdown initiated on $(hostname)"

# ── Log active PHP sessions ───────────────────────────────
SESSION_COUNT=$(find /var/lib/php/sessions/ -name 'sess_*' 2>/dev/null | wc -l)
log "Active PHP sessions at shutdown: ${SESSION_COUNT}"

if [[ $SESSION_COUNT -gt 0 ]]; then
    log "Allowing 5s for active requests to drain..."
    sleep 5
fi

# ── Graceful Apache shutdown ──────────────────────────────
if systemctl is-active --quiet apache2; then
    log "Stopping Apache2 gracefully (graceful-stop)..."
    apachectl graceful-stop 2>/dev/null || true

    # Wait up to 15s for graceful stop
    for i in $(seq 1 15); do
        sleep 1
        if ! systemctl is-active --quiet apache2; then
            log "Apache2: STOPPED (after ${i}s)"
            break
        fi
        if [[ $i -eq 15 ]]; then
            log "WARNING: Graceful stop timed out — forcing stop"
            systemctl stop apache2
            log "Apache2: FORCE STOPPED"
        fi
    done
else
    log "Apache2: already stopped"
fi

# ── MySQL graceful shutdown ───────────────────────────────
if systemctl is-active --quiet mysql; then
    log "Flushing MySQL binary logs..."
    mysqladmin flush-logs 2>/dev/null || true
    log "Stopping MySQL..."
    systemctl stop mysql
    log "MySQL: STOPPED"
else
    log "MySQL: already stopped"
fi

# ── Final resource snapshot ───────────────────────────────
DISK_USE=$(df -h /var/www/html/digitracker 2>/dev/null | awk 'NR==2{print $5}' || echo "?")
log "Disk usage at shutdown: ${DISK_USE}"

log "DigiTracker shutdown COMPLETE"
log "========================================================="
exit 0
