#!/usr/bin/env bash
# DigiTracker — make the GitHub Actions self-hosted runner resilient.
#
# Why this exists: the runner exits cleanly (status 0) whenever GitHub forces it
# to self-update. The unit that `svc.sh install` generates has no Restart=
# directive, so systemd leaves the service dead — and every queued deploy job
# then waits forever at "Waiting for a runner to pick up this job...".
#
# This installs a systemd drop-in so the runner is restarted automatically.
#
# Usage: sudo bash harden-runner.sh [unit-name]
set -euo pipefail

UNIT="${1:-}"
if [ -z "$UNIT" ]; then
  UNIT=$(systemctl list-units --type=service --all --plain --no-legend 'actions.runner.*' \
         | awk '{print $1}' | head -1)
fi

if [ -z "$UNIT" ]; then
  echo "[FAIL] No actions.runner.* systemd unit found."
  echo "       Register the runner first: sudo bash scripts/setup-runner.sh"
  exit 1
fi

echo "==> Hardening runner unit: $UNIT"

DROPIN_DIR="/etc/systemd/system/${UNIT}.d"
mkdir -p "$DROPIN_DIR"
cat > "$DROPIN_DIR/restart.conf" <<'CONF'
[Unit]
# Bound the retries: a genuinely broken runner (e.g. its registration was deleted
# by GitHub after a long offline period) must end up in `failed` state where it is
# visible, not loop forever pretending to recover.
StartLimitIntervalSec=300
StartLimitBurst=5

[Service]
# The runner process exits 0 after a forced self-update. Without this it would
# stay dead and queued jobs would never be picked up.
Restart=always
RestartSec=10
CONF
echo "[OK] Wrote $DROPIN_DIR/restart.conf"

systemctl daemon-reload
systemctl restart "$UNIT"

# Give the runner time to self-update and reconnect before reporting state.
sleep 20
echo "==> Status after restart:"
systemctl status "$UNIT" --no-pager -l 2>&1 | head -12

if systemctl is-active --quiet "$UNIT"; then
  echo "[OK] Runner is active and listening for jobs."
else
  echo "[WARN] Runner is not active. It may need re-registration:"
  echo "       cd /home/github-runner/actions-runner"
  echo "       sudo -u github-runner ./config.sh remove --token <REMOVE_TOKEN>"
  echo "       then re-run: sudo bash scripts/setup-runner.sh"
  echo "       Diagnostic log: sudo tail -50 /home/github-runner/actions-runner/_diag/Runner_*.log"
  exit 1
fi
