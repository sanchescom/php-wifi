# Changelog

All notable changes to this project will be documented in this file.

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

### Fixed
- Linux: an SSID containing `:` shifted every field after it (`nmcli --terse` escapes it as `\:`).

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
