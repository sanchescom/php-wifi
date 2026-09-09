# Upgrade Guide to v2.0

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
