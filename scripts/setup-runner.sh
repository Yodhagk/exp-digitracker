#!/usr/bin/env bash
# DigiTracker — Install GitHub Actions self-hosted runner on the server
# Run as: sudo bash scripts/setup-runner.sh
# Prereqs: curl, tar, systemd (Ubuntu 24.04 LTS)
set -euo pipefail

REPO_URL="https://github.com/Yodhagk/exp-digitracker"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNNER_USER="github-runner"

# Resolve the latest runner release rather than pinning a version that goes stale.
# Override with: RUNNER_VERSION=x.y.z sudo -E bash setup-runner.sh
if [ -z "${RUNNER_VERSION:-}" ]; then
  RUNNER_VERSION=$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest 2>/dev/null \
    | grep -m1 '"tag_name"' | sed -E 's/.*"tag_name": *"v?([^"]+)".*/\1/' || true)
fi
if ! printf '%s' "${RUNNER_VERSION:-}" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "[ERR] Could not resolve a valid runner version (got: '${RUNNER_VERSION:-}')."
  echo "      The GitHub API may be rate limited from this network."
  echo "      Set it explicitly: RUNNER_VERSION=x.y.z sudo -E bash $0"
  echo "      Latest version is listed at https://github.com/actions/runner/releases/latest"
  exit 1
fi
echo "[OK] Runner version to install: v${RUNNER_VERSION}"
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
github-runner ALL=(ALL) NOPASSWD: /var/app/script/backup-db.sh, /var/app/script/restore-db.sh
SUDOERS
chmod 440 "$SUDOERS_FILE"
echo "[OK] Sudoers configured: $SUDOERS_FILE"

# 2b. Tear down any existing install so this script is safe to re-run.
#     A stale runner that GitHub forces to self-update will crash-loop instead of
#     accepting jobs, and the only reliable cure is a clean re-install.
EXISTING_UNIT=$(systemctl list-units --type=service --all --plain --no-legend 'actions.runner.*' \
                | awk '{print $1}' | head -1)
if [ -n "$EXISTING_UNIT" ]; then
  echo "[..] Removing existing runner service: $EXISTING_UNIT"
  systemctl stop "$EXISTING_UNIT" 2>/dev/null || true
  systemctl reset-failed "$EXISTING_UNIT" 2>/dev/null || true
  if [ -x "${RUNNER_DIR}/svc.sh" ]; then
    (cd "$RUNNER_DIR" && ./svc.sh uninstall 2>/dev/null) || true
  fi
  echo "[OK] Existing runner service removed"
fi

# 2c. Clear any local runner configuration. --replace only resolves a server-side
#     name collision; a leftover .runner file makes config.sh refuse outright with
#     "Cannot configure the runner because it is already configured".
if [ -f "${RUNNER_DIR}/.runner" ]; then
  echo "[..] Clearing previous local runner configuration"
  rm -f "${RUNNER_DIR}/.runner" \
        "${RUNNER_DIR}/.credentials" \
        "${RUNNER_DIR}/.credentials_rsaparams"
  echo "[OK] Previous local configuration cleared"
fi

# 3. Download runner package
RUNNER_PKG="actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
RUNNER_URL="https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${RUNNER_PKG}"

mkdir -p "$RUNNER_DIR"
chown "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR"
cd "$RUNNER_DIR"

if [ ! -s "$RUNNER_PKG" ]; then
  echo "[..] Downloading runner v${RUNNER_VERSION} ..."
  # Download as root (writes reliably regardless of directory ownership), then
  # hand the tarball to the runner user before extracting.
  curl -fL --retry 3 --retry-delay 2 -o "${RUNNER_DIR}/${RUNNER_PKG}" "$RUNNER_URL"
  chown "$RUNNER_USER:$RUNNER_USER" "${RUNNER_DIR}/${RUNNER_PKG}"
fi
sudo -u "$RUNNER_USER" tar xzf "$RUNNER_PKG"
echo "[OK] Runner v${RUNNER_VERSION} extracted to $RUNNER_DIR"

# 4. Get registration token
echo ""
echo "======================================================="
echo "  You need a GitHub registration token."
echo "  Go to: $REPO_URL/settings/actions/runners/new"
echo "  Copy the token shown under 'Configure' step."
echo "======================================================="
read -rp "Paste registration token: " REG_TOKEN

# The GitHub UI shows a full "./config.sh --url ... --token XXXX" line, so accept
# either the bare token or that whole command pasted in.
if printf '%s' "$REG_TOKEN" | grep -q -- '--token'; then
  REG_TOKEN=$(printf '%s' "$REG_TOKEN" | sed -E 's/.*--token[[:space:]]+([A-Za-z0-9]+).*/\1/')
  echo "[OK] Extracted token from the pasted command line"
fi
REG_TOKEN=$(printf '%s' "$REG_TOKEN" | tr -d '[:space:]')

if ! printf '%s' "$REG_TOKEN" | grep -qE '^[A-Za-z0-9]{20,}$'; then
  echo "[ERR] That does not look like a registration token."
  echo "      Paste only the value after --token from:"
  echo "      $REPO_URL/settings/actions/runners/new"
  exit 1
fi

# 5. Configure the runner (--replace takes over an existing registration of the
#    same name, so re-running this after a crash-looping runner just works)
sudo -u "$RUNNER_USER" ./config.sh \
  --url "$REPO_URL" \
  --token "$REG_TOKEN" \
  --name "$(hostname)" \
  --labels "$RUNNER_LABEL" \
  --work "_work" \
  --replace \
  --unattended

echo "[OK] Runner configured with label: $RUNNER_LABEL"

# 6. Install as systemd service
./svc.sh install "$RUNNER_USER"
./svc.sh start
echo "[OK] Runner service installed and started"

# Note: do NOT add a systemd Restart= directive to this service. The runner exits
# deliberately ("no retry needed") when its registration is invalid, and restarting
# it in a loop only hides that. Re-run this script to re-register instead.

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
