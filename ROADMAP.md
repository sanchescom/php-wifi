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
3.3.0 below.

## 3.2.5 — hardening

No API changes.

- **Runtime files out of `/tmp`.** The `hostapd`/`dnsmasq` pid files and the
  watchdog's state file live at fixed names in a world-writable directory,
  written by root, so a planted symlink can make root overwrite any file.
  Move them to `/run/php-wifi/` (0700), with `RuntimeDirectory=` in the
  `wifi watch` unit.
- **Stale watchdog state and the pid-file race.** `wifi hotspot stop` leaves
  the watchdog's state file behind, so the watchdog can tear down a hotspot the
  provisioning demo raised on its first idle tick. A stop followed at once by a
  start can let the old `hostapd` delete the new one's pid file.
- **`NmcliBackend` deletes a profile by name**, which can remove another
  profile with the same name. Delete by UUID instead.
- **`wifi forget` on an unknown network** says "was not found in the scan";
  saved networks are not scanned. Give it its own message.
- **A CLI test for the `hotspot_busy: cannot count hotspot stations` log line.**
- **CI: `actions/checkout@v5`.** v4 runs on the deprecated Node 20.
- **Live run:** the provisioning demo on `WpaCliBackend`, the last Linux item
  under "Not verified" in docs/verified-on.md.

## 3.3.0 — `wifi provision`

One command turns a headless Linux device into one you set up from a phone.
The device raises a setup hotspot and shows a QR code to join it, and the
phone opens the setup page on its own (captive portal). The page lists the
networks and says plainly why a join failed — wrong passphrase, network not
found, no address from the router. On success the device joins its network,
is reachable as `<name>.local`, and the watchdog keeps it there. This turns
`examples/provision`, which has to be assembled by hand today, into a product
feature.

- Captive portal: `dnsmasq` answers every DNS query with the device, and a
  small HTTP server redirects the phones' connectivity checks
  (`generate_204`, `hotspot-detect.html`) to the setup page.
- Typed failure reasons (`WrongPassphrase` and friends) from
  `wpa_supplicant`'s events and `nmcli`'s replies — useful to every caller of
  `connect()`, not only the setup page.
- A QR code (`WIFI:T:WPA;S:…;P:…;;`) for the setup hotspot, as terminal
  output.
- `<name>.local` through avahi.
- The setup page is built from reusable parts: framework-free request
  handlers and JSON endpoints (scan, connect, status) that any PHP app can
  mount behind its own authentication. `wifi provision` is one app built from
  them, not the only one.
- Foundation in the same release:
  - **`hostapd` channel and country code.** Today the channel is fixed (6 for
    2.4 GHz, 36 for 5 GHz) and no country code is set; the Pi runs in world
    regulatory mode (`country 00`).
  - **Argv-free hotspot passphrase for `nmcli`.** `NmcliBackend::startHotspot()`
    still passes it as an argument — `nmcli` has no stdin mode for that
    subcommand. A keyfile under `/etc/NetworkManager/system-connections/`,
    the same shape as `Backend\Windows\ProfileFile` and `HostapdConfig`,
    closes it.
- Risks to settle in the spec:
  - A single radio cannot hold the hotspot and join the network at once, so
    the phone loses the page at the moment of success. The page has to tell
    the user where to go next, and bring the hotspot back with the reason if
    the join fails.
  - Captive-portal detection differs between iOS and Android; only a live run
    with real phones settles it.

## 3.4.0 — `wifi doctor` and events

- **`wifi doctor`**: explains why a device cannot get online, with the fix for
  each finding. Checks: rfkill block, unset country code, missing `netdev`
  membership, NetworkManager and `wpa_supplicant` fighting over the radio,
  wrong passphrase, no DHCP lease, weak signal. Each one comes from something
  the 3.2 live runs actually hit.
- **Events**: `WiFi::onEvent()` and `wifi events` — connected, disconnected,
  roamed, signal dropped — through `wpa_cli -a` and `nmcli monitor`.
- **An example with [femus](https://github.com/femus/femus)**: a button held
  for three seconds starts `wifi provision`, and an LED shows the state —
  blinking while the hotspot is up, solid when connected, fast on a failure.
  php-wifi does not depend on femus; the example only listens to the events.

## 3.5.0 — `EspAtBackend` (experimental)

php-wifi through an ESP8266 (ESP-01S) running AT firmware over a serial port.
The AT commands map almost one to one onto `Backend`: `AT+CWLAP` (scan, with
real SSIDs, BSSIDs, RSSI and channel), `AT+CWJAP`/`AT+CWQAP`
(connect/disconnect), `AT+CWSAP` (hotspot) and `AT+CWLIF` (attached stations,
for the watchdog).

- **Real SSIDs on macOS**, through a USB-TTL adapter — without the signed
  CoreWLAN helper this page declines below.
- **Wi-Fi for devices without Linux**, such as femus projects on an Arduino.
- **A second radio for a Pi**, so the setup hotspot can stay up while `wlan0`
  joins the network.
- Caveat: the module joins the network, not the computer it is attached to —
  a different meaning of "connected", to be named clearly in the API and the
  docs.
- First step: read the module's firmware version (`AT+GMR`); ESP-01S boards
  often ship an old AT firmware that needs reflashing.

## On demand

Not scheduled until someone asks for them.

- **WPA3/SAE on either Linux backend.** Neither `NmcliBackend`'s hotspot nor
  `WpaCliBackend` (`connect()` or its `HostapdConfig` hotspot) offers
  anything beyond WPA2-PSK.
- **IPv6 configuration.** Both Linux backends only ever configure IPv4 — DHCP
  after `WpaCliBackend::connect()` associates, and the fixed `10.42.0.0/24`
  hotspot subnet on both backends.

## Declined

- **macOS CoreWLAN helper.** The only way to get real SSIDs and BSSIDs on
  macOS 14+ is a small signed Swift helper that talks to CoreWLAN and returns
  JSON; that is a different, platform-specific project. php-wifi ships the
  best `system_profiler` can do and says so. 3.5.0's `EspAtBackend` offers
  real SSIDs on macOS through hardware instead.
- **A web dashboard.** A permanent web UI that can change Wi-Fi needs root
  and becomes the device's biggest attack surface: authentication, CSRF,
  HTTPS and rate limiting make it a product of its own. RaspAP and Cockpit
  already do this. php-wifi's web surface is the setup page, which lives only
  while the setup hotspot is up, plus the reusable handlers 3.3.0 ships. A
  full device dashboard, with Wi-Fi as one panel among sensors and relays,
  belongs in [femus](https://github.com/femus/femus).
- **CLI split.** `bin/wifi` pulls `splitbrain/php-cli` and
  `phplucidframe/console-table` into every install of the library. Splitting
  it into its own package would remove that, but no user has asked, and it
  would cost a second release process for a low-traffic CLI.
