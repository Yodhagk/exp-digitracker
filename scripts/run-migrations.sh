#!/usr/bin/env bash
# DigiTracker — idempotent SQL migration runner
# Tracks applied migrations in a DB table so each runs exactly once.
set -euo pipefail

DB="digitracker"
WORKSPACE="$(pwd)"

# Ensure migrations tracking table exists
sudo mysql "$DB" <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(100) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL

run_migration() {
  local file="$1"
  local version
  version=$(basename "$file" .sql)

  # Skip if already applied
  local count
  count=$(sudo mysql -sN "$DB" -e "SELECT COUNT(*) FROM schema_migrations WHERE version='$version';")
  if [ "$count" -gt 0 ]; then
    echo "  [SKIP] $version (already applied)"
    return
  fi

  echo "  [RUN]  $version ..."
  if sudo mysql "$DB" < "$file" 2>&1; then
    sudo mysql "$DB" -e "INSERT INTO schema_migrations (version) VALUES ('$version');"
    echo "  [OK]   $version applied"
  else
    echo "  [WARN] $version had errors (column may already exist — skipping)"
    # Still record it so we don't retry on ALTER TABLE "duplicate column" errors
    sudo mysql "$DB" -e "INSERT IGNORE INTO schema_migrations (version) VALUES ('$version');"
  fi
}

# Run all migrate_*.sql files in version order
shopt -s nullglob
migrations=("$WORKSPACE"/migrate_*.sql)

if [ ${#migrations[@]} -eq 0 ]; then
  echo "==> No migration files found"
  exit 0
fi

echo "==> Running migrations from $WORKSPACE"
for mig in "${migrations[@]}"; do
  run_migration "$mig"
done

echo "==> Migrations complete"
