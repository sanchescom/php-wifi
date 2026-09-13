# Roadmap

Planned work for php-wifi, in the order it should ship, and why. See
CHANGELOG.md for what has already landed.

## 2.1 — shipped

See CHANGELOG.md. Left open from the 2.1 list: nothing.

## 3.0 — shipped

See CHANGELOG.md and UPGRADE.md. The object API, value objects, known
networks, hotspot support and the provisioning demo are the 3.0 release;
nothing from the old "3.0" list on this page is left open.

## 3.1 — shipped

See CHANGELOG.md and UPGRADE.md. `WiFi::connectTo()`, `--password-file`/stdin
for the CLI, `wifi list --json`, real SSIDs for known networks, and a
provisioning demo that caches its pre-hotspot scan and stops itself are the
3.1 release; nothing from the old "3.1 candidates" list on this page is left
open.

## 3.2 — shipped

See CHANGELOG.md and UPGRADE.md. `WpaCliBackend` (the `wpa_supplicant`/
`wpa_cli` backend for NetworkManager-free machines) with automatic selection
via `BackendFactory::forLinux()`, the connect passphrase off both Linux
backends' argv, and `Watchdog`/`wifi watch` are the 3.2 release; nothing from
the old "3.2 candidates" list on this page is left open except what moves to
"3.3 candidates" below.

## 3.3 candidates

- **Argv-free hotspot passphrase for `nmcli`.** 3.2 closed this for a
  *connect* passphrase on both Linux backends, but
  `NmcliBackend::startHotspot()` still passes the hotspot passphrase as a
  plain argument — `nmcli` has no stdin mode for that subcommand — so `ps`
  can catch it for that instant. A keyfile under
  `/etc/NetworkManager/system-connections/`, the same shape
  `Backend\Windows\ProfileFile` and `WpaCliBackend`'s own `HostapdConfig`
  already use for their secrets, would close it.
- **WPA3/SAE on either Linux backend.** Neither `NmcliBackend`'s hotspot nor
  `WpaCliBackend` (`connect()` or its `HostapdConfig` hotspot) offers
  anything beyond WPA2-PSK.
- **`hostapd` channel and country-code selection.** `HostapdConfig` picks a
  fixed default channel per band (6 for 2.4 GHz, 36 for 5 GHz) with no way to
  override it or set a regulatory country code; a channel the country code
  forbids fails to start.
- **IPv6 configuration.** Both Linux backends only ever configure IPv4 — DHCP
  after `WpaCliBackend::connect()` associates, and the fixed `10.42.0.0/24`
  hotspot subnet on both backends.
- **macOS CoreWLAN helper — declined for now.** The only way to get real
  SSIDs and BSSIDs on macOS 14+ is a small signed Swift helper that talks
  to CoreWLAN and returns JSON; that is a different, platform-specific
  project. php-wifi ships the best `system_profiler` can do and says so.
- **CLI split — declined.** `bin/wifi` pulls `splitbrain/php-cli` and
  `phplucidframe/console-table` into every install of the library.
  Splitting it into its own package would remove that, but no user has
  asked, and it would cost a second release process for a low-traffic CLI.

## Decisions the maintainer has to make

The macOS question — ship the best `system_profiler` can do and say so, or
ship a signed CoreWLAN helper — was decided for 3.0 and stays decided:
best-effort, no helper, until a user actually asks for real SSIDs and
BSSIDs on macOS. No other decision is open right now; everything else
under "3.3 candidates" above is a scoping call the maintainer can make
independently once there is real demand for it.
