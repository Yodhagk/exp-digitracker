#!/usr/bin/env bash
# DigiTracker — full database backup (schema + data)
# Location: /var/app/script/backup-db.sh
# Usage: backup-db.sh [--quiet]
#
# Writes a timestamped, gzip-compressed dump to $BACKUP_DIR and refreshes
# latest.sql.gz, which restore-db.sh uses by default when bringing up a new machine.
set -euo pipefail

DB_NAME="digitracker"
BACKUP_DIR="/var/backups/digitracker"
LOG_DIR="/var/app/logs"
LOG="${LOG_DIR}/service.log"
KEEP=14   # dated backups to retain; latest.sql.gz is always kept regardless

QUIET=0
for arg in "$@"; do [[ "$arg" == "--quiet" ]] && QUIET=1; done

stamp() { date '+%Y-%m-%d %H:%M:%S'; }
out()   { [[ $QUIET -eq 0 ]] && echo "$1"; }
log()   { echo "[$(stamp)] [BACKUP] $*" >> "$LOG" 2>/dev/null || true; }

sudo mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/${DB_NAME}_$(date '+%Y%m%d_%H%M%S').sql.gz"

out "==> Backing up database '$DB_NAME' -> $FILE"
if sudo mysqldump --single-transaction --routines --triggers "$DB_NAME" | gzip > "$FILE" 2>/tmp/backup-db.err; then
    sudo cp -f "$FILE" "$BACKUP_DIR/latest.sql.gz"
    SIZE=$(du -h "$FILE" | cut -f1)
    out "[OK] Backup saved ($SIZE)"
    log "OK: $FILE ($SIZE)"
else
    out "[FAIL] mysqldump failed — see /tmp/backup-db.err"
    log "FAIL: mysqldump failed: $(cat /tmp/backup-db.err 2>/dev/null)"
    rm -f "$FILE"
    exit 1
fi

# Rotate: keep only the last $KEEP dated backups (latest.sql.gz is untouched)
COUNT=$(sudo bash -c "ls -1 '$BACKUP_DIR'/${DB_NAME}_*.sql.gz 2>/dev/null | wc -l")
if [ "$COUNT" -gt "$KEEP" ]; then
    sudo bash -c "ls -1t '$BACKUP_DIR'/${DB_NAME}_*.sql.gz | tail -n +$((KEEP + 1)) | xargs -r rm -f"
    out "[OK] Rotated old backups, keeping last $KEEP"
fi
