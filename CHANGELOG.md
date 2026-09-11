# Changelog

All notable changes to this project will be documented in this file.

## [3.1.0] - 2026-09-11

A small, additive release on top of 3.0.0. Nothing in this release changes
calling code — see [UPGRADE.md](UPGRADE.md#30--31).

### Added
- **`WiFi::connectTo(string $ssid, Credentials $credentials, ?Device $device = null)`**:
  joins a network without scanning first. `connect()` still scans, to resolve
  an SSID and to raise `NetworkNotFound` on a typo; `connectTo()` hands the
  SSID straight to the backend, for the case where the radio cannot scan
  because it is already running the provisioning hotspot.
- **`--password-file=<path>` / `--password-file=-`** for `connect` and
  `hotspot start`: reads the password from a file or from stdin, stripping
  exactly one trailing newline. Mutually exclusive with `--password`; an
  empty file, an empty value, a directory path, or a tty on stdin are each
  rejected with a clear message and exit `1`.
- **`wifi list --json`**: one JSON object per network (`ssid`, `hidden`,
  `bssid`, `channel`, `band`, `frequency`, `quality`, `dbm`, `security`,
  `securityFlags`, `connected`), pretty-printed to stdout. `--unique` and
  `--connected` apply first, as for the table; the hidden-SSID hint still
  goes to STDERR.
- **Cached scan for the provisioning demo**: `hotspot.sh` runs `wifi list
  --unique --json` before starting the hotspot and caches the result
  (`PROVISION_CACHE`); the demo page serves that cache instead of a live
  scan, which would otherwise see only the hotspot itself.
- **A self-stopping provisioning unit**: `index.php` writes a done marker
  (`PROVISION_DONE`) once a connection succeeds; `hotspot.sh` polls for it,
  for at most `PROVISION_TIMEOUT` seconds, then stops the web server and the
  hotspot and exits `0` (so `Restart=on-failure` does not bring it back).

### Changed
- **`KnownNetwork::$ssid`** now holds the real SSID, resolved with one extra
  `nmcli -g 802-11-wireless.ssid connection show <name>` call per profile;
  `$name` remains the connection name. A failed lookup falls back to the
  connection name rather than failing the whole list.
- **`NmcliBackend::knownNetworks()`** therefore costs N+1 `nmcli` commands
  for N wireless profiles, not one.
- The provisioning demo connects through `connectTo()` instead of `connect()`,
  since the radio is already running an access point at that point.

### Fixed
- The provisioning demo could only ever see its own hotspot in the network
  list, because a single radio cannot scan while running an access point.
- The provisioning hotspot and web server outlived a successful setup —
  nothing stopped the unit once the device had joined the real network.
- `hotspot.sh` passed the hotspot password as a plain argv value on the
  `wifi` command line; it now pipes it through `--password-file=-` instead,
  which keeps it off `wifi`'s own argv. `nmcli` still receives it as an
  argument one process later, so it remains visible via `ps` on Linux for
  that instant; see [ROADMAP.md](ROADMAP.md) for closing that window too.
- macOS `connect()` reported success when `networksetup` had actually
  failed, because that tool exits 0 and only prints the reason (`Could not
  find network …` or `Failed to join network …`) on stdout; `connect()` now
  inspects that output and raises `NetworkNotFound` or `CommandFailed`.
- A mistyped passphrase used to end the provisioning session, because
  NetworkManager drops the access point to attempt the join and does not
  restore it.

## [3.0.0] - 2026-09-10

A ground-up rewrite. See [UPGRADE.md](UPGRADE.md) for the full "2.x → 3.0"
mapping and [docs/verified-on.md](docs/verified-on.md) for what was run on
real hardware before this tag.

### Added
- **Object API**: `WiFi` is now a facade over one `Backend` — `WiFi::create()`
  auto-detects the OS; `new WiFi($backend)` injects one directly (tests use
  this with `FakeCommandRunner`). No more static mutable state.
- **Value objects**: `Network` (readonly, replaces `AbstractNetwork`),
  `NetworkCollection` (replaces the old `Collection`), `Signal` (`dbm` +
  `quality`, replaces `to_dbm()`/`to_quality()`), `Bssid`, `Device`,
  `Credentials`, `KnownNetwork`, `Hotspot`, `HotspotConfig`.
- **Enums**: `Band` (`GHz2_4`/`GHz5`/`GHz6`) and `Security`
  (`WPA3`/`WPA2`/`WPA`/`WEP`/`Open`/`Unknown`) replace string constants.
- **Known networks**: `WiFi::knownNetworks(): list<KnownNetwork>` and
  `WiFi::forget(KnownNetwork|string)`, backed by `nmcli connection show` on
  Linux (`SupportsKnownNetworks`); not supported on macOS or Windows.
- **Hotspot**: `WiFi::startHotspot(HotspotConfig)`, `stopHotspot()`,
  `isHotspotActive()` via `nmcli device wifi hotspot` on Linux
  (`SupportsHotspot`); not supported on macOS or Windows.
- **`PermissionDenied`** exception, a `CommandFailed` subclass raised when
  the underlying tool reports "Not authorized" / "Insufficient privileges";
  the CLI maps it to exit code `3` and prints an OS-tailored hint (the
  polkit pointer on Linux, "run with sufficient privileges" elsewhere).
- **CLI**: `device`, `known`, `forget <ssid-or-name>` and
  `hotspot {start|stop|status}` commands, on top of the existing
  `list`/`connect`/`disconnect`. Exit codes: `0` success, `1` general
  error/usage, `2` unsupported operation (capability not implemented by
  this backend), `3` permission denied.
- **Provisioning demo** (`examples/provision/`): a headless Raspberry Pi
  setup wizard — a temporary hotspot plus a small PHP page a phone can use
  to scan and join the real network, no SSH or monitor required.
- **`docs/verified-on.md`**: the raw output of every CLI command run live
  on a Raspberry Pi (NetworkManager 1.52.1), including one real `connect`
  — the release gate for this tag.
- CLI capability interfaces (`Backend`, `SupportsKnownNetworks`,
  `SupportsHotspot`) let `WiFi::supports(string $capabilityInterface)`
  check what the active backend can do before calling it.

### Changed (Breaking)
Every item in the [UPGRADE.md "2.x → 3.0"](UPGRADE.md#2x--30) table,
including:
- `WiFi::` static helpers, `Collection` filter/sort methods,
  `AbstractNetwork` properties and `$network->connect()`/`disconnect()`
  are all replaced — see UPGRADE.md for the one-to-one mapping.
- **`null` instead of sentinels.** A network with no signal reading is
  `$network->signal === null` (was `-100` dBm / `0%`); an unrecognised
  channel is `$network->channel === null` / `$network->band === null`
  (was `frequency === 0`).
- **macOS `disconnect()` no longer removes the preferred network** — it
  only power-cycles the radio (`-setairportpower off` then `on`), matching
  what "disconnect" means on the other two platforms.
- `Sanchescom\WiFi\Shell\Os::current()` throws `UnsupportedOperation` (not
  a bespoke `UnknownSystemException`) when `PHP_OS_FAMILY` is not `Linux`,
  `Darwin` or `Windows`.

### Removed
- The seven global helper functions autoloaded into every consumer's
  namespace: `to_dbm()`, `to_quality()`, `to_hex()`, `trim_first()`,
  `extract_bssid()`, `extract_after()`, `glue_commands()`.
- `WiFi::setCommandClass()` / `WiFi::setPhpOperationSystem()` and all other
  process-global static state.
- `AbstractNetwork` and the per-OS positional-array parsing it was built
  on (`$network[2]` meaning different things on different platforms).
  Replaced by pure parsers (`src/Parser/**`) that build `Network` directly.
- The `Sanchescom\WiFi\Exceptions\*` namespace (`CommandException`,
  `NetworkNotFoundException`, `DeviceNotFoundException`,
  `UnknownSystemException`) — replaced by `Sanchescom\WiFi\Exception\*`.
- `Sanchescom\WiFi\System\Windows\Profile` — replaced by
  `Sanchescom\WiFi\Backend\Windows\ProfileFile`.

### Fixed
- **Linux:** an nmcli SSID ending in a backslash (`\` is `nmcli --terse`'s
  own escape character) was silently dropped instead of parsed.
- **Windows:** a `netsh` scan block with more than one BSSID for the same
  SSID could misalign every network parsed after it; blocks are now
  scanned per field instead of by a fixed row count.
- 2.4 GHz channel 14 now reports its correct centre frequency, 2484 MHz
  (was 2477 MHz).
- The CLI's hidden-SSID hint is OS-aware: it names macOS Location Services
  only when the active backend is `NetworksetupBackend`; on every other
  backend it reports how many networks broadcast no SSID at all.
- `forget` prints `Forgot <name>.` on success instead of nothing.
- `PermissionDenied`'s hint is tailored per OS (the polkit pointer on
  Linux; "run the command with sufficient privileges" elsewhere) instead
  of a single generic message.
- **Windows:** `ProfileFile` now renders its template in a single
  `strtr()` pass instead of parallel `str_replace()` arrays, so an SSID
  equal to a template placeholder (e.g. `{key}`) can no longer make the
  rendered profile contain the passphrase in place of the network name.
- **Windows:** a `netsh` scan block's `Band :` field (present on Windows 11
  22H2+) is now honoured when parsing networks, so a 6 GHz access point is
  reported as `Band::GHz6` with the correct frequency instead of being
  misclassified as 5 GHz from its channel number alone.

## [2.1.0] - 2026-09-09

### Added
- `connect()` and `disconnect()` accept `null` for `$device` and detect the wireless device (`nmcli -t -f DEVICE,TYPE device`, `networksetup -listallhardwareports`, `netsh wlan show interfaces`); `DeviceNotFoundException` when there is none. CLI `--device` is optional.
- `Collection::get6GhzNetworks()` / `WiFi::get6GhzNetworks()`; 6 GHz frequencies (5950 + 5 × channel) when macOS reports the band.
- `Collection::uniqueBySsid()` — strongest radio per SSID, hidden networks kept; CLI `list --unique`.
- `AbstractNetwork::$ssidRedacted` and `Collection::hasRedactedSsids()`; the CLI warns on STDERR when macOS hid the names.
- README: polkit rules for running `connect()` as `www-data` on Linux.
- CI: a `--prefer-lowest` job.

### Changed
- **Every shell argument is escaped** (`escapeshellarg()` on Linux/macOS; on Windows `"`, `%`, `!` are replaced with a space). SSIDs with spaces now work on macOS; the exact command strings changed, so tests that asserted them must be updated.
- **Windows profile** is written to a random file under `sys_get_temp_dir()` (was `vendor/…/tmp/<ssid>.xml`), with the SSID and passphrase XML-escaped, and deleted in a `finally`. The package's `tmp/` directory is gone.
- Band filters (`get24GhzNetworks()`, `get5GhzNetworks()`) select by frequency instead of channel number; a network whose channel is unknown (`frequency === 0`) is in no band.
- Coding standard PSR-12 (was PSR-2); PHPStan level 6 with no baseline.
- `Windows\Network::getProfileService()` now declares its return type as `: Profile`, and `Windows\Profile::$ssid` / `$securityType` are now typed `string`; see UPGRADE.md if you subclass either.
- Dev dependencies: `phpunit/phpunit` ^11.5 (was ^11.0), `phpstan/phpstan` ^2.2 (was ^2.0) — the first versions clean at PHPStan level 6 / PHPUnit 11 config.
- `Windows\Profile::create()` throws `InvalidArgumentException` for a security type outside `WPA3`/`WPA2`/`WPA`/`WEP`/`Unknown` (the library itself only ever passes one of those).

### Fixed
- Linux: an SSID containing `:` shifted every field after it (`nmcli --terse` escapes it as `\:`).
- Windows: an SSID or passphrase that is not valid UTF-8 rendered an empty XML element in the profile; it now renders U+FFFD for the invalid bytes.
- Windows: a value ending in an odd number of backslashes could swallow the closing quote of a `netsh` argument; the run is now doubled.

## [2.0.1] - 2026-09-08

### Changed
- **License:** MIT (was GPL-3.0). All code is by the copyright holder.
- `php` constraint is now `^8.2` — 8.4 and 8.5 install; 8.1 could never resolve 2.0.0 because `illuminate/collections` 11 already required 8.2.
- `illuminate/collections` constraint is now `^11.0 || ^12.0 || ^13.0`, so the package coexists with Laravel 11, 12 and 13 applications.
- `getSecurityType()` recognises `WPA3` (new constant `AbstractNetwork::WPA3_SECURITY`) and returns `Unknown` instead of `WEP` when nothing matches.
- Composer scripts renamed: `check-style` → `lint`, `fix-style` → `fix`; new `analyse` (PHPStan).

### Fixed
- **Linux:** `nmcli` reports signal as a percentage; it was stored as dBm and quality was derived from it (`+95 dBm`, `390 %`). Both fields are now correct.
- **macOS:** the dBm reading from `system_profiler` (and from the legacy `airport` table) was converted as if it were a percentage (`-59 dBm` became `-129.5 dBm`).
- **macOS:** every network was reported `connected: true` when SSIDs are redacted; only the current network is now, and never when its name is `<redacted>`.
- **macOS:** the `awdl0` interface block was parsed as extra networks; only the first Wi-Fi interface (`Card Type:`) is scanned.
- **macOS:** networks for which `system_profiler` reports no `Signal / Noise` line were reported as `0 dBm` / `100 %` — the strongest possible reading — and so won `getStrongest()`; they now report `-100 dBm` / `0 %`.
- **Windows:** `WPA3-Personal` networks selected the WPA (TKIP) profile template; a `WPA3.xml` (WPA3SAE) template is added.
- Test suite: PHPUnit 11 configuration and attributes (12 deprecations removed); `failOnWarning`/`failOnNotice`/`failOnDeprecation` enforced.

### Added
- GitHub Actions CI: PHP 8.2–8.5 × `illuminate/collections` 11/12/13 (Travis configuration removed).
- `.gitattributes` so `composer require` no longer ships tests and tooling.
- Real `system_profiler` fixtures (macOS 26.5), redacted and named, covering the parser 2.0.0 introduced.

## [2.0.0] - 2025-11-15

### Breaking Changes
- **Minimum PHP version is now 8.1** (previously 7.2.8)
- Replaced `tightenco/collect` with `illuminate/collections`
- All methods now have strict return type declarations
- Properties are now strictly typed

### Added
- **New Collection Methods:**
  - `getBySecurity(string $type)` - Filter networks by security type
  - `getByMinSignalStrength(float $dbm)` - Filter by minimum signal strength
  - `getByChannel(int $channel)` - Filter networks by specific channel
  - `get24GhzNetworks()` - Get networks on 2.4GHz band
  - `get5GhzNetworks()` - Get networks on 5GHz band
  - `sortBySignalStrength()` - Sort networks by signal strength
  - `getStrongest()` - Get the strongest available network

- **New WiFi Static Methods:**
  - `WiFi::getConnected()` - Quick access to connected networks
  - `WiFi::getStrongestNetwork()` - Get strongest network directly
  - `WiFi::get24GhzNetworks()` - Get 2.4GHz networks directly
  - `WiFi::get5GhzNetworks()` - Get 5GHz networks directly
  - `WiFi::getNetworksBySecurity(string $type)` - Filter by security type

### Changed
- **Complete refactoring with PHP 8.1+ features:**
  - Added typed properties throughout the codebase
  - Replaced `list()` with array destructuring syntax `[]`
  - Added strict types declaration to all files
  - Improved type safety with union types where appropriate
  - Modern array and string syntax

- **Updated Dependencies:**
  - `php`: ^8.1 || ^8.2 || ^8.3 (was ^7.2.8)
  - `illuminate/collections`: ^11.46 (replaces tightenco/collect ^5.8)
  - `splitbrain/php-cli`: ^1.3 (was ^1.1)
  - `phpunit/phpunit`: ^11.0 (was ^8.0)
  - `mockery/mockery`: ^1.6 (was ^1.2)
  - `squizlabs/php_codesniffer`: ^3.8 (was ^3.4)
  - `phpstan/phpstan`: ^1.10 (was ^0.11.5)
  - `phplucidframe/console-table`: ^1.3 (was ^1.2)

### Improved
- Enhanced README.md with:
  - Comprehensive API documentation
  - More usage examples
  - Platform-specific notes
  - Migration guide from v1.x
  - Feature highlights
  - Detailed method references with tables

### Fixed
- Type safety issues with float properties
- Command execution error handling
- Collection method signatures compatibility
- `getFrequency()` now returns 0 instead of null for unknown channels (fixes TypeError on macOS)
- macOS: Migrated to `system_profiler SPAirPortDataType` (official Apple tool) for WiFi scanning
- macOS: Maintained backward compatibility with old `airport` format for legacy systems
- macOS: No more deprecation warnings

## [1.x] - Previous Versions

Legacy version with PHP 7.2+ support.
