# Upgrade Guide

## 3.0 → 3.1

Nothing to change in calling code — 3.1 is additive. Two things worth
knowing:

- **`KnownNetwork::$ssid` now means the SSID.** `knownNetworks()` used to
  return the connection name in both `$name` and `$ssid`, because `nmcli
  connection show` in list mode cannot emit the SSID. 3.1 resolves the real
  SSID with one extra `nmcli -g 802-11-wireless.ssid connection show <name>`
  call per profile, so `$name` and `$ssid` now differ for a hotspot profile
  saved under a name like `Hotspot`. If code compared `$known->name` against
  `$known->ssid` to detect that case, it no longer matches — compare against
  the SSID you expect instead. A failed per-profile lookup falls back to the
  connection name, same as before.
- **`WiFi::connectTo(string $ssid, Credentials $credentials, ?Device $device = null)`**
  is new: it joins `$ssid` without scanning first. Existing calls to
  `connect()` do not need to change — it still scans, to resolve an SSID and
  to raise `NetworkNotFound` on a typo. Use `connectTo()` only where a scan
  is impossible, such as while the radio is running the provisioning
  hotspot.

## 2.x → 3.0

3.0 replaces the whole 2.x surface — every `WiFi::` static method, the
`Collection` filter/sort methods, `AbstractNetwork` and its public
properties, the seven global helper functions, and the
`Sanchescom\WiFi\Exceptions\*` namespace — with a single object API:
`WiFi::create()` (or `new WiFi($backend)`) returns a facade over one
`Backend`; `scan()` returns a `NetworkCollection` of immutable `Network`
value objects; connecting takes a `Credentials` value object instead of a
bare password string. There is no compatibility shim — every row below is
a breaking change.

| Was (2.x) | Now (3.0) |
| --- | --- |
| `WiFi::scan()` | `WiFi::create()->scan()` |
| `WiFi::getConnected()` | `WiFi::create()->scan()->connected()` |
| `WiFi::getStrongestNetwork()` | `WiFi::create()->scan()->strongest()` |
| `WiFi::get24GhzNetworks()` / `get5GhzNetworks()` / `get6GhzNetworks()` | `->scan()->band(Band::GHz2_4)` / `Band::GHz5` / `Band::GHz6` |
| `WiFi::getNetworksBySecurity('WPA2')` | `->scan()->security(Security::WPA2)` |
| `WiFi::setCommandClass()` / `WiFi::setPhpOperationSystem()` | Gone — construct `new WiFi($backend)` directly, e.g. `new WiFi(BackendFactory::forOs(Os::Linux, new FakeCommandRunner([...])))` in tests |
| `Collection::getAll()` | `->all()` (inherited from `Illuminate\Support\Collection`) |
| `Collection::getBySsid(string $ssid)` | `NetworkCollection::bySsid(string $ssid): Network` |
| `Collection::getByBssid(string $bssid)` | `NetworkCollection::byBssid(Bssid\|string $bssid): Network` |
| `Collection::getConnected()` | `NetworkCollection::connected(): static` |
| `Collection::getBySecurity(string $type)` | `NetworkCollection::security(Security $security): static` |
| `Collection::getByMinSignalStrength(float $dbm)` | `NetworkCollection::strongerThan(Signal\|float $threshold): static` |
| `Collection::getByChannel(int $channel)` | Gone — filter yourself: `->filter(fn ($n) => $n->channel === 6)` |
| `Collection::sortBySignalStrength()` | `NetworkCollection::sortBySignal(): static` |
| `Collection::getStrongest()` | `NetworkCollection::strongest(): Network` |
| `Collection::hasRedactedSsids()` | `NetworkCollection::hasHiddenSsids(): bool` |
| `AbstractNetwork` public properties | `Network` readonly properties: `$ssidRedacted` → `$ssidHidden`; `$quality` / `$dbm` → `$signal?->quality` / `$signal?->dbm` (a `Signal` value object, or `null` when the tool reported none); `$bssid` (`string`) → `$bssid` (`?Bssid`); `$security` (`string`) → `$security` (`Security` enum) plus `$securityFlags` (unchanged, still `string`) |
| `$network->getSecurityType()` | `$network->security` (a `Security` enum case, not a string) |
| `$network->connect(string $password, ?string $device = null)` | `WiFi::connect(Network\|string $network, Credentials $credentials, ?Device $device = null)` — networks no longer connect themselves |
| `$network->disconnect(?string $device = null)` | `WiFi::disconnect(?Device $device = null)` |
| `to_dbm()` / `to_quality()` (global functions) | Gone — use `Signal::fromDbm()` / `Signal::fromQuality()` |
| `to_hex()`, `trim_first()`, `extract_bssid()`, `extract_after()`, `glue_commands()` (global functions) | Gone. These were parser internals; the 3.0 parsers (`src/Parser/**`) are pure functions over fixture text and do not need them from outside |
| `Sanchescom\WiFi\Exceptions\CommandException` | `Sanchescom\WiFi\Exception\CommandFailed` |
| `Sanchescom\WiFi\Exceptions\NetworkNotFoundException` | `Sanchescom\WiFi\Exception\NetworkNotFound` |
| `Sanchescom\WiFi\Exceptions\DeviceNotFoundException` | `Sanchescom\WiFi\Exception\DeviceNotFound` |
| `Sanchescom\WiFi\Exceptions\UnknownSystemException` | `Sanchescom\WiFi\Exception\UnsupportedOperation`, thrown by `Sanchescom\WiFi\Shell\Os::current()` when `PHP_OS_FAMILY` is not `Linux`, `Darwin` or `Windows`. (The lower-level `Os::from()` — a plain native `enum::from()` — still throws PHP's own `ValueError`; the library itself only ever calls `Os::current()`.) |
| `Sanchescom\WiFi\System\Windows\Profile` | `Sanchescom\WiFi\Backend\Windows\ProfileFile` |

Two behavioural changes that are not renames:

- **macOS `disconnect()` no longer removes the preferred network.** 2.x
  called `networksetup -removepreferredwirelessnetwork` as part of
  disconnecting, which also forgot the network. 3.0's
  `NetworksetupBackend::disconnect()` only power-cycles the Wi-Fi radio
  (`-setairportpower off` then `on`); if you relied on disconnect also
  forgetting the network on macOS, do that separately.
- **Sentinel values are gone.** A network with no signal reading used to
  be reported as `-100` dBm / `0%`; a network with an unknown channel used
  to report `frequency === 0`. Both are `null` now (`$network->signal`,
  `$network->frequency`, `$network->channel`, `$network->band`) — check
  for `null`, not for the old magic numbers.

## 2.0 → 2.1

### If you extend `AbstractNetwork`

`connect()` and `disconnect()` are now declared as
`connect(string $password, ?string $device = null): void` and
`disconnect(?string $device = null): void`. **Any class that extends
`AbstractNetwork` must widen its own `connect()`/`disconnect()` signatures
to match, or PHP fatals** with a "declaration must be compatible" error.
This is not theoretical: the project's own test suite has two anonymous
`AbstractNetwork` subclasses (`tests/SecurityTypeTest.php` and
`tests/SignalTest.php`) that fataled until their `connect()`/`disconnect()`
signatures were widened to `?string $device = null`. This is the only
source-level change a subclass author needs to make.

#### Windows subclasses

`Windows\Network::getProfileService()` now declares its return type as
`: Profile` (2.0.1 had none), and `Windows\Profile::$ssid` /
`$securityType` are now typed `string`. A subclass that overrides
`getProfileService()` must declare the same `: Profile` return type, and a
subclass of `Profile` must not redeclare `$ssid` or `$securityType`
without the `string` type — PHP forbids dropping a parent's return type or
narrowing/removing a parent's property type, and fatals at class load
("declaration must be compatible") if either is done.

### Everything else

- Command strings are now quoted (`escapeshellarg()` on Linux/macOS, `"`,
  `%` and `!` replaced with a space on Windows). Anyone asserting on
  `getLastCommand()` must update the expected strings.
- The Windows profile path moved from
  `vendor/sanchescom/php-wifi/tmp/<ssid>.xml` to a random file under
  `sys_get_temp_dir()`, and the package's `tmp/` directory no longer
  exists. Anything that relied on `Profile::getTmpFileName()`'s old
  location breaks.
- Band filters (`get24GhzNetworks()`, `get5GhzNetworks()`) now select by
  frequency instead of channel number. On Windows, `netsh` reports
  channels only, so 6 GHz networks are reported on their 5 GHz table
  frequency there.

## Overview

Version 2.0 brings PHP 8.2+ support, modern syntax, extended functionality, and macOS improvements.

## Breaking Changes

### 1. PHP Version
**Before (v1.x):**
```json
"php": "^7.2.8"
```

**Now (v2.0):**
```json
"php": "^8.2"
```

2.0.1 widened this to any 8.2+ release (8.4, 8.5) and allows illuminate/collections 11–13.

### 2. Collection Library
**Before:** `tightenco/collect`
**Now:** `illuminate/collections`

The API remains the same, but the underlying library changed.

### 3. Typed Returns
All methods now have strict return types:

```php
// Before
public function getAll()

// Now
public function getAll(): array
```

## macOS-Specific Changes

### No More Deprecation Warnings! 🎉

**v1.x Problem:**
```
WARNING: The airport command line tool is deprecated and will be removed in a future release.
```

**v2.0 Solution:**
- Uses `system_profiler SPAirPortDataType` (official Apple tool)
- Dual-format parser for backward compatibility
- Zero deprecation warnings

## macOS in 2.0.1

`system_profiler` scanning has real limits that 2.0.0's docs did not
mention:

- Apple gates Wi-Fi details behind Location Services: unless the process
  running PHP has been granted Location Services, every SSID comes back as
  the literal string `<redacted>`.
- `system_profiler` never reports BSSIDs for other networks, so `bssid` is
  always empty on macOS.
- Because of that, `getByBssid()` cannot find anything and the CLI's
  `connect --bssid` does not work on macOS — use `getBySsid()`.

## New Features

### Collection Filtering

```php
use Sanchescom\WiFi\WiFi;

// Get only 5GHz networks
$networks5g = WiFi::scan()->get5GhzNetworks();

// Get networks with strong signal
$strongNetworks = WiFi::scan()->getByMinSignalStrength(-60);

// Get WPA2 networks
$secureNetworks = WiFi::scan()->getBySecurity('WPA2');

// Chain filters
$best = WiFi::scan()
    ->get5GhzNetworks()
    ->getBySecurity('WPA2')
    ->sortBySignalStrength()
    ->first();
```

### Static Helpers

```php
// Quick access methods
$connected = WiFi::getConnected();
$strongest = WiFi::getStrongestNetwork();
$networks5g = WiFi::get5GhzNetworks();
```

## Installation

### Fresh Install

```bash
composer require sanchescom/php-wifi
```

### Upgrade from v1.x

```bash
composer update sanchescom/php-wifi
```

**Note:** You may need to update your PHP version to 8.2+ first.

## Testing After Upgrade

```bash
# Run tests
composer test

# Check code style
composer lint

# Test WiFi scanning
./vendor/bin/wifi list
```

## Compatibility

Most v1.x code will work without changes. The main requirement is PHP 8.2+.

### Working v1.x Code Example

```php
<?php
use Sanchescom\WiFi\WiFi;

// This works in both v1.x and v2.0
$networks = WiFi::scan();
foreach ($networks->getAll() as $network) {
    echo "{$network->ssid}: {$network->dbm} dBm\n";
}
```

## Need Help?

- Report issues: https://github.com/sanchescom/php-wifi/issues
- Read the docs: README.md
- Check examples in README.md
