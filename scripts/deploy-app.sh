#!/usr/bin/env bash
# DigiTracker — deploy PHP app files from runner workspace to web root
# Called by .github/workflows/deploy.yml Step "Deploy PHP app files"
set -euo pipefail

WEBROOT="/var/www/html/digitracker"
WORKSPACE="$(pwd)"

echo "==> Source  : $WORKSPACE"
echo "==> Target  : $WEBROOT"

# Root PHP pages — every *.php in the repo root, not a hand-maintained list.
# (A hardcoded list is exactly how css/ got left undeployed for an entire
# redesign earlier, and how users.php would have silently never shipped here.)
for f in "$WORKSPACE"/*.php; do
  [ -f "$f" ] || continue
  sudo cp "$f" "$WEBROOT/$(basename "$f")"
  echo "  copied: $(basename "$f")"
done

# includes/ directory
sudo mkdir -p "$WEBROOT/includes"
for f in "$WORKSPACE"/includes/*.php; do
  [ -f "$f" ] || continue
  sudo cp "$f" "$WEBROOT/includes/$(basename "$f")"
  echo "  copied: includes/$(basename "$f")"
done

# css/ — every page loads css/style.css, so this must ship with the PHP files
sudo mkdir -p "$WEBROOT/css"
for f in "$WORKSPACE"/css/*; do
  [ -f "$f" ] || continue
  sudo cp "$f" "$WEBROOT/css/$(basename "$f")"
  echo "  copied: css/$(basename "$f")"
done

# Root-level static assets
for f in mlogo.png; do
  if [ -f "$WORKSPACE/$f" ]; then
    sudo cp "$WORKSPACE/$f" "$WEBROOT/$f"
    echo "  copied: $f"
  fi
done

# Fix ownership & permissions
sudo chown -R www-data:www-data "$WEBROOT"
sudo find "$WEBROOT" \( -name "*.php" -o -name "*.css" -o -name "*.png" \) -exec chmod 644 {} \;

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
