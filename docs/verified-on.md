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
