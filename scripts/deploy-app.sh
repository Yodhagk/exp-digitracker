#!/usr/bin/env bash
# DigiTracker — deploy PHP app files from runner workspace to web root
# Called by .github/workflows/deploy.yml Step "Deploy PHP app files"
set -euo pipefail

WEBROOT="/var/www/html/digitracker"
WORKSPACE="$(pwd)"

echo "==> Source  : $WORKSPACE"
echo "==> Target  : $WEBROOT"

# Root PHP pages
ROOT_FILES=(
  dashboard.php expenses.php loans.php reports.php
  invoices.php warranties.php upcoming.php
  index.php login.php logout.php register.php config.php
)
for f in "${ROOT_FILES[@]}"; do
  if [ -f "$WORKSPACE/$f" ]; then
    sudo cp "$WORKSPACE/$f" "$WEBROOT/$f"
    echo "  copied: $f"
  fi
done

# includes/ directory
sudo mkdir -p "$WEBROOT/includes"
for f in "$WORKSPACE"/includes/*.php; do
  [ -f "$f" ] || continue
  sudo cp "$f" "$WEBROOT/includes/$(basename "$f")"
  echo "  copied: includes/$(basename "$f")"
done

# Fix ownership & permissions
sudo chown -R www-data:www-data "$WEBROOT"
sudo find "$WEBROOT" -name "*.php" -exec chmod 644 {} \;

# Sync operational scripts (healthcheck, etc.) to /var/app/script/
SCRIPT_SRC="$WORKSPACE/scripts"
SCRIPT_DST="/var/app/script"
if [ -d "$SCRIPT_SRC" ]; then
  sudo mkdir -p "$SCRIPT_DST"
  for s in "$SCRIPT_SRC"/*.sh; do
    [ -f "$s" ] || continue
    sudo cp "$s" "$SCRIPT_DST/$(basename "$s")"
    sudo chmod 755 "$SCRIPT_DST/$(basename "$s")"
    echo "  synced: scripts/$(basename "$s")"
  done
fi

echo "==> App files deployed successfully"
