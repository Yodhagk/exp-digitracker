#!/bin/bash
# DigiTracker - Application Shutdown Script
# Location: /var/app/script/shutdown.sh
# Called by: systemd digitracker.service (ExecStop)
#
# NOTE: Apache2 and MySQL are managed by their own systemd units.
#       This script handles ONLY app-level cleanup (session drain,
#       log flush, snapshot). Systemd stops the other services
#       automatically in the correct dependency order.

LOG_DIR="/var/app/logs"
LOG="${LOG_DIR}/service.log"

mkdir -p "$LOG_DIR"

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] [SHUTDOWN] $*"
    echo "$msg" | tee -a "$LOG"
}

log "========================================================="
log "DigiTracker shutdown initiated on $(hostname)"
log "Uptime: $(uptime -p 2>/dev/null || uptime)"

# ── Count and drain active PHP sessions ───────────────────
SESSION_DIR="/var/lib/php/sessions"
SESSION_COUNT=$(find "$SESSION_DIR" -name 'sess_*' 2>/dev/null | wc -l)
log "Active PHP sessions at shutdown: ${SESSION_COUNT}"

if [[ $SESSION_COUNT -gt 0 ]]; then
    log "Allowing 3s for in-flight requests to complete..."
    sleep 3
fi

# ── Flush MySQL binary logs (while MySQL is still up) ─────
if mysqladmin ping --silent 2>/dev/null; then
    mysqladmin flush-logs 2>/dev/null && log "MySQL binary logs flushed" \
        || log "WARNING: MySQL flush-logs failed (non-critical)"
fi

# ── Final resource snapshot ───────────────────────────────
MEM_FREE=$(free -m 2>/dev/null | awk '/Mem:/{print $4}')
DISK_USE=$(df -h /var/www/html/digitracker 2>/dev/null | awk 'NR==2{print $5}')
log "Final snapshot: mem_free=${MEM_FREE}MB disk_used=${DISK_USE}"

log "DigiTracker app-level shutdown COMPLETE"
log "  (Apache2 + MySQL will be stopped by systemd in dependency order)"
log "========================================================="
exit 0
