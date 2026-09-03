#!/usr/bin/env bash
# Certbot --manual-auth-hook for a DuckDNS DNS-01 challenge.
# Certbot sets $CERTBOT_VALIDATION. DUCKDNS_TOKEN/DUCKDNS_DOMAIN come from the
# environment if set (first issuance), otherwise from the server's secrets file
# — which is what makes certbot's unattended renewal work, since renewal runs
# as root from a timer with no shell exports.
set -euo pipefail
ENV_FILE="/etc/digitracker/digitracker.env"
if [ -z "${DUCKDNS_TOKEN:-}" ] && [ -r "$ENV_FILE" ]; then . "$ENV_FILE"; fi
: "${DUCKDNS_TOKEN:?DUCKDNS_TOKEN not set and not found in $ENV_FILE}"
DUCKDNS_DOMAIN="${DUCKDNS_DOMAIN:-digitracker}"

RESP=$(curl -fsS "https://www.duckdns.org/update?domains=${DUCKDNS_DOMAIN}&token=${DUCKDNS_TOKEN}&txt=${CERTBOT_VALIDATION}")
if [ "$RESP" != "OK" ]; then
  echo "[FAIL] DuckDNS TXT update returned: $RESP"
  exit 1
fi

echo "[OK] TXT record published for _acme-challenge.${DUCKDNS_DOMAIN}.duckdns.org"
echo "Waiting for DNS propagation..."
sleep 30
