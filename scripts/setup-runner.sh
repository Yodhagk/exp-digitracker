#!/usr/bin/env bash
# DigiTracker — Install GitHub Actions self-hosted runner on the server
# Run as: sudo bash scripts/setup-runner.sh
# Prereqs: curl, tar, systemd (Ubuntu 24.04 LTS)
set -euo pipefail

REPO_URL="https://github.com/Yodhagk/exp-digitracker"
RUNNER_VERSION="2.319.1"
RUNNER_USER="github-runner"
RUNNER_DIR="/home/${RUNNER_USER}/actions-runner"
RUNNER_LABEL="digitracker-prod"

echo "============================================"
echo "  DigiTracker GitHub Actions Runner Setup"
echo "============================================"
echo "Repo   : $REPO_URL"
echo "Label  : $RUNNER_LABEL"
echo "Dir    : $RUNNER_DIR"
echo ""

# 1. Create runner user (no login shell, no password)
if ! id "$RUNNER_USER" &>/dev/null; then
  useradd -m -s /bin/bash "$RUNNER_USER"
  echo "[OK] Created user: $RUNNER_USER"
else
  echo "[SKIP] User $RUNNER_USER already exists"
fi

# 2. Configure passwordless sudo for specific commands the runner needs
SUDOERS_FILE="/etc/sudoers.d/github-runner"
cat > "$SUDOERS_FILE" <<SUDOERS
# GitHub Actions runner — allow DigiTracker deploy operations
github-runner ALL=(ALL) NOPASSWD: /bin/cp, /bin/mkdir, /bin/chown, /bin/chmod, /usr/bin/find
github-runner ALL=(ALL) NOPASSWD: /usr/bin/mysql, /usr/sbin/apache2ctl
github-runner ALL=(ALL) NOPASSWD: /bin/systemctl reload apache2
github-runner ALL=(ALL) NOPASSWD: /bin/systemctl restart apache2
SUDOERS
chmod 440 "$SUDOERS_FILE"
echo "[OK] Sudoers configured: $SUDOERS_FILE"

# 3. Download runner package
RUNNER_PKG="actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
RUNNER_URL="https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${RUNNER_PKG}"

mkdir -p "$RUNNER_DIR"
cd "$RUNNER_DIR"

if [ ! -f "$RUNNER_PKG" ]; then
  echo "[..] Downloading runner v${RUNNER_VERSION} ..."
  sudo -u "$RUNNER_USER" curl -fsSL -o "$RUNNER_PKG" "$RUNNER_URL"
fi
sudo -u "$RUNNER_USER" tar xzf "$RUNNER_PKG"
echo "[OK] Runner extracted to $RUNNER_DIR"

# 4. Get registration token
echo ""
echo "======================================================="
echo "  You need a GitHub registration token."
echo "  Go to: $REPO_URL/settings/actions/runners/new"
echo "  Copy the token shown under 'Configure' step."
echo "======================================================="
read -rp "Paste registration token: " REG_TOKEN

# 5. Configure the runner
sudo -u "$RUNNER_USER" ./config.sh \
  --url "$REPO_URL" \
  --token "$REG_TOKEN" \
  --name "$(hostname)" \
  --labels "$RUNNER_LABEL" \
  --work "_work" \
  --unattended

echo "[OK] Runner configured with label: $RUNNER_LABEL"

# 6. Install as systemd service
./svc.sh install "$RUNNER_USER"
./svc.sh start
echo "[OK] Runner service installed and started"

# 7. Verify
sleep 3
STATUS=$(./svc.sh status 2>&1 || true)
echo ""
echo "==> Runner service status:"
echo "$STATUS"

echo ""
echo "======================================================="
echo "  SETUP COMPLETE!"
echo "  The runner is now live and listening for jobs."
echo "  Push to 'main' branch to trigger auto-deployment."
echo "======================================================="
