# Verified on real hardware — 3.0.0

Every command below was run over SSH on the maintainer's Raspberry Pi on
**2026-09-09** (scan, hotspot, demo) and **2026-09-10** (the real `connect`) against the `3.0.0` branch (commit `de9ed2a` plus this file).
Outputs are pasted verbatim; the only edits are the masked secrets (`***`).

*Commands were run at `de9ed2a`; two later commits changed CLI wording
only: the hidden-SSID hint is now OS-aware and `forget` prints `Forgot
<name>.` — the exit codes and nmcli behaviour are unchanged.*

| | |
| --- | --- |
| Board | Raspberry Pi, `aarch64`, kernel `6.18.34+rpt-rpi-v8` |
| OS | Debian GNU/Linux 13 (trixie) |
| NetworkManager | `nmcli tool, version 1.52.1` |
| PHP | `PHP 8.4.24 (cli) (built: Jul 31 2026 05:11:11) (NTS)` |
| Test suite on the Pi | `OK (204 tests, 460 assertions)` |
| User | `femus` — member of `netdev` and `sudo`; no polkit rule installed |
| Radio before / after | `disabled` / `disabled` (restored) |

The Pi's Wi-Fi radio is normally off; it was switched on with
`sudo nmcli radio wifi on` for the session and switched off again at the end.

## Scan and device detection (unprivileged)

```
$ php bin/wifi device
wlan0
[exit 0]

$ php bin/wifi list
Network names were hidden by macOS. Grant Location Services to the process running PHP to see SSIDs.
 SSID             BSSID              Channel  Band  Quality  dBm  Frequency  Connected  Security
-------------------------------------------------------------------------------------------------
 BELL340          0e:ac:8a:99:58:5c  1        2.4   87%      -56  2412       false      WPA2
 VTECH_5764_9764  a6:97:5c:b7:97:64  1        2.4   70%      -65  2412       false      WPA2
 BELL340          0e:ac:8a:99:58:5d  157      5     64%      -68  5785       false      WPA2
 -                0e:ac:8a:99:58:5e  157      5     64%      -68  5785       false      WPA2
[exit 0]

$ php bin/wifi list --unique
 SSID             BSSID              Channel  Band  Quality  dBm  Frequency  Connected  Security
-------------------------------------------------------------------------------------------------
 BELL340          0e:ac:8a:99:58:5c  1        2.4   87%      -56  2412       false      WPA2
 VTECH_5764_9764  a6:97:5c:b7:97:64  1        2.4   70%      -65  2412       false      WPA2
 -                0e:ac:8a:99:58:5e  157      5     64%      -68  5785       false      WPA2
[exit 0]

$ php bin/wifi known
 Name  SSID  Device  Active
----------------------------
[exit 0]
```

Debian's `netdev` group lets an unprivileged user scan. The fourth row is a
real hidden access point (empty SSID) — the hint printed above it named macOS
on a Linux box; that wording was fixed before the tag (see CHANGELOG).

## Hotspot — permission denied as an unprivileged user

`netdev` grants `org.freedesktop.NetworkManager.settings.modify.system` but not
`network-control`, so starting a hotspot without a polkit rule is refused and
the CLI exits `3`:

```
$ php bin/wifi hotspot start --ssid=femus-setup --password=***
Command LANG='C' 'nmcli' 'device' 'wifi' 'hotspot' 'ifname' 'wlan0' 'ssid' 'femus-setup' 'password' '***' exited with 4: Error: Failed to setup a Wi-Fi hotspot: Not authorized to control networking. The process is not allowed to control NetworkManager — see README → Linux → Privileges (polkit).
[exit 3]
```

Note that the refused call still leaves a `Hotspot` profile behind (the
profile is added under `settings.modify.system` before activation is
refused). Mixing unprivileged and `sudo` runs therefore produces two profiles
named `Hotspot`; `wifi forget Hotspot` removes one per call.

## Hotspot lifecycle (with `sudo`)

Run as one script on the Pi, timestamps from the Pi's clock:

```
=== $ sudo php bin/wifi hotspot start --ssid=femus-setup --password=***
Hotspot femus-setup started on wlan0 (auto)
[exit 0]
=== $ php bin/wifi hotspot status
active
[exit 0]
=== $ php bin/wifi known
 Name     SSID     Device  Active
----------------------------------
 Hotspot  Hotspot  wlan0   true
[exit 0]
=== $ nmcli -t -f NAME,UUID,ACTIVE,AUTOCONNECT connection show
Hotspot:e4e2b619-5149-43c2-83cb-ea6c9483229e:yes:no
Wired connection 1:b1cb4ded-e039-3520-a275-bf8c19c03ac4:yes:yes
lo:9fbd3f5f-6e69-4061-bef7-a3fb9843651f:yes:no
[exit 0]
=== $ sudo php bin/wifi hotspot stop
Hotspot stopped.
[exit 0]
=== $ php bin/wifi hotspot status (immediately)
inactive
[exit 0]
=== $ php bin/wifi known
 Name     SSID     Device  Active
----------------------------------
 Hotspot  Hotspot  -       false
[exit 0]
=== $ sudo php bin/wifi forget Hotspot
[exit 0]
=== $ php bin/wifi known (immediately)
 Name  SSID  Device  Active
----------------------------
[exit 0]
```

While the hotspot was up, `ip -4 -br addr show wlan0` reported
`10.42.0.1/24` (NetworkManager's default shared range). From the Mac,
`system_profiler SPAirPortDataType | grep -c femus-setup` printed `0`: macOS 26
redacts every SSID unless the process has Location Services, so the Mac cannot
confirm the SSID by name — the phone could (next section).

`known` shows the hotspot's SSID as `Hotspot`, not `femus-setup`: `KnownNetwork`
takes its SSID from the connection *name*, because `nmcli connection show` in
list mode cannot emit `802-11-wireless.ssid`. NetworkManager names ordinary
connections after their SSID, so the two agree except for hotspots.

## Provisioning demo (`examples/provision/`)

With the hotspot up and `php -S 0.0.0.0:8080 -t examples/provision` running as
`femus`, a phone joined `femus-setup` and opened `http://10.42.0.1:8080/` —
[`examples/provision/screenshot.jpg`](../examples/provision/screenshot.jpg) is
that page. Two things it shows honestly:

- The list contains only `femus-setup · 2.4 GHz · 0%`. The Pi has one radio;
  while it runs an access point, `nmcli device wifi list` returns nothing but
  the access point itself. A real provisioning flow has to scan *before*
  starting the hotspot and serve the cached result — noted in ROADMAP for 3.1.
- Pressing *Connect* as the unprivileged `femus` rendered the `PermissionDenied`
  message with the password masked (`'***'`) and the polkit pointer, and no
  stack trace.

## Real `connect` (with `sudo`)

The maintainer's own network, passphrase read from a file on the Pi and never
printed. The run was scripted; the Pi's radio was on from the previous steps.

```
passphrase: 12 chars, contains space: no, contains $: no
radio: enabled
=== $ sudo php bin/wifi connect --ssid=BELL340 --password=***
Connected to BELL340 via wlan0 (auto)
[exit 0]
=== $ php bin/wifi list --connected
 SSID     BSSID              Channel  Band  Quality  dBm  Frequency  Connected  Security
-----------------------------------------------------------------------------------------
 BELL340  0e:ac:8a:99:58:5d  157      5     65%      -68  5785       true       WPA2
[exit 0]
=== $ nmcli -t -f NAME,TYPE,DEVICE,ACTIVE connection show
BELL340:802-11-wireless:wlan0:yes
Wired connection 1:802-3-ethernet:eth0:yes
lo:loopback:lo:yes
[exit 0]
=== $ ip -4 -br addr show wlan0
wlan0            UP             192.168.2.75/24
[exit 0]
=== $ php bin/wifi known
 Name     SSID     Device  Active
----------------------------------
 BELL340  BELL340  wlan0   true
[exit 0]
=== $ sudo php bin/wifi disconnect
Disconnected via wlan0 (auto)
[exit 0]
=== $ php bin/wifi list --connected
 SSID  BSSID  Channel  Band  Quality  dBm  Frequency  Connected  Security
--------------------------------------------------------------------------
[exit 0]
=== $ sudo php bin/wifi forget BELL340
[exit 0]
=== $ php bin/wifi known
 Name  SSID  Device  Active
----------------------------
[exit 0]
=== $ sudo nmcli radio wifi off
[exit 0]
radio after: disabled
```

The plan asked for a passphrase containing a space and a `$`; the maintainer's
real passphrase has neither, so shell-escaping of those characters on a live
box rests on the `printf %s` round-trip in `CommandTest`, not on this run.
`connect()` joined the 5 GHz radio of `BELL340` (NetworkManager's choice), the
Pi obtained `192.168.2.75`, `disconnect()` dropped it, and `forget` removed the
profile `connect` had created, leaving the Pi exactly as found.

## Not verified

- Windows (`NetshBackend`, `ProfileFile`): fixture-only. No Windows machine.
- macOS `connect()`/`disconnect()` on real hardware: fixture-only; `scan()` and
  `device()` were run on macOS 26.5 (SSIDs redacted, as documented).
- Hotspot on the 5 GHz band (`--band=5`): the Pi's radio was left on 2.4 GHz.

---

# Verified on real hardware — 3.1.0

Same Raspberry Pi, **2026-09-11**, against the `3.1.0` branch at `f124fdf`
(plus this file). Same board, OS, NetworkManager 1.52.1 and PHP 8.4.24 as the
3.0.0 run above; the suite on the Pi: `OK (239 tests, 564 assertions)`. The
radio was `disabled` before and after, and every profile created during the run
was removed.

## What 3.1 changes, seen on the device

`known` now separates the connection name from the real SSID — the whole point
of the N+1 lookup. With a hotspot profile saved:

```
$ php bin/wifi known
 Name     SSID         Device  Active
--------------------------------------
 Hotspot  femus-setup  wlan0   true
```

In 3.0.0 both columns read `Hotspot`.

`list --json` parses and carries every documented key:

```
$ php bin/wifi list --unique --json | python3 -c 'import json,sys; d=json.load(sys.stdin); print(len(d),"objects; keys:",",".join(d[0].keys()))'
3 objects; keys: ssid,hidden,bssid,channel,band,frequency,quality,dbm,security,securityFlags,connected
```

The hidden-SSID hint is now OS-aware (3.0.0 blamed macOS on a Linux box):

```
1 network(s) broadcast no SSID (hidden); they are shown as "-".
```

## The passphrase and `ps` — measured, not assumed

`connect` was run with the passphrase on a pipe
(`printf '%s' "$(cat ~/.wifi-pass)" | sudo php bin/wifi connect --ssid=BELL340 --password-file=-`)
while a loop sampled `ps` for the passphrase:

```
ps sample 1: wifi-argv-with-password=0 nmcli-argv-with-password=0
ps sample 2: wifi-argv-with-password=0 nmcli-argv-with-password=2
ps sample 3: wifi-argv-with-password=0 nmcli-argv-with-password=2
Connected to BELL340 via wlan0 (auto)
```

That is exactly what the README claims and no more: the passphrase never
appears in the `wifi` process's own arguments, and it *does* appear in
`nmcli`'s (twice — the `sh -c` wrapper and `nmcli` itself) for the duration of
the call. Closing that window is a 3.2 candidate.

`disconnect` and `forget` then confirmed themselves on stdout (`Disconnected
via wlan0 (auto)`, `Forgot BELL340.`), which 3.0.0 did silently.

## Provisioning, end to end

Run as a transient systemd unit, the way `provision.service` runs it. The page
served the networks scanned **before** the hotspot came up — the fix this
release exists for:

> Scanned before the hotspot started.
> BELL340 · 2.4 GHz · 97%
> VTECH_5764_9764 · 2.4 GHz · 70%

[`examples/provision/screenshot.jpg`](../examples/provision/screenshot.jpg) is
that page on a phone joined to `femus-setup`. In 3.0.0 the same page could only
ever list the hotspot itself.

Submitting the form with the correct passphrase:

```
t0    hotspot=active   marker=no
t+5s  unit=inactive hotspot=inactive wlan0=connected:BELL340 marker=yes
...
$ php bin/wifi list --connected
 SSID     BSSID              Channel  Band  Quality  dBm  Frequency  Connected  Security
-----------------------------------------------------------------------------------------
 BELL340  0e:ac:8a:99:58:5d  157      5     62%      -69  5785       true       WPA2
```

The page stopped the hotspot, joined, wrote the marker; the supervisor saw the
marker, stopped the web server and the hotspot and exited — no timeout, no
manual step.

## Two things the live run found and 3.1 fixes

Both were discovered here, not in review, and neither is reproducible from
fixtures:

1. **A mistyped passphrase used to end the session.** NetworkManager takes the
   access point down to attempt a join and does not put it back; the page
   became unreachable and the unit waited out its whole timeout.
   NetworkManager's own journal for that attempt:
   `4way_handshake -> disconnected`, `no secrets`,
   `Activation: failed for connection 'BELL340'`. `hotspot.sh` now notices the
   access point is gone without a marker and restarts it — verified by taking
   the AP down by hand mid-run:

   ```
   t+0   hotspot=inactive
   t+4s  hotspot=active wlan0=connected page=200
   ```

2. **The page could not join at all while holding the radio.** With the access
   point up, NetworkManager's scan list is empty, so `nmcli` refused:
   `exited with 10: Error: No network with SSID 'BELL340' found.` — even though
   the page listed that network, because the page's list comes from the cached
   pre-scan. The page now stops the hotspot before joining. Consequence worth
   knowing: the phone always loses the page at that moment; with one radio
   there is no way around it.

## Not verified

- Windows (`NetshBackend`, `ProfileFile`) — fixture-only, no machine.
- macOS `connect()`/`disconnect()` on real hardware. The macOS false-success
  fix in this release (`networksetup` exits 0 on failure) *was* confirmed on a
  real Mac: `Could not find network NoSuchNetXYZ.` and `Failed to join network
  BELL340.` / `Error: -3912` both come back with exit code 0.
- The `--password-file=-` tty guard (no tty in the harness).

---

# Verified on real hardware — 3.2.0

Same Raspberry Pi, **2026-09-12**, against the `3.2.0` branch at `481c7b2`
(plus this file). Board, OS, NetworkManager 1.52.1 and PHP 8.4.24 as in the
earlier runs. Installed for this release — installed only, nothing on that
machine was ever removed: `hostapd` (then immediately masked so it cannot
auto-start). `iw`, `wpa_supplicant`/`wpa_cli`, `dnsmasq` and `dhcpcd` were
already present.

The `wpa_cli` backend needs a `wpa_supplicant` that owns the radio, so each run
below took `wlan0` away from NetworkManager (`nmcli device set wlan0 managed
no`, NetworkManager stopped), started
`wpa_supplicant -B -i wlan0 -c <conf> -D nl80211` with
`ctrl_interface=/run/wpa_supplicant`, and gave everything back afterwards.
`eth0`, which carries the SSH session, was never touched.

## The suite, on the Pi

`./vendor/bin/phpunit` → `OK (387 tests)`. Running it there also caught three
tests that passed on macOS and failed on Linux — they would have failed CI on
the first push. Fixed before the tag; see the CHANGELOG.

## Choosing a backend

Measured both ways on the same machine:

```
$ # NetworkManager running
Sanchescom\WiFi\Backend\NmcliBackend
$ LANG=C nmcli -t -f RUNNING general
running
$ # NetworkManager stopped
Sanchescom\WiFi\Backend\WpaCliBackend
```

## The `wpa_cli` backend, end to end

```
$ sudo php bin/wifi list --unique
 SSID             BSSID              Channel  Band  Quality  dBm  Frequency  Connected  Security
-------------------------------------------------------------------------------------------------
 BELL340          0e:ac:8a:99:58:5c  1        2.4   100%     -41  2412       false      WPA2
 VTECH_5764_9764  a6:97:5c:b7:97:64  1        2.4   92%      -54  2412       false      WPA2

$ printf '%s' "$PASSPHRASE" | sudo php bin/wifi connect --ssid=BELL340 --password-file=-
wlan0: connected to Access Point: BELL340
wlan0: leased 192.168.2.76 for 259200 seconds
Connected to BELL340 via wlan0 (auto)
[exit 0]

$ sudo php bin/wifi list --connected
 SSID     BSSID              Channel  Band  Quality  dBm  Frequency  Connected  Security
-----------------------------------------------------------------------------------------
 BELL340  0e:ac:8a:99:58:5d  157      5     92%      -54  5785       true       WPA2

$ ip -4 -br addr show wlan0
wlan0            UP             192.168.2.76/24
$ sudo php bin/wifi disconnect
Disconnected via wlan0 (auto)
$ sudo php bin/wifi forget BELL340
Forgot BELL340.
```

The address came from `dhcpcd`, invoked by the backend after association —
`wpa_supplicant` associates and nothing more, so without that step the
interface would have been associated and unusable.

## The passphrase and `ps` — measured, and this time it is zero

3.1 could only claim the passphrase left the `wifi` command line; `nmcli` still
carried it. Both Linux backends now keep it out of every process's arguments.
Measured during a live `connect`, with the search pattern read **from a file**
so the measuring `grep` could not carry the secret itself:

```
$ ps -ww -eo args > /tmp/ps.txt
$ grep -c -F -f ~/.wifi-pass /tmp/ps.txt
0
```

Same check while the hotspot was up: `hotspot passphrase in any argv: 0` — it
reaches only the `hostapd` config file, which is `tempnam`-created at 0600 and
deleted once `hostapd` has read it.

## Hotspot on `hostapd` and `dnsmasq`

```
$ printf '%s' "$HOTSPOT_PW" | sudo php bin/wifi hotspot start --ssid=femus-setup --password-file=-
Hotspot femus-setup started on wlan0 (auto)
$ sudo php bin/wifi hotspot status
active
$ ip -4 -br addr show wlan0
wlan0            UP             10.42.0.1/24
$ pgrep -a hostapd
50638 /usr/sbin/hostapd -B -P /tmp/php-wifi-hostapd.pid /tmp/php-wifi-hostapd-eipg9m6ilk2n5bSVEYV
$ pgrep -a dnsmasq
50643 /usr/sbin/dnsmasq --interface=wlan0 --bind-interfaces --except-interface=lo --dhcp-range=10.42.0.10,10.42.0.100,12h --pid-file=/tmp/php-wifi-dnsmasq.pid
$ sudo php bin/wifi hotspot stop
Hotspot stopped.
$ sudo php bin/wifi hotspot status
inactive
```

`hostapd` was measured gone one second after `stop`, the address flushed and
the radio handed back to `wpa_supplicant`.

## The watchdog

`wifi watch --once` in three of its states, on the device:

```
disconnected, nothing known   -> hotspot_raised   (and the hotspot really came up)
hotspot up, nobody attached   -> hotspot_busy     (and it was not torn down)
connected                     -> connected
```

## Five defects this run found that no amount of reading would have

1. **Every Linux tool was unreachable.** `wpa_cli`, `iw`, `dnsmasq` and
   `dhcpcd` live in `/usr/sbin`, which is not in an unprivileged `PATH` on
   Debian, and 3.2 had just removed the shell from the execution path. Passing
   `PATH` in the command's environment does not help — PHP resolves the program
   with the *parent's* `PATH`. Measured directly:
   `proc_open(["wpa_cli","-v"], …)` failed; `["/sbin/wpa_cli","-v"]` exited 0.
   The backend now resolves each tool's absolute path with `which` first.
2. **`nmcli --ask` ignores a freshly supplied passphrase** when a profile for
   that SSID already exists: with the device disconnected and a stored correct
   secret, feeding a deliberately wrong one still reported
   `Device 'wlan0' successfully activated`. `connect()` now deletes the
   existing profile first when a passphrase is given.
3. **A scan was read before it finished.** On a cold `wpa_supplicant`,
   `scan_results` is empty for several seconds, so every SSID looked absent and
   `connect()` failed with `NetworkNotFound`. `scan()` now waits for rows.
4. **Association was given microseconds.** The poll ran its fifteen attempts
   back to back with no pause, so `connect()` could never succeed on real
   hardware: `did not reach wpa_state=COMPLETED within 15 attempt(s); last
   state: SCANNING`. There is now a real interval between attempts.
5. **A hotspot could be raised but never stopped.** While the radio serves as
   an access point `iw dev` reports `type AP`, and device detection had been
   tightened to accept only `type managed`, so `hotspot stop` died with
   `iw dev listed no wireless interface` and the access point stayed up. The
   parser now prefers a managed interface and falls back to any non-P2P one.

## After the final review — 2026-09-13

The final review found that the watchdog had only ever been tested against
`NmcliBackend`. The fixes it prompted change behaviour on this backend, so they
were run again on the same Pi, at `a2b19db`, with the suite there at
`OK (408 tests)`. A saved network that is never in range (`OtherNet`) sat in
`wpa_supplicant` throughout, as it would on a real device.

```
=== no wpa_supplicant running: wifi list
Command LANG='C' '/usr/sbin/wpa_cli' '-i' 'wlan0' 'scan' exited with 255: Failed to connect to non-global ctrl_ifname: wlan0  error: No such file or directory
[exit 1] after 0s

=== connect with the passphrase
Connected to BELL340 via wlan0 (auto)
list_networks: 0 OtherNet any ;1 BELL340 any [CURRENT];
saved conf: network_blocks=2 disabled=1_lines=0

=== connect with a WRONG passphrase
wpa_cli -i wlan0 did not reach wpa_state=COMPLETED on network 2 within 15 attempt(s); last state: SCANNING
[exit 1]
list_networks: 0 OtherNet any ;1 BELL340 any ;
saved conf: network_blocks=2 disabled=1_lines=0
state=ssid=BELL340 id=1 wpa_state=COMPLETED        (ten seconds later)

=== connect with the right passphrase again
Connected to BELL340 via wlan0 (auto)
list_networks: 0 OtherNet any ;2 BELL340 any [CURRENT];

=== disconnect, then connect with no credentials / watch --once
Connected to BELL340 via wlan0 (auto)
recovered
list_networks: 0 OtherNet any ;2 BELL340 any [CURRENT];
saved conf: network_blocks=2 disabled=1_lines=0

=== hotspot start with port 53 already taken
dnsmasq: failed to create listening socket for 10.42.0.1: Address already in use
[exit 1]
hostapd=0 dnsmasq=0 hostapd.pid=gone iw=type managed addr=
state=ssid=BELL340 id=2 wpa_state=COMPLETED

=== hotspot up, wpa_supplicant killed, hotspot stop
Hotspot stopped.
[exit 0] after 0s
hostapd=0 dnsmasq=0 hostapd.pid=gone iw=type managed addr=
```

What each line shows:
- A mistyped passphrase no longer costs the working one. The new block is
  removed, nothing is saved, and the device rejoins on its own.
- A corrected passphrase leaves exactly one block for the SSID.
- Rejoining without a passphrase — the watchdog's path — uses that block
  rather than adding an open one.
- No other network's block ends up `disabled=1` on disk.
- A hotspot that fails half-way leaves no `hostapd`, no address and a
  reassociated radio behind.

`systemd-analyze verify examples/watch/wifi-watch.service` reported no
start-limit or unknown-key warning.

This run found two more defects that no fixture showed:

6. **Interactive `wpa_cli` waits forever when no `wpa_supplicant` is
   running.** `printf 'reconnect\nquit\n' | wpa_cli -i wlan0` was still
   running when `timeout 5` killed it, while `wpa_cli -i wlan0 reconnect`
   exited 255 at once. `hotspot stop` on a hostapd-only box hung the same way.
   Every command now goes to `wpa_cli` as arguments, except the one script
   that carries a passphrase.
7. **`connect` failed whenever a scan was already running.** With a saved
   network out of range, `wpa_supplicant` scans continuously and answers
   `scan` with `FAIL-BUSY`, so `wifi connect` failed every time. `scan()` now
   takes that as "results are on their way" and polls for them.

## A phone on the hotspot — 2026-09-14

The one branch left unproven on 2026-09-12: someone is attached, so the
watchdog must leave the access point alone even after `--retry` has passed.
Run on the same Pi at `3f219ef` as
`wifi watch --ssid=BELL340 --hotspot-ssid=femus-setup --interval=10 --retry=120`,
with no saved block for BELL340, so every rejoin failed and the hotspot came
back. The maintainer joined `femus-setup` from an iPhone.

```
03:25:10Z watch: hotspot_raised          (idle: torn down and raised again every ~2m20s until then)
03:27:33Z watch: hotspot_raised
04:28:31  stations=1   PHONE ATTACHED    (Pi clock, UTC+1)
DHCPACK(wlan0) 10.42.0.77 ce:39:4a:b7:a6:66 iPhone
03:29:34Z watch: hotspot_busy            (--retry due at 03:29:33Z)
...                                      one hotspot_busy every 10s, nothing else
03:32:56Z watch: hotspot_busy            (window ended, phone still attached)
```

Before the phone joined, the idle hotspot was torn down and the rejoin retried
every cycle. With the phone attached it stayed up for 3m23s past `--retry` and
was never touched.

The phone leaving, run separately with `--retry=60`. BELL340's block was added
once the phone joined, so that there was a network to go back to:

```
03:45:49Z watch: hotspot_raised
04:46:02  stations=1   PHONE ATTACHED
DHCPACK(wlan0) 10.42.0.77 ce:39:4a:b7:a6:66 iPhone
04:46:37  stations=0                     (the phone left the network)
03:46:42Z watch: hotspot_busy            (--retry not due until 03:46:49Z)
03:47:01Z watch: recovered
state=ssid=BELL340 wpa_state=COMPLETED  addr=192.168.2.76/24
```

24 seconds after the phone left, the hotspot was down and the device was back
on its network with an address.

The same run checked one more defect, found while diagnosing why the phone
could not see the hotspot at first:

8. **`hotspot start` refused to run with no `wpa_supplicant`.** Its first step,
   `wpa_cli disconnect`, now fails at once instead of hanging, and that failure
   aborted the start. With nothing holding the radio there is nothing to
   disconnect, so the failure is ignored (a permission error still stops it).
   Measured after the fix: `Hotspot femus-setup started on wlan0 (auto)`,
   `iw=type AP hostapd=1 dnsmasq=1`.

The earlier "cannot see it" was timing, not radio: with the hotspot up, a Mac
next to the Pi saw a new 2.4 GHz channel-6 network for exactly the time it was
up, on a channel that was otherwise empty.

## Not verified

- The provisioning demo on the `wpa_cli` backend (it was verified on
  NetworkManager in 3.1.0).
- `NmcliBackend::startHotspot()` still passes the hotspot passphrase as an
  argument — nmcli has no stdin mode for that command, and the argv-free route
  is a keyfile. Listed in ROADMAP for 3.3.
- Windows and macOS are untouched by this release.
