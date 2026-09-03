#!/usr/bin/env bash
# DigiTracker — restore the database from a backup dump.
# Location: /var/app/script/restore-db.sh
# Usage: restore-db.sh [path-to-dump.sql.gz] [--force]
#
# Used when bringing up a new machine: run setup.sql first to create the
# database/user, copy a dump produced by backup-db.sh onto the new box, then
# run this script to load the real data instead of starting from empty tables.
#
# Refuses to overwrite a database that already has users unless --force is given.
set -euo pipefail

DB_NAME="digitracker"
BACKUP_DIR="/var/backups/digitracker"

DUMP=""
FORCE=0
for arg in "$@"; do
    if [[ "$arg" == "--force" ]]; then
        FORCE=1
    elif [[ -z "$DUMP" ]]; then
        DUMP="$arg"
    fi
done
DUMP="${DUMP:-$BACKUP_DIR/latest.sql.gz}"

if [ ! -f "$DUMP" ]; then
    echo "[FAIL] Dump file not found: $DUMP"
    echo "       Copy a backup produced by backup-db.sh onto this machine first, e.g.:"
    echo "       scp old-host:/var/backups/digitracker/latest.sql.gz $DUMP"
    exit 1
fi

EXISTING=$(sudo mysql -sN "$DB_NAME" -e "SELECT COUNT(*) FROM users;" 2>/dev/null || echo "0")
if [ "$EXISTING" != "0" ] && [ "$FORCE" -ne 1 ]; then
    echo "[FAIL] Database '$DB_NAME' already has $EXISTING user(s)."
    echo "       Re-run with --force to overwrite it: restore-db.sh $DUMP --force"
    exit 1
fi

echo "==> Restoring '$DB_NAME' from $DUMP ..."
gunzip -c "$DUMP" | sudo mysql "$DB_NAME"
echo "[OK] Database restored from $DUMP"
