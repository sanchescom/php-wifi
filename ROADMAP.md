# Roadmap

Planned work for php-wifi, in the order it should ship, and why. See
CHANGELOG.md for what has already landed.

## 2.1 — safe to point at a real network

Minor release. Behaviour changes only where the old behaviour was already
broken or unsafe.

- **Escape every shell argument.** No call site uses `escapeshellarg()`.
  On macOS `connect()` passes SSID and password *unquoted* into `sh`, so any
  SSID containing a space — the unit test's own fixture,
  `Offshore View Marine Services`, asserts the broken command — cannot be
  joined. On Linux they are double-quoted but not escaped, so `"`, `$` and
  backticks in a password are interpreted by the shell. SSIDs are chosen by
  whoever runs the access point; treat them as hostile input.
- **Move the Windows profile out of the package directory.** `connect()`
  writes the plaintext password to `vendor/sanchescom/php-wifi/tmp/<ssid>.xml`
  — a path built from the SSID with no sanitising (`..` and `/` are honoured)
  and an XML body built with `str_replace` and no escaping. Write to
  `sys_get_temp_dir()` under a random name, escape the SSID for XML, delete
  in a `finally`.
- **Add a WPA3 profile template and 6 GHz channels.** `Frequency` maps
  channel 165 to 5825 MHz; on macOS 26 channel 165 is a 6 GHz / 160 MHz
  network (`Channel: 165 (6GHz, 160MHz)` in the raw output). Add the 6 GHz
  table, add `get6GhzNetworks()`, and stop `get5GhzNetworks()` meaning
  "channel > 14".
- **Make the macOS limitation explicit instead of silent.** When
  `system_profiler` returns `<redacted>`, the scan should not yield six
  identical networks named `<redacted>`. Options, in order of preference:
  a `ssidRedacted` flag on the network plus a documented
  `LocationServicesRequired` condition; or throwing. Document that BSSIDs
  are never available from `system_profiler`, so `getByBssid()` and the CLI's
  `connect --bssid` cannot work on macOS at all, and that the only path is
  `getBySsid()` with Location Services granted to the host process (Terminal,
  the web server, …).
- **Cover the `system_profiler` parser with a fixture.** The Darwin test
  fixture is still the old `airport` table, so the parser 2.0.0 shipped as
  its headline feature has never been executed by a test. Capture the real
  output from a Mac with and without Location Services and test both.
- **Document Linux privileges.** Three of the closed issues (#15, #20, #21)
  are the same problem: `nmcli device wifi connect` needs a polkit rule or
  membership in `netdev`, and a PHP-FPM worker has neither. A README section
  with the polkit rule closes the most-reported failure the library has.
- **Detect the wireless device instead of asking for it** (#19). `nmcli -t
  -f DEVICE,TYPE device` on Linux and `networksetup -listallhardwareports`
  on macOS both say which interface is Wi-Fi; make `$device` optional.
- **Collapse duplicate SSIDs** (#10). `groupBySsid()` returning the strongest
  BSSID per SSID — most users want one row per network, not one per radio.
- **Dependency hygiene.** `.gitattributes` with `export-ignore` for
  `tests/`, `tmp/`, `.github/`; PHPStan config and a `composer analyse`
  script (PHPStan is a dev dependency today with nothing configured); Pint
  or PSR-12 in place of PSR-2.

## 3.0 — only if a consumer exists

Major release. Do not start this until something real depends on the
package; every item is a breaking change with no user asking for it yet.

- **Remove the global helper functions.** `composer.json` autoloads
  `to_dbm()`, `to_quality()`, `to_hex()`, `trim_first()`, `extract_bssid()`,
  `extract_after()` and `glue_commands()` into the global namespace of every
  consumer. Make them static methods or private.
- **Replace positional arrays with a value object.** Each OS's
  `createFromArray()` reads a different index layout (`$network[2]` is
  signal on Darwin and Windows, security on Linux). A `readonly` `Network`
  DTO built by each parser removes the layout coupling and the
  uninitialised-typed-property errors that follow a partial parse.
- **Replace static mutable state with injection.** `WiFi::setCommandClass()`
  and `WiFi::setPhpOperationSystem()` are process-global; tests depend on
  call order. A `Scanner` interface with per-OS implementations, resolved by
  a small factory, is the same amount of code without the global.
- **Make Linux/NetworkManager the first-class backend.** It is the only
  platform where a PHP process is a realistic wifi manager — a headless
  Raspberry Pi with a PHP admin panel doing "scan, pick, connect" (#16). Add
  saved-connection management, hotspot/AP mode via `nmcli device wifi
  hotspot`, and an `iw`/`wpa_cli` fallback for images without NetworkManager.
  macOS and Windows become explicitly best-effort adapters.
- **Split the CLI into its own package.** `bin/wifi` pulls
  `splitbrain/php-cli` and `phplucidframe/console-table` into every install
  of the library; both are maintained, but a library should not carry a
  CLI's dependencies.

## Decisions the maintainer has to make

- **macOS.** The honest options are (a) ship the best PHP can do and say so,
  as above, or (b) ship a small signed Swift helper that talks to CoreWLAN
  and returns JSON — the only way to get SSIDs and BSSIDs on macOS 14+.
  (b) is a different project. Recommendation: (a), until a user asks.
