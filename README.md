[![CI](https://github.com/sanchescom/php-wifi/actions/workflows/ci.yml/badge.svg)](https://github.com/sanchescom/php-wifi/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/sanchescom/php-wifi.svg)](https://packagist.org/packages/sanchescom/php-wifi)
[![PHP Version](https://img.shields.io/packagist/php-v/sanchescom/php-wifi.svg)](https://packagist.org/packages/sanchescom/php-wifi)
[![License](https://img.shields.io/packagist/l/sanchescom/php-wifi.svg)](LICENSE.md)

# PHP WiFi

A cross-platform PHP library for scanning and joining Wi-Fi networks. One
object facade (`WiFi`) drives `nmcli` or `wpa_cli` on Linux, `networksetup` on
macOS and `netsh` on Windows through a shared `Backend` interface, returns
immutable value objects instead of arrays, and ships a CLI (`bin/wifi`) on top
of the same API. Linux has two backends, picked automatically — see "Linux —
two backends" below.

On Linux it also raises a hotspot (`hostapd`/`dnsmasq` or NetworkManager) and
ships `wifi watch`, a watchdog that keeps a headless device — a Raspberry Pi
with no keyboard or screen — reachable: it rejoins the network on its own, and
raises a provisioning hotspot when it cannot, leaving it up while someone is
attached to it. Every release is verified on real hardware before it is
tagged — the transcripts, and the defects those runs found, are in
[`docs/verified-on.md`](docs/verified-on.md).

## `wifi list` on a Raspberry Pi

Run over SSH on the maintainer's Pi, unprivileged (`femus`, member of
`netdev`), NetworkManager 1.52.1 — full transcript in
[`docs/verified-on.md`](docs/verified-on.md):

```
$ php bin/wifi device
wlan0
[exit 0]

$ php bin/wifi list
1 network(s) broadcast no SSID (hidden); they are shown as "-".
 SSID             BSSID              Channel  Band  Quality  dBm  Frequency  Connected  Security
-------------------------------------------------------------------------------------------------
 BELL340          0e:ac:8a:99:58:5c  1        2.4   87%      -56  2412       false      WPA2
 VTECH_5764_9764  a6:97:5c:b7:97:64  1        2.4   70%      -65  2412       false      WPA2
 BELL340          0e:ac:8a:99:58:5d  157      5     64%      -68  5785       false      WPA2
 -                0e:ac:8a:99:58:5e  157      5     64%      -68  5785       false      WPA2
[exit 0]
```

The fourth row is a real hidden access point — an empty SSID broadcast
from `0e:ac:8a:99:58:5e`, shown as `-`. (The stderr line above is this
release's wording; `docs/verified-on.md` records the exact run that found
and fixed the earlier, OS-wrong version of it.)

## Requirements

- PHP 8.2, 8.3, 8.4 or 8.5
- `illuminate/collections` 11, 12 or 13 (pulled in automatically; the
  constraint lets the package coexist with Laravel 11–13 applications)
- Linux, macOS (Darwin) or Windows, with the matching system tool: `nmcli`
  (NetworkManager) or `wpa_supplicant`/`wpa_cli` on Linux (see "Linux — two
  backends" below), `networksetup` (built in) on macOS, `netsh` (built in) on
  Windows

## Installation

```bash
composer require sanchescom/php-wifi
```

## Quick start

### Scan and read the strongest network

```php
use Sanchescom\WiFi\WiFi;

$wifi = WiFi::create();
$strongest = $wifi->scan()->strongest();

printf("%s: %.0f dBm, %s\n", $strongest->ssid, $strongest->signal->dbm, $strongest->security->value);
```

### Connect

```php
use Sanchescom\WiFi\Value\Credentials;
use Sanchescom\WiFi\WiFi;

$wifi = WiFi::create();
$network = $wifi->scan()->bySsid('My-WiFi-Network');
$wifi->connect($network, Credentials::password('secret123'));
```

The wireless device is detected automatically; pass a third `Device`
argument to override it.

### List known (saved) networks — Linux only

```php
use Sanchescom\WiFi\WiFi;

$wifi = WiFi::create();
foreach ($wifi->knownNetworks() as $known) {
    printf("%s (%s)\n", $known->name, $known->active ? 'active' : 'saved');
}
```

On `NmcliBackend`, `$known->name` is the NetworkManager connection name;
`$known->ssid` is the real SSID, resolved with one extra `nmcli -g
802-11-wireless.ssid connection show <name>` call per profile —
`knownNetworks()` costs N+1 `nmcli` commands for N wireless profiles, not
one. The two differ for a saved hotspot profile: a connection named
`Hotspot` reports its actual SSID in `$ssid`. A failed per-profile lookup
falls back to the connection name rather than failing the whole list.

On `WpaCliBackend`, `wpa_supplicant` has no notion of a connection name
distinct from the SSID, so `$known->name` always equals `$known->ssid`, and
`$known->device` is always `null` (`wpa_supplicant`'s `list_networks` never
reports which interface owns a configured network). `$known->active`
reflects `list_networks`' `[CURRENT]` flag, which means the network is
*selected* — the one `wpa_supplicant` is trying or last used — not
necessarily *associated right now*; a network can show `active: true` while
the radio is actually disconnected from it.

### Start a hotspot — Linux only

```php
use Sanchescom\WiFi\Value\HotspotConfig;
use Sanchescom\WiFi\WiFi;

$wifi = WiFi::create();
$hotspot = $wifi->startHotspot(new HotspotConfig(ssid: 'femus-setup', password: 'change-me-now'));
printf("%s on %s\n", $hotspot->ssid, $hotspot->device);
```

Calling a capability the active backend does not implement (`known` or
`hotspot` on macOS/Windows) throws `UnsupportedOperation`; check first
with `$wifi->supports(SupportsHotspot::class)`.

## Provisioning a headless Raspberry Pi

`examples/provision/` turns a headless Pi into a Wi-Fi setup wizard: it
opens a temporary hotspot and serves a small PHP page that lets a phone
scan and join the real network — no SSH, no keyboard, no monitor. See
[`examples/provision/README.md`](examples/provision/README.md) for the
systemd unit and install steps.

![Provisioning page on a phone](examples/provision/screenshot.jpg)

This was taken live on the maintainer's Pi during the 3.0 release, a phone
joined the `femus-setup` hotspot and opened `http://10.42.0.1:8080/`. At the
time the list showed only `femus-setup · 2.4 GHz · 0%`, because while
`nmcli` is running the hotspot on the Pi's one radio, `nmcli device wifi
list` can see the hotspot itself and nothing else — 3.1 fixes this with a
cached scan (below).

A single Wi-Fi radio can either scan or run an access point, never both, so
`hotspot.sh` scans first (`wifi list --unique --json`) and caches the result
to `PROVISION_CACHE` *before* starting the hotspot. `index.php` reads that
cache on every `GET` and labels the list "Scanned before the hotspot
started."; a missing or unreadable cache falls back to a live scan.

Pressing Connect stops the hotspot before joining: while the radio is
running the access point, NetworkManager's own scan list is empty, so it
refuses a join outright no matter what the cached list shows. The phone
loses the page at that point — expected and unavoidable with one radio —
and `index.php` waits a moment for the radio to leave AP mode, then joins
through `WiFi::connect()`, which scans again to resolve the SSID (turning a
typo into `NetworkNotFound` instead of an opaque `nmcli` exit code).

Once a connection succeeds, `index.php` writes an empty marker file at
`PROVISION_DONE` and the run ends with the hotspot down. `hotspot.sh` polls
for that marker once a second (every `RESTART_BACKOFF` seconds while it is
restarting a dropped hotspot), for at most `PROVISION_TIMEOUT` seconds (also
giving up if the web server dies), then stops the built-in PHP server, runs
`wifi hotspot stop` (a no-op by then), and exits — the unit ends itself once
provisioning is done instead of running indefinitely. If the join fails
instead, the hotspot is left down; `hotspot.sh`'s wait loop notices within a
few seconds and brings it back so the phone can rejoin and the person can
retry.

A mistyped passphrase drops the access point without ever delivering an HTTP
response, so `index.php` also writes the outcome of every submitted attempt
to `PROVISION_STATE` — `{"at": <unix time>, "ssid": …, "ok": false, "error":
"…"}` — and shows "Last attempt: … failed — &lt;reason&gt;" the next time the
page loads. This is the only way a person learns why their attempt failed
once the page itself is unreachable; the record is ignored (and the page
falls back to no banner) once it is more than an hour old, or timestamped in
the future. The passphrase is never part of it.

| Variable | Default | Meaning |
| --- | --- | --- |
| `PROVISION_CACHE` | `/run/php-wifi-provision/networks.json` | Where the pre-hotspot scan is cached as JSON. |
| `PROVISION_DONE` | `/run/php-wifi-provision/done` | Marker file created once a connection succeeds. |
| `PROVISION_STATE` | `/run/php-wifi-provision/last-attempt.json` | Where the outcome of the last connection attempt is recorded as JSON. |
| `PROVISION_TIMEOUT` | `900` | Seconds `hotspot.sh` waits for `PROVISION_DONE` before giving up. |
| `RESTART_BACKOFF` | `5` | Seconds to wait between attempts to bring a dropped hotspot back. |
| `MAX_RESTARTS` | `5` | Consecutive failed hotspot restarts before `hotspot.sh` gives up (still exiting `0`). |

See [`examples/provision/README.md`](examples/provision/README.md) for the
full install steps and the `provision.service` unit that provisions
`/run/php-wifi-provision` for these two files.

## Design

**Pure parsers over real fixtures.** Every `src/Parser/**` class is a pure
function: tool output in, a `list<Network>` (or a `Device`, or a list of
`KnownNetwork`) out. No shell calls, no state. Every parser is tested
against real `nmcli --terse`, `system_profiler` and `netsh` output captured
from actual machines, not hand-written approximations of it.

**`Command` owns escaping, and nowhere else does.** `src/Shell/Command.php`
is the only place a program, its arguments and its environment are
rendered into a shell string — `escapeshellarg()` on Linux and macOS,
cmd-safe quoting on Windows. Backends never build a shell string
themselves; they build a `Command` and hand it to a `CommandRunner`.

**`null` over sentinels.** 2.x reported an unreadable signal as `-100 dBm`
/ `0%` and an unrecognised channel as `frequency === 0` — both
indistinguishable from a real (if extreme) reading. 3.0 reports `null`:
`$network->signal`, `$network->channel`, `$network->band` and
`$network->frequency` are all nullable, and nothing else is overloaded to
mean "unknown".

**Capability interfaces, not silent no-ops.** Known-networks and hotspot
support are declared as `SupportsKnownNetworks` and `SupportsHotspot`
interfaces, implemented by both Linux backends (`NmcliBackend` and, since
3.2, `WpaCliBackend`) and neither macOS nor Windows backend. Calling
`knownNetworks()` or `startHotspot()` against a `Backend` that does not
implement the relevant interface throws `UnsupportedOperation` immediately,
instead of returning an empty list or silently doing nothing.

## Linux — two backends

Linux has two backends behind the one `WiFi` facade:

- **`NmcliBackend`** drives NetworkManager's `nmcli`. Full parity, and the
  only Linux backend before 3.2.
- **`WpaCliBackend`** drives `wpa_supplicant` directly through `wpa_cli`, for
  machines with no NetworkManager at all — minimal Raspberry Pi OS Lite
  images and most embedded distributions run `wpa_supplicant` directly and
  have no `nmcli` to drive. It has the same parity: scan, connect (including
  asking a DHCP client for an address once associated), disconnect, known
  networks, forget, and a hotspot — through `hostapd` + `dnsmasq` instead of
  `nmcli`'s built-in one.

`WiFi::create()` picks between them automatically, through
`BackendFactory::forLinux()`: it runs one cheap probe, `nmcli -t -f RUNNING
general` (with `LANG=C`), and returns `NmcliBackend` when it exits `0` and
prints `running`, `WpaCliBackend` otherwise — a missing `nmcli` binary, a
stopped NetworkManager, or any other failure. The probe never throws: a
failed probe is itself the answer ("no usable NetworkManager here"), not an
error. `BackendFactory::forOs(Os::Linux, …)` is unchanged and always returns
`NmcliBackend`, so code and tests that build a backend directly for a fixed
OS are unaffected; only `forCurrentOs()` (and therefore `WiFi::create()`)
routes Linux through the probe.

```php
use Sanchescom\WiFi\Backend\BackendFactory;
use Sanchescom\WiFi\Shell\ShellCommandRunner;

$backend = BackendFactory::forLinux(ShellCommandRunner::forCurrentOs());
// NmcliBackend when NetworkManager is running, WpaCliBackend otherwise.
```

### What `WpaCliBackend` needs installed

| Tool | Used for | Required? |
| --- | --- | --- |
| `wpa_supplicant` / `wpa_cli` | Everything — scan, connect, disconnect, known networks, forget | Always |
| `iw` | Device detection (`iw dev`); `wifi watch`'s station count (`iw dev <iface> station dump`) | Always |
| A DHCP client — `dhcpcd`, `udhcpc`, or `dhclient` | Getting an address after `connect()` associates | Recommended — `connect()` still succeeds without one, but the interface is then associated with no address; something else (a static configuration, `systemd-networkd`) has to address it |
| `hostapd` | The hotspot access point | Only for `startHotspot()` / `stopHotspot()` / `isHotspotActive()` |
| `dnsmasq` | DHCP for hotspot clients | Only for the hotspot |

Every tool above is spawned by its absolute path, resolved with `which`
against a fixed search path that includes `/usr/local/sbin`, `/usr/sbin` and
`/sbin` (`Backend\Linux\ToolPath`) — directories absent from an unprivileged
user's `PATH` on Debian and Raspberry Pi OS, where `proc_open()` resolves a
bare program name using PHP's own `PATH`, never the `Command`'s own `$env`.
This was found live on the Pi: `proc_open(["wpa_cli", "-v"], …)` failed while
`["/sbin/wpa_cli", "-v"]` exited `0` — see
[`docs/verified-on.md`](docs/verified-on.md).

### Privileges — `WpaCliBackend`

`docs/verified-on.md`'s 3.2.0 run drove every `WpaCliBackend` command —
`list`, `connect`, `disconnect`, `forget`, `hotspot start`/`status`/`stop` —
under `sudo`; that is the only privilege level this release measured.
`PermissionDenied::fromWpaCli()`'s own hint names an alternative that was
not exercised this release: a `wpa_supplicant` whose `ctrl_interface` config
line grants a `GROUP` (e.g. `netdev`) lets a member of that group talk to
the control socket without root. Raising the hotspot needs root regardless
of that setting — `startHotspot()` flushes and assigns the interface's IP
address (`ip addr`) and binds `hostapd` and `dnsmasq` to it, all privileged
operations — and so do most DHCP clients when asked to configure an
interface.

### Two behaviour changes worth knowing about

- **`connect()` with a passphrase now replaces an existing NetworkManager
  profile for that SSID** (`NmcliBackend`). Measured on the Pi: `nmcli --ask`
  only prompts for a secret when it has none of its own already, so a saved
  profile holding a stale passphrase made `nmcli` connect with that stored
  secret and silently ignore a freshly typed, corrected one. `connect()` now
  runs `nmcli connection delete <ssid>` first when a passphrase is given, so
  the new passphrase actually wins — at the cost of any other setting that
  profile held, such as a static address or `autoconnect=no`.
- **`KnownNetwork::$active` means something different on the two Linux
  backends.** On `NmcliBackend` it reflects `nmcli`'s own idea of the active
  connection. On `WpaCliBackend` it is `wpa_supplicant`'s `[CURRENT]` flag
  from `list_networks`, which means the network is *selected* — the one
  `wpa_supplicant` is trying or already used — not necessarily *associated*
  right now. A network can show `active: true` while the radio is actually
  disconnected from it.

## Platform matrix

| Capability | Linux (`nmcli`) | Linux (`wpa_cli`) | macOS (`networksetup`) | Windows (`netsh`) |
| --- | --- | --- | --- | --- |
| scan | ✅ verified live (NetworkManager 1.52.1) | ✅ verified live (NetworkManager stopped, `wpa_supplicant` driving the radio directly) | ✅ verified live — SSIDs come back redacted unless the process running PHP has Location Services; BSSIDs are never reported for other networks | ✅ tests only — no Windows machine |
| connect | ✅ verified live | ✅ verified live, including DHCP (`dhcpcd`) | ✅ tests only — not run against real hardware in 3.0 | ✅ tests only |
| disconnect | ✅ verified live | ✅ verified live | ✅ tests only — not run against real hardware in 3.0 | ✅ tests only |
| detect (`device()`) | ✅ verified live | ✅ verified live (implicit — no `--device` was passed in any 3.2.0 run, so `iw dev` resolved it every time) | ✅ verified live | ✅ tests only |
| known networks | ✅ verified live | `forget()` ✅ verified live; `knownNetworks()` (`wifi known`) — tests only, not shown in the 3.2.0 run | ❌ throws `UnsupportedOperation` | ❌ throws `UnsupportedOperation` |
| hotspot | ✅ verified live | ✅ verified live (`hostapd` + `dnsmasq`) | ❌ throws `UnsupportedOperation` | ❌ throws `UnsupportedOperation` |

See [`docs/verified-on.md`](docs/verified-on.md) for the raw commands
behind every "verified live" cell above. `wifi watch` (Linux only, either
backend) was verified live in three of its five states — see the CLI
reference below.

On Windows, a 6 GHz network is only reported as `Band::GHz6` when `netsh`
prints a `Band :` field for it (Windows 11 22H2 and later); on older
builds it falls back to a channel-derived guess.

## Linux — Privileges (polkit)

`nmcli device wifi connect` asks NetworkManager to create and activate a
connection. A user logged in at the console is allowed by default; a PHP
process running as `www-data` (PHP-FPM, a queue worker, cron) is not — it
fails with *Not authorized to control networking* or *Insufficient
privileges*. Grant it the three NetworkManager actions this library uses:

```js
// /etc/polkit-1/rules.d/50-php-wifi.rules
polkit.addRule(function (action, subject) {
    if (subject.user === "www-data" &&
        (action.id === "org.freedesktop.NetworkManager.network-control" ||
         action.id === "org.freedesktop.NetworkManager.wifi.scan" ||
         action.id === "org.freedesktop.NetworkManager.settings.modify.system")) {
        return polkit.Result.YES;
    }
});
```

On systems whose polkit still uses `localauthority` (Debian 11 and older):

```ini
# /etc/polkit-1/localauthority/50-local.d/php-wifi.pkla
[php-wifi]
Identity=unix-user:www-data
Action=org.freedesktop.NetworkManager.network-control;org.freedesktop.NetworkManager.wifi.scan;org.freedesktop.NetworkManager.settings.modify.system
ResultAny=yes
ResultInactive=yes
ResultActive=yes
```

> [!NOTE]
> On Debian and Raspberry Pi OS, adding the user to the `netdev` group
> (`adduser www-data netdev`) grants only `settings.modify.system`; the
> connect call still needs `network-control`, so use one of the rules above.

**Live finding (Raspberry Pi, Debian 13, 2026-09-09/10):** `netdev`
membership alone lets an unprivileged process scan
(`org.freedesktop.NetworkManager.wifi.scan`) and add a connection profile
(`settings.modify.system`), but not activate one
(`network-control`). Concretely: `wifi hotspot start` and `wifi connect`
called without `network-control` are refused with `PermissionDenied` — but
the connection profile NetworkManager creates on the way to activation is
not rolled back, so a refused call still leaves a profile behind. Running
the same command once unprivileged (refused) and once with `sudo`
(succeeds) therefore creates two profiles with the same name; `wifi forget
<name>` removes one profile per call, so a duplicate needs two calls.

## Security

Every command a backend runs is built as a `Command` value object
(`src/Shell/Command.php`) and rendered by exactly one code path: no
backend ever concatenates a shell string itself. On Linux and macOS every
argument goes through `escapeshellarg()`; on Windows, `"`, `%`, `!` and
raw `\n`/`\r` — the five characters that can break out of a `cmd.exe`
double-quoted argument — are replaced with a space. Arguments marked as
secrets (passwords) are never shown in an exception message, a test
assertion helper, or `toDisplay()` — they print as `***`. On Windows, the
profile handed to `netsh` is written to a random file under the system
temp directory (`Backend\Windows\ProfileFile`) with the SSID and
passphrase XML-escaped, and the file is deleted as soon as `netsh` has
read it, including on failure. `CommandFailed::$command` keeps the
original `Command` with the unmasked argument list — `getMessage()` is
masked, but do not dump the exception object into logs or error pages.

Since 3.2, `ShellCommandRunner` execs with an argv array
(`proc_open($command->toArgv(), …)`) instead of a rendered shell string, so
no `/bin/sh -c` (or, on Windows, a bare `cmd` wrapper) sits between this
library and the tool it runs — one fewer process, and one fewer place a
secret could show up in `ps`. The one deliberate exception is
`NetshBackend::scan()`'s `cmd /c "chcp 65001 >nul & netsh …"` composite,
which keeps working unchanged: `cmd` is the program there, not a shell
wrapper around one. A command can also carry input for the child's stdin
(`Command::$stdin`, masked in `toDisplay()` as `<<< '***'` when
`$stdinIsSecret` is set) — the mechanism both Linux backends use below.

**Where a passphrase reaches a process's own arguments, precisely, as of
3.2.0:**

- **Neither Linux backend puts a `connect()` passphrase in any process's
  arguments.** `NmcliBackend::connect()` passes it to `nmcli --ask` on
  stdin; `WpaCliBackend::connect()` passes it to `wpa_cli`'s interactive
  stdin. Measured on the Pi during a live `connect`, sampling `ps -ww -eo
  args` for the passphrase (read from a file, so the measuring `grep`
  itself never carries the secret): `0` matches, on both backends — see
  [`docs/verified-on.md`](docs/verified-on.md). 3.1 could only make this
  claim for the `wifi` process's own argv; `nmcli` itself still carried the
  secret for the duration of that call. 3.2 closes that window on both
  Linux backends.
- **`NmcliBackend::startHotspot()` still passes the hotspot passphrase as a
  plain argument** (`nmcli device wifi hotspot … password <secret>`) —
  `nmcli` has no stdin mode for that subcommand. Closing this is a 3.3
  candidate (a keyfile, the way the Windows backend already avoids the
  equivalent problem for `netsh`); see ROADMAP.md.
- **`WpaCliBackend::startHotspot()`'s passphrase never reaches any
  process's arguments.** It is written to a `hostapd` config file
  (`Backend\Linux\HostapdConfig`, created via `tempnam()` at mode `0600`)
  and deleted once `hostapd` has read it. Measured on the Pi during a live
  hotspot: `0` matches in the same `ps` sample.
- **macOS's `NetworksetupBackend::connect()` passes the passphrase as a
  plain `networksetup` argument**, unchanged and untouched by this
  release (`networksetup -setairportnetwork <device> <ssid> <password>`).

## Verified on

[`docs/verified-on.md`](docs/verified-on.md) is the release gate for every
tag from 3.0.0 on: the raw output of `device`, `list`, `list --unique`,
`known`, `hotspot start`/`status`/`stop`, `forget`, `watch --once`, and a
real `connect` to the maintainer's own network, run on a Raspberry Pi — on
`NmcliBackend` since 3.0.0, and on `WpaCliBackend` as well since 3.2.0.

## CLI reference

Installed via Composer, the binary is `vendor/bin/wifi`; from a checkout
of this repository it is `php bin/wifi`.

| Command | Options | Description |
| --- | --- | --- |
| `list` | `--unique`, `--connected`, `--json` | Show surrounding Wi-Fi networks |
| `connect` | `--ssid=`, `--bssid=`, `--password=`, `--password-file=`, `--device=` | Connect to a network — one of `--ssid`/`--bssid` is required |
| `disconnect` | `--device=` | Disconnect from the current network |
| `device` | — | Show the detected Wi-Fi device |
| `known` | — | List known (saved) networks |
| `forget <ssid-or-name>` | — | Forget a known network; prints `Forgot <name>.` |
| `hotspot start` | `--ssid=`, `--password=`, `--password-file=`, `--band=`, `--device=` | Start a hotspot (`--band` is `2.4` or `5`) |
| `hotspot stop` | — | Stop the hotspot |
| `hotspot status` | — | Print `active` or `inactive` |
| `watch` | `--ssid=`, `--interval=`, `--retry=`, `--hotspot-ssid=`, `--hotspot-password-file=`, `--device=`, `--once` | Keep rejoining a network, raising a provisioning hotspot when it cannot (requires `SupportsHotspot`, i.e. either Linux backend) |

`--device` is optional everywhere it appears; the wireless device is
detected automatically when omitted.

An SSID or connection name that starts with `-` (e.g. `-my-network`) looks
like an option to the CLI's own parser and must be passed after a literal
`--`: `wifi forget -- -my-network`.

**Exit codes:** `0` success · `1` general error (bad usage, a command the
underlying tool rejected) · `2` `UnsupportedOperation` — the active
backend does not implement the capability (e.g. `known`/`hotspot` on
macOS or Windows) · `3` `PermissionDenied`.

**`--password-file`** reads the password from a file instead of a plain
argv value, keeping the passphrase off the `wifi` command line itself.
On Linux it is still passed to `nmcli` as an argument one process later,
so a local user watching `ps` during the call can still see it; on
Windows it never reaches a command line at all, since it goes through the
temp connection-profile file instead. `--password-file=-` reads it from
stdin instead:

```bash
printf '%s' "$PASSWORD" | wifi connect --ssid=home --password-file=-
```

Exactly one trailing newline is stripped and nothing else, so a passphrase
may legitimately end in a space. `--password` and `--password-file` are
mutually exclusive; an empty file, an empty value, a directory path, or a
tty on stdin are each rejected with a clear message and exit `1`.

**`--json`** prints one JSON object per network instead of the table;
`--unique`/`--connected` still apply first, and the hidden-SSID hint stays
on STDERR so stdout is valid JSON. Real output, trimmed to two networks,
from `WIFI_FAKE_RUNNER=tests/Fixtures/cli/linux WIFI_FAKE_OS=Linux php
bin/wifi list --unique --json`:

```json
[
    {
        "ssid": "BELL340",
        "hidden": false,
        "bssid": "02:00:00:00:00:01",
        "channel": 1,
        "band": "2.4",
        "frequency": 2412,
        "quality": 100,
        "dbm": -50,
        "security": "WPA2",
        "securityFlags": "(none) pair_ccmp group_ccmp psk",
        "connected": false
    },
    {
        "ssid": "",
        "hidden": true,
        "bssid": "02:00:00:00:00:05",
        "channel": 157,
        "band": "5",
        "frequency": 5785,
        "quality": 67,
        "dbm": -67,
        "security": "WPA2",
        "securityFlags": "(none) pair_ccmp group_ccmp psk",
        "connected": false
    }
]
```

`bssid`, `channel`, `band`, `frequency`, `quality` and `dbm` are `null` when
the platform does not report them.

### `wifi watch`

Keeps a headless device reachable. On every tick it checks whether the
device is already connected; if not, it tries to rejoin the target network
(or every known network, in order, when `--ssid` is omitted); if that fails
too, it raises a provisioning hotspot. While the hotspot is up, it counts
attached stations (`iw dev <iface> station dump`) and leaves it alone as
long as someone is attached — a person typing a passphrase must never be
dropped mid-session, a lesson from 3.1's live run. Once nobody is attached
and `--retry` seconds have passed since the hotspot went up, it tears the
hotspot down and tries the real network again. If the station count cannot
be read at all (`iw` missing, or `station dump` failing), the hotspot is
treated as occupied and left up — so install `iw`. A systemd unit is in
[examples/watch/](examples/watch/README.md).

| Option | Default | Meaning |
| --- | --- | --- |
| `--ssid=` | any known network | The SSID to keep rejoining; omit to try every known network in order |
| `--interval=` | `30` | Seconds between ticks (only used by the looping form, not `--once`) |
| `--retry=` | `300` | Seconds an idle, unattended hotspot is left up before the next retry |
| `--hotspot-ssid=` | — (required) | SSID of the hotspot to raise |
| `--hotspot-password-file=` | — (required) | Read the hotspot passphrase from a file, or from stdin with `-`; omitting it fails with "passphrase must be 8…" — `HotspotConfig` requires one, so `watch` cannot raise an open hotspot |
| `--device=` | auto-detected | Pins the interface both reconnection attempts and station counts use |
| `--once` | off | Run a single tick, print the resulting state to stdout, and exit, instead of looping forever — how the release gate checks each state on the Pi |

`watch` requires the active backend to implement `SupportsHotspot` (either
Linux backend); on macOS or Windows it exits `2` like any other unsupported
operation.

Each tick resolves to one of five states. `--once` prints it to stdout; the
looping form logs a timestamped line to STDERR on every state *change*
(`connected` is otherwise logged only the first time it is reached, not on
every uneventful tick that follows):

| State | Meaning |
| --- | --- |
| `connected` | Already joined to a client network; nothing was done |
| `recovered` | Was disconnected (or the hotspot was stopped); a join attempt succeeded |
| `hotspot_raised` | No client connection could be recovered; the hotspot is now up |
| `hotspot_busy` | The hotspot is up and either occupied or not yet due for a retry; nothing was touched |
| `failed` | An unexpected failure while acting; the loop keeps running |

Verified live on the Pi in three of these states (`docs/verified-on.md`):
disconnected with nothing known → `hotspot_raised`; hotspot up with nobody
attached → `hotspot_busy`; already connected → `connected`. `recovered` and
`failed`, and the "someone is actually attached" branch of `hotspot_busy`,
are covered by unit tests but were not exercised with real hardware this
release.

### Testing the CLI without hardware

`bin/wifi` reads two environment variables as a test hook: `WIFI_FAKE_OS`
(one of `Linux`, `Darwin`, `Windows`) selects the backend, and
`WIFI_FAKE_RUNNER` points at a directory containing a `map.json` fixture
map consumed by `FakeCommandRunner` (see `tests/Cli/CliTest.php` and
`tests/Fixtures/cli/**` for real examples). This hook requires a dev
install — `Sanchescom\WiFi\Test\Support\FakeCommandRunner` is not
autoloaded in a `composer require --no-dev` install.

## API reference

### `WiFi` facade (`Sanchescom\WiFi\WiFi`)

| Method | Returns | Description |
| --- | --- | --- |
| `WiFi::create(?CommandRunner $runner = null)` | `self` (static) | Auto-detects the OS and builds the matching backend |
| `new WiFi(Backend $backend)` | `self` | Construct directly over a given backend (tests, custom runners) |
| `scan()` | `NetworkCollection` | Scan for surrounding networks |
| `connect(Network\|string $network, Credentials $credentials, ?Device $device = null)` | `void` | Connect; a hidden/redacted `Network` throws `InvalidArgument` — pass the SSID as a string instead |
| `connectTo(string $ssid, Credentials $credentials, ?Device $device = null)` | `void` | Join `$ssid` without scanning first — for callers who already know the SSID and want to skip the scan (custom backends, scripted flows); a wrong SSID then surfaces as the backend's own `CommandFailed` rather than `NetworkNotFound` |
| `disconnect(?Device $device = null)` | `void` | Disconnect |
| `device()` | `Device` | The detected wireless device |
| `knownNetworks()` | `list<KnownNetwork>` | Saved connections (Linux only; `UnsupportedOperation` elsewhere) |
| `forget(KnownNetwork\|string $ssid)` | `void` | Remove a known network (Linux only) |
| `startHotspot(HotspotConfig $config)` | `Hotspot` | Start a hotspot (Linux only) |
| `stopHotspot()` | `void` | Stop the hotspot (Linux only) |
| `isHotspotActive()` | `bool` | Whether a hotspot is currently active (Linux only) |
| `supports(string $capabilityInterface)` | `bool` | Whether the active backend implements `SupportsKnownNetworks::class` / `SupportsHotspot::class` |
| `backend()` | `Backend` | The underlying backend instance |

### `NetworkCollection` (extends `Illuminate\Support\Collection<int, Network>`)

| Method | Returns | Description |
| --- | --- | --- |
| `bySsid(string $ssid)` | `Network` | First match, or `NetworkNotFound` |
| `byBssid(Bssid\|string $bssid)` | `Network` | First match, or `NetworkNotFound` |
| `connected()` | `static` | Only connected networks |
| `band(Band $band)` | `static` | Only networks on the given band |
| `security(Security $security)` | `static` | Only networks with the given security |
| `strongerThan(Signal\|float $threshold)` | `static` | Networks at or above a dBm threshold |
| `sortBySignal()` | `static` | Strongest first; networks with no reading last |
| `strongest()` | `Network` | The strongest network with a signal reading, or `NetworkNotFound` |
| `uniqueBySsid()` | `static` | One row per SSID (strongest radio); hidden names are never merged |
| `hasHiddenSsids()` | `bool` | Whether any network's SSID is hidden |

Every other `Illuminate\Support\Collection` method (`all()`, `first()`,
`filter()`, `map()`, …) is inherited and works normally.

> [!NOTE]
> `strongerThan()` compares `Signal->dbm`. On Linux and Windows this value
> is derived from a 0–100 quality percentage (`Signal::fromQuality()`) and
> therefore never exceeds −50 dBm, so a threshold above −50 matches
> nothing on those platforms; macOS reports a real RSSI value instead.

### `Network` (readonly)

| Property | Type | Notes |
| --- | --- | --- |
| `$ssid` | `string` | Empty when the network is hidden |
| `$ssidHidden` | `bool` | True for a hidden SSID or a redacted one (macOS without Location Services) |
| `$bssid` | `?Bssid` | Never available on macOS |
| `$channel` | `?int` | `null` when unknown |
| `$band` | `?Band` | `Band::GHz2_4` / `GHz5` / `GHz6`, or `null` |
| `$frequency` | `?int` | MHz, or `null` |
| `$signal` | `?Signal` | `->dbm` and `->quality`, or `null` when the tool reported none |
| `$security` | `Security` | Enum: `WPA3`/`WPA2`/`WPA`/`WEP`/`Open`/`Unknown` |
| `$securityFlags` | `string` | Raw flags string as the tool printed it |
| `$connected` | `bool` | |

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct, and the process for submitting pull requests to us.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [tags on this repository](https://github.com/sanchescom/php-wifi/tags).

## Authors

* **Efimov Aleksandr** - *Initial work* - [Sanchescom](https://github.com/sanchescom)

See also the list of [contributors](https://github.com/sanchescom/php-wifi/contributors) who participated in this project.

## License

This project is licensed under the MIT License — see the [LICENSE.md](LICENSE.md) file for details.

Versions up to and including 2.0.0 were published under GPL-3.0. 2.0.1 relicenses the package to MIT; the change is made by the sole author and copyright holder.
