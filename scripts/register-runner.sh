#!/usr/bin/env bash
# Run once to register the self-hosted runner with GitHub
# Usage: sudo bash /var/app/script/register-runner.sh <TOKEN>
# Get token from: https://github.com/Yodhagk/exp-digitracker/settings/actions/runners/new
set -euo pipefail

RUNNER_DIR=/home/github-runner/actions-runner
REPO_URL=https://github.com/Yodhagk/exp-digitracker

if [ -z "${1:-}" ]; then
  echo "=========================================="
  echo "  DigiTracker Runner Registration"
  echo "=========================================="
  echo "Usage: sudo bash $0 <TOKEN>"
  echo ""
  echo "Get your token at:"
  echo "  $REPO_URL/settings/actions/runners/new"
  echo "(Click 'New self-hosted runner', copy the token shown)"
  exit 1
fi

TOKEN="$1"

echo "==> Configuring runner ..."
cd "$RUNNER_DIR"
sudo -u github-runner ./config.sh \
  --url "$REPO_URL" \
  --token "$TOKEN" \
  --name "$(hostname)" \
  --labels "digitracker-prod" \
  --work "_work" \
  --unattended

echo "==> Installing systemd service ..."
./svc.sh install github-runner
./svc.sh start

echo ""
echo "==> Runner registered and started!"
systemctl status "actions.runner.Yodhagk-exp-digitracker.$(hostname).service" --no-pager 2>/dev/null | head -8 || \
  systemctl list-units 'actions.runner.*' --no-pager | head -5
