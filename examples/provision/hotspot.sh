#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

CACHE="${PROVISION_CACHE:-/run/php-wifi-provision/networks.json}"
DONE="${PROVISION_DONE:-/run/php-wifi-provision/done}"
TIMEOUT="${PROVISION_TIMEOUT:-900}"
: "${PROVISION_PASSWORD:?set PROVISION_PASSWORD}"

mkdir -p "$(dirname "$CACHE")" "$(dirname "$DONE")"
rm -f "$DONE"

# Scan before the radio becomes an access point: with one radio it can do
# either, never both.
"$ROOT/bin/wifi" list --unique --json > "$CACHE" || printf '[]' > "$CACHE"

printf '%s' "$PROVISION_PASSWORD" | "$ROOT/bin/wifi" hotspot start \
    --ssid="${PROVISION_SSID:-femus-setup}" \
    --password-file=-

php -d display_errors=0 -d log_errors=1 -d expose_php=0 -S 0.0.0.0:8080 -t "$SCRIPT_DIR" &
SERVER=$!

teardown() {
    kill "$SERVER" 2>/dev/null || true
    "$ROOT/bin/wifi" hotspot stop || true
}
trap teardown EXIT INT TERM

waited=0
while [ ! -e "$DONE" ] && [ "$waited" -lt "$TIMEOUT" ]; do
    kill -0 "$SERVER" 2>/dev/null || break
    sleep 1
    waited=$((waited + 1))
done

# Always zero: Restart=on-failure must not resurrect a finished run.
exit 0
