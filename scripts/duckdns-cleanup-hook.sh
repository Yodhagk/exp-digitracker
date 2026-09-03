#!/usr/bin/env bash
# Certbot --manual-cleanup-hook — clears the DuckDNS TXT record after validation.
set -euo pipefail
: "${DUCKDNS_TOKEN:?Set DUCKDNS_TOKEN before running certbot}"
: "${DUCKDNS_DOMAIN:?Set DUCKDNS_DOMAIN (the subdomain only, e.g. 'digitracker')}"

curl -fsS "https://www.duckdns.org/update?domains=${DUCKDNS_DOMAIN}&token=${DUCKDNS_TOKEN}&txt=removed&clear=true" -o /dev/null
echo "[OK] TXT record cleared"
