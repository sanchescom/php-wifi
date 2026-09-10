#!/usr/bin/env bash
set -euo pipefail

# Starts a temporary Wi-Fi hotspot and serves the provisioning page on it.
# Intended to run as the `provision` systemd unit (see provision.service).

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

"$ROOT/bin/wifi" hotspot start \
    --ssid="${PROVISION_SSID:-femus-setup}" \
    --password="${PROVISION_PASSWORD:?set PROVISION_PASSWORD}"

exec php -S 0.0.0.0:8080 -t "$SCRIPT_DIR"
