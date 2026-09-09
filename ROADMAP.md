# Roadmap

Planned work for php-wifi, in the order it should ship, and why. See
CHANGELOG.md for what has already landed.

## 2.1 — shipped

See CHANGELOG.md. Left open from the 2.1 list: nothing.

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
