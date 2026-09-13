# Keeping a headless device joined, unattended

A small systemd unit around `wifi watch` (see the CLI reference in the
project's main README.md): it keeps a headless Raspberry Pi joined to a
known network and, whenever it cannot, raises a provisioning hotspot so a
phone can fix it — without anyone SSHing in. Where `examples/provision/`
gets a device onto Wi-Fi the first time, this keeps it there afterwards,
tick after tick, for as long as the device runs.

## Install

`examples/` is `export-ignore`d from the package tarball, so a `composer
require` install will not have it. Clone the repository instead:

```bash
sudo git clone https://github.com/sanchescom/php-wifi.git /opt/php-wifi
cd /opt/php-wifi
composer install --no-dev
```

## Configure

Create the environment file the unit reads:

```bash
sudo tee /etc/php-wifi-watch.env >/dev/null <<'EOF'
WATCH_HOTSPOT_SSID=femus-setup
WATCH_HOTSPOT_PASSWORD_FILE=/etc/php-wifi-watch.passphrase
WATCH_INTERVAL=30
WATCH_RETRY=300
EOF
sudo chmod 600 /etc/php-wifi-watch.env
```

`WATCH_HOTSPOT_PASSWORD_FILE` names a *file*, never the passphrase itself:
`wifi watch` has no inline `--hotspot-password` option at all (unlike
`wifi hotspot start`), on purpose — an env file lives in
`/proc/<pid>/environ`, readable by the unit's own user, but argv is
readable by *any* local user via `ps`. Write the actual passphrase (8–63
characters, WPA2's own limit) to the file the variable above points at and
lock it down the same way:

```bash
sudo tee /etc/php-wifi-watch.passphrase >/dev/null <<'EOF'
change-me-please
EOF
sudo chmod 600 /etc/php-wifi-watch.passphrase
```

`--ssid` (which known network to keep rejoining; default: any known
network, tried in order) and `--device` (which radio to pin; default:
auto-detected) are deliberately left out of `ExecStart=` above: systemd
expands an unset `${VAR}` to an empty string, and an *empty* `--ssid=` is
not the same as *omitting* `--ssid` — it is rejected outright as an empty
SSID. If either is needed, add it directly to the `ExecStart=` line in
`wifi-watch.service` (a literal value, not a third environment variable)
before installing the unit.

### Required tools

Whichever Linux backend `wifi watch` resolves to (`nmcli` or `wpa_cli`),
every tool it spawns is resolved to its absolute path through
`Backend\Linux\ToolPath` before it runs, because an unprivileged user's
`PATH` on Debian/Raspberry Pi OS omits `/usr/sbin`, where most of these
live — see the "Privileges" sections of the main README.md. Install
whichever set applies:

| Backend | Needs |
|---|---|
| `NmcliBackend` | `nmcli`, plus `iw` (this unit's own station counting during a busy hotspot) |
| `WpaCliBackend` | `wpa_cli`, `iw`, `ip`, `hostapd`, `dnsmasq`, and ideally a DHCP client (`dhcpcd`, `udhcpc` or `dhclient`) |

A missing or unresolvable `iw` does not make the watchdog tear a hotspot
down early — see "What a missing `iw` means" below — but it does mean the
"someone attached" check never clears: the hotspot stays up, past
`--retry`, for as long as `iw` is missing. Each such tick is logged as
`hotspot_busy: cannot count hotspot stations: …`, and `iw` is looked up
again on every tick, so installing it takes effect without a restart.

This unit is written for the Linux backends. It refuses to start on macOS
or Windows (`wifi watch` requires `SupportsHotspot`, which
`NetworksetupBackend`/`NetshBackend` do not implement) — that is by
design, not a bug to work around here.

### Privileges

`wifi watch` may raise a hotspot at any tick, and raising one always needs
root — flushing and re-addressing the interface (`ip addr`), and binding
`hostapd`/`dnsmasq` to it — regardless of backend. This unit therefore runs
as root (no `User=` line); see the main README.md's "Linux — Privileges"
sections if a lower-privilege setup for the non-hotspot ticks is
worthwhile in your case.

## Run

```bash
sudo cp /opt/php-wifi/examples/watch/wifi-watch.service /etc/systemd/system/wifi-watch.service
sudo systemctl daemon-reload
sudo systemctl enable --now wifi-watch
```

Follow it live with `journalctl -u wifi-watch -f`: every tick other than an
uneventful "still connected" is logged one line at a time (a hotspot going
up, staying busy, or a failure — with its message).

## Restart=always, and what it is no longer needed for

`Restart=always` with `RestartSec=10`, and `StartLimitIntervalSec=0` in
`[Unit]` — the only section systemd reads it from — (no cap
on how many times systemd will restart it, ever — this device has no one
around to run `systemctl reset-failed`) is the unit's own safety net for
the process dying outright: an out-of-memory kill, a PHP fatal error, the
device itself rebooting.

It is deliberately *not* the safety net for an ordinary failed tick
anymore. Earlier in 3.2.0's life, `Watchdog::tick()` only caught its own
`WiFiException` hierarchy; a plain `RuntimeException` — e.g.
`HostapdConfig::create()` failing to write its temp file — escaped tick(),
past `run()`, into the CLI's outer `catch (Throwable)`, and exited the
process. Under this unit that was survivable (a restart 10 seconds later),
but it meant every unmapped error cost a full process restart instead of
just the one tick it actually broke. `tick()` now catches `Throwable` and
reports `WatchdogState::Failed` (message available via `lastError()`) for
any of it, so the loop itself keeps running; `Restart=always` is here for
the failures a `catch` block cannot help with, not routine ones.

## What a missing `iw` means

`Watchdog::countHotspotStations()` resolves `iw` through the same
`ToolPath` every backend tool goes through, rather than spawning a bare
`iw` that fails outright on the unprivileged `PATH` this whole release
exists to fix. Either `iw` cannot be resolved, or the `station dump` call
itself fails: both are read as "cannot tell whether anyone is attached",
never as "nobody is attached". `WatchdogConfig::$requireIdleHotspot`
defaults to true (there is no CLI flag for it yet — this unit always gets
the default) and, at that default, "cannot tell" is treated the same as
"someone is attached": the hotspot is left alone rather than torn down.
That is the safe direction, since the alternative (reading a failed count
as zero) would drop a phone mid-provisioning to retry a network nobody
asked to retry yet. It does *not* block `--retry`-based teardown for a
caller who constructs `Watchdog` with `requireIdleHotspot: false` directly
(this unit does not); that path never looked at the station count in the
first place.

## Surviving a clock that steps backwards

A Raspberry Pi has no RTC: its clock starts each boot wherever it last
stood and is corrected by NTP later — sometimes backwards, while this unit
is already running with a hotspot up. `--retry` counts from the moment the
hotspot went up, so a clock stepped back behind that moment would leave an
elapsed time that never reaches `--retry`. `Watchdog` treats that as "the
hotspot went up just now" and restarts the countdown from the corrected
clock, so the real network is still retried.

The raise time is also persisted, but only matters when a restarted process
finds the hotspot still up; after a reboot the hotspot is gone, and the
countdown starts afresh when it is raised again.
