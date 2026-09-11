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

## 3.2 candidates

- **`iw`/`wpa_cli` backend.** A Linux fallback for images without
  NetworkManager: some minimal Raspberry Pi OS Lite installs and most
  embedded distros run `wpa_supplicant` directly, with no `nmcli` to drive.
- **Hotspot auto-fallback watchdog.** If a Raspberry Pi's regular network
  connection drops, automatically start the provisioning hotspot instead
  of requiring a manual `wifi hotspot start` over a connection that is
  already gone.
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
under "3.2 candidates" above is a scoping call the maintainer can make
independently once there is real demand for it.
