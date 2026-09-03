#!/usr/bin/env bash
# Certbot --manual-cleanup-hook — clears the DuckDNS TXT record after validation.
set -euo pipefail
ENV_FILE="/etc/digitracker/digitracker.env"
if [ -z "${DUCKDNS_TOKEN:-}" ] && [ -r "$ENV_FILE" ]; then . "$ENV_FILE"; fi
: "${DUCKDNS_TOKEN:?DUCKDNS_TOKEN not set and not found in $ENV_FILE}"
DUCKDNS_DOMAIN="${DUCKDNS_DOMAIN:-digitracker}"

curl -fsS "https://www.duckdns.org/update?domains=${DUCKDNS_DOMAIN}&token=${DUCKDNS_TOKEN}&txt=removed&clear=true" -o /dev/null
echo "[OK] TXT record cleared"
