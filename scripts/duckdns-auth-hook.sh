#!/usr/bin/env bash
# Certbot --manual-auth-hook for a DuckDNS DNS-01 challenge.
# Certbot sets $CERTBOT_VALIDATION; DUCKDNS_TOKEN/DUCKDNS_DOMAIN must be
# exported in the shell that invokes certbot (see README.md).
set -euo pipefail
: "${DUCKDNS_TOKEN:?Set DUCKDNS_TOKEN before running certbot}"
: "${DUCKDNS_DOMAIN:?Set DUCKDNS_DOMAIN (the subdomain only, e.g. 'digitracker')}"

RESP=$(curl -fsS "https://www.duckdns.org/update?domains=${DUCKDNS_DOMAIN}&token=${DUCKDNS_TOKEN}&txt=${CERTBOT_VALIDATION}")
if [ "$RESP" != "OK" ]; then
  echo "[FAIL] DuckDNS TXT update returned: $RESP"
  exit 1
fi

echo "[OK] TXT record published for _acme-challenge.${DUCKDNS_DOMAIN}.duckdns.org"
echo "Waiting for DNS propagation..."
sleep 30
