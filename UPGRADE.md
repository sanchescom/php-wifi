# Upgrade Guide to v2.0

## Overview

Version 2.0 brings PHP 8.1+ support, modern syntax, extended functionality, and macOS improvements.

## Breaking Changes

### 1. PHP Version
**Before (v1.x):**
```json
"php": "^7.2.8"
```

**Now (v2.0):**
```json
"php": "^8.1 || ^8.2 || ^8.3"
```

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

**Note:** You may need to update your PHP version to 8.1+ first.

## Testing After Upgrade

```bash
# Run tests
composer test

# Check code style
composer check-style

# Test WiFi scanning
./vendor/bin/wifi list
```

## Compatibility

Most v1.x code will work without changes. The main requirement is PHP 8.1+.

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
