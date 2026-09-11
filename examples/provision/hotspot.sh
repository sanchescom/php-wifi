#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

CACHE="${PROVISION_CACHE:-/run/php-wifi-provision/networks.json}"
DONE="${PROVISION_DONE:-/run/php-wifi-provision/done}"
TIMEOUT="${PROVISION_TIMEOUT:-900}"
RESTART_BACKOFF="${RESTART_BACKOFF:-5}"
MAX_RESTARTS="${MAX_RESTARTS:-5}"
: "${PROVISION_PASSWORD:?set PROVISION_PASSWORD}"

mkdir -p "$(dirname "$CACHE")" "$(dirname "$DONE")"
rm -f "$DONE"

# Scan before the radio becomes an access point: with one radio it can do
# either, never both.
"$ROOT/bin/wifi" list --unique --json > "$CACHE" || printf '[]' > "$CACHE"

SERVER=""

teardown() {
    [ -n "$SERVER" ] && kill "$SERVER" 2>/dev/null || true
    "$ROOT/bin/wifi" hotspot stop || true
    trap - EXIT INT TERM
}
trap teardown EXIT INT TERM

# Keeps the passphrase out of argv (and therefore out of `ps`); it stays in
# the pipe all the way down to --password-file=-.
start_hotspot() {
    printf '%s' "$PROVISION_PASSWORD" | "$ROOT/bin/wifi" hotspot start \
        --ssid="${PROVISION_SSID:-femus-setup}" \
        --password-file=-
}

# A single radio cannot run the access point and attempt a client join at
# the same time, so NetworkManager tears the hotspot down to try a join and,
# on a wrong passphrase, leaves the radio disconnected instead of restoring
# it. This tells the wait loop the AP is gone so it can bring it back.
hotspot_active() {
    [ "$("$ROOT/bin/wifi" hotspot status)" = 'active' ]
}

if ! start_hotspot; then
    echo 'provision: could not start the hotspot (is the polkit rule installed?)' >&2
    exit 0
fi

php -d display_errors=0 -d log_errors=1 -d expose_php=0 -S 0.0.0.0:8080 -t "$SCRIPT_DIR" &
SERVER=$!

sleep 1
kill -0 "$SERVER" 2>/dev/null || { echo 'provision: web server failed to start' >&2; exit 0; }

waited=0
restarts=0
while [ ! -e "$DONE" ] && [ "$waited" -lt "$TIMEOUT" ]; do
    kill -0 "$SERVER" 2>/dev/null || break

    # The marker wins over the hotspot status: a successful join tears the
    # AP down too, and that must end the loop, never trigger a restart.
    if [ ! -e "$DONE" ] && ! hotspot_active; then
        if [ "$restarts" -ge "$MAX_RESTARTS" ]; then
            echo "provision: hotspot would not stay up after $MAX_RESTARTS restart(s), giving up" >&2
            break
        fi

        echo "provision: $(date '+%Y-%m-%dT%H:%M:%S%z') the hotspot went down (a failed join?), restarting it" >&2

        if start_hotspot; then
            restarts=0
        else
            restarts=$((restarts + 1))
        fi

        sleep "$RESTART_BACKOFF"
        waited=$((waited + RESTART_BACKOFF))
        continue
    fi

    sleep 1
    waited=$((waited + 1))
done

# Always zero: Restart=on-failure must not resurrect a finished run.
exit 0
