[![CI](https://github.com/sanchescom/php-wifi/actions/workflows/ci.yml/badge.svg)](https://github.com/sanchescom/php-wifi/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/sanchescom/php-wifi.svg)](https://packagist.org/packages/sanchescom/php-wifi)
[![PHP Version](https://img.shields.io/packagist/php-v/sanchescom/php-wifi.svg)](https://packagist.org/packages/sanchescom/php-wifi)
[![License](https://img.shields.io/packagist/l/sanchescom/php-wifi.svg)](LICENSE.md)

# PHP WiFi

A modern, cross-platform PHP library for managing WiFi networks. Built with PHP 8.2+ and fully typed for better IDE support and type safety.

## Features

- **Cross-platform support**: Works on Linux, macOS, and Windows
- **Modern PHP 8.2+**: Fully typed properties, strict types, and modern syntax
- **Rich API**: Scan networks, connect/disconnect, filter by various parameters
- **Powerful filtering**: Filter by security type, signal strength, frequency band, and more
- **Collection-based**: Fluent interface with Laravel-style collections
- **Type-safe**: Full type hints and strict typing throughout

## Requirements

- PHP 8.2, 8.3, 8.4 or 8.5
- illuminate/collections 11, 12 or 13 (pulled in automatically; the constraint lets the package coexist with Laravel 11–13 applications)
- Operating System: Linux, macOS (Darwin), or Windows
- Appropriate system utilities (networksetup on macOS, nmcli on Linux, netsh on Windows)

### Installing

Require this package, with [Composer](https://getcomposer.org/), in the root directory of your project.

``` bash
$ composer require sanchescom/php-wifi
```

## Basic Usage

### Scanning for Networks

```php
<?php

use Sanchescom\WiFi\WiFi;

// Scan for all available networks
$networks = WiFi::scan();

// Get all networks as array
foreach ($networks->getAll() as $network) {
    echo sprintf(
        "SSID: %s, Signal: %.2f dBm, Channel: %d, Security: %s\n",
        $network->ssid,
        $network->dbm,
        $network->channel,
        $network->security
    );
}
```

### Connecting and Disconnecting

```php
<?php

use Sanchescom\WiFi\WiFi;

$device = 'en0'; // macOS: en0, en1; Linux: wlan0, wlan1; Windows: use netsh

try {
    // Connect to a specific network
    $network = WiFi::scan()->getBySsid('My-WiFi-Network');
    $network->connect('password123', $device);

    echo "Connected successfully!\n";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}

// Disconnect from all connected networks
$connectedNetworks = WiFi::getConnected();
foreach ($connectedNetworks as $network) {
    $network->disconnect($device);
}
```

## Advanced Usage

### Filtering Networks

```php
<?php

use Sanchescom\WiFi\WiFi;

// Get only 5GHz networks
$networks5ghz = WiFi::scan()->get5GhzNetworks();

// Get only 2.4GHz networks
$networks24ghz = WiFi::scan()->get24GhzNetworks();

// Filter by security type
$wpa2Networks = WiFi::scan()->getBySecurity('WPA2');

// Get networks with strong signal (above -60 dBm)
$strongNetworks = WiFi::scan()->getByMinSignalStrength(-60);

// Get networks on specific channel
$channel6Networks = WiFi::scan()->getByChannel(6);

// Combine filters (fluent interface)
$strongWPA2Networks = WiFi::scan()
    ->getBySecurity('WPA2')
    ->getByMinSignalStrength(-50)
    ->get5GhzNetworks();
```

### Finding Specific Networks

```php
<?php

use Sanchescom\WiFi\WiFi;
use Sanchescom\WiFi\Exceptions\NetworkNotFoundException;

try {
    // Get the strongest available network
    $strongestNetwork = WiFi::getStrongestNetwork();
    echo "Strongest network: {$strongestNetwork->ssid} ({$strongestNetwork->dbm} dBm)\n";

    // Find network by SSID
    $network = WiFi::scan()->getBySsid('MyNetwork');

    // Find network by BSSID (MAC address)
    $network = WiFi::scan()->getByBssid('4c:49:e3:f5:35:17');

} catch (NetworkNotFoundException $e) {
    echo "Network not found!\n";
}
```

### Sorting Networks

```php
<?php

use Sanchescom\WiFi\WiFi;

// Sort networks by signal strength (strongest first)
$sortedNetworks = WiFi::scan()->sortBySignalStrength();

foreach ($sortedNetworks as $network) {
    echo "{$network->ssid}: {$network->dbm} dBm\n";
}
```

### Working with Network Properties

```php
<?php

use Sanchescom\WiFi\WiFi;

$network = WiFi::scan()->getBySsid('MyNetwork');

// Access network properties
echo "SSID: {$network->ssid}\n";
echo "BSSID: {$network->bssid}\n";
echo "Channel: {$network->channel}\n";
echo "Frequency: {$network->frequency} MHz\n";
echo "Signal Quality: {$network->quality}%\n";
echo "Signal Strength: {$network->dbm} dBm\n";
echo "Security: {$network->security}\n";
echo "Security Flags: {$network->securityFlags}\n";
echo "Connected: " . ($network->connected ? 'Yes' : 'No') . "\n";
```

### Static Helper Methods

```php
<?php

use Sanchescom\WiFi\WiFi;

// Quick access to common operations
$connectedNetworks = WiFi::getConnected();
$strongest = WiFi::getStrongestNetwork();
$networks24ghz = WiFi::get24GhzNetworks();
$networks5ghz = WiFi::get5GhzNetworks();
$wpa2Networks = WiFi::getNetworksBySecurity('WPA2');
```

## CLI Usage

The library includes a command-line interface for managing WiFi networks directly from the terminal.

### List all available networks
```bash
./vendor/bin/wifi list
```

### List only connected networks
```bash
./vendor/bin/wifi list --connected
```

### Connect to a network
```bash
./vendor/bin/wifi connect --bssid=4c:49:e3:f5:35:17 --password=12345 --device=en1
```

### Disconnect from a network
```bash
./vendor/bin/wifi disconnect --bssid=4c:49:e3:f5:35:17 --device=en1
```

## API Reference

### WiFi Class (Static Methods)

| Method | Return Type | Description |
|--------|-------------|-------------|
| `WiFi::scan()` | `Collection` | Scan for all available networks |
| `WiFi::getConnected()` | `AbstractNetwork[]` | Get all connected networks |
| `WiFi::getStrongestNetwork()` | `AbstractNetwork` | Get the strongest available network |
| `WiFi::get24GhzNetworks()` | `Collection` | Get all 2.4GHz networks |
| `WiFi::get5GhzNetworks()` | `Collection` | Get all 5GHz networks |
| `WiFi::getNetworksBySecurity(string $type)` | `Collection` | Get networks by security type |

### Collection Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getAll()` | `AbstractNetwork[]` | Get all networks as array |
| `getBySsid(string $ssid)` | `AbstractNetwork` | Find network by SSID |
| `getByBssid(string $bssid)` | `AbstractNetwork` | Find network by BSSID (MAC) |
| `getConnected()` | `AbstractNetwork[]` | Get connected networks |
| `getBySecurity(string $type)` | `Collection` | Filter by security type |
| `getByMinSignalStrength(float $dbm)` | `Collection` | Filter by minimum signal strength |
| `getByChannel(int $channel)` | `Collection` | Filter by channel |
| `get24GhzNetworks()` | `Collection` | Get 2.4GHz networks |
| `get5GhzNetworks()` | `Collection` | Get 5GHz networks |
| `sortBySignalStrength()` | `Collection` | Sort by signal strength |
| `getStrongest()` | `AbstractNetwork` | Get strongest network |

### Network Properties

| Property | Type | Description |
|----------|------|-------------|
| `$ssid` | `string` | Network name |
| `$bssid` | `string` | MAC address |
| `$channel` | `int` | WiFi channel |
| `$frequency` | `int` | Frequency in MHz |
| `$quality` | `float` | Signal quality percentage |
| `$dbm` | `float` | Signal strength in dBm |
| `$security` | `string` | Security type |
| `$securityFlags` | `string` | Security flags |
| `$connected` | `bool` | Connection status |

### Network Methods

| Method | Parameters | Description |
|--------|------------|-------------|
| `connect()` | `string $password, string $device` | Connect to the network |
| `disconnect()` | `string $device` | Disconnect from the network |
| `getSecurityType()` | - | Get security type (WPA2/WPA/WEP) |

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

## Platform Support

### Linux
- Requires `nmcli` (NetworkManager command-line interface)
- Default device: `wlan0` or `wlan1`

### macOS

- **Scanning** uses `system_profiler SPAirPortDataType`. Apple gates Wi-Fi
  details behind Location Services: unless the process that runs PHP
  (Terminal, your web server, a LaunchAgent…) has been granted Location
  Services, every SSID comes back as the literal string `<redacted>`.
  `system_profiler` never reports BSSIDs for other networks, so `bssid` is
  always empty on macOS, `getByBssid()` cannot find anything, and the CLI's
  `connect --bssid` does not work on macOS — use `getBySsid()`.
- **Connection management** uses `networksetup` (built in). Default device:
  `en0`; find yours with `networksetup -listallhardwareports`.
- The legacy `airport` output format is still parsed for older systems; the
  `airport` binary itself was removed by Apple in macOS 14.4.

### Windows
- Requires `netsh` (built-in)
- Automatically detects WiFi interface

## What's New in v2.0

- **PHP 8.1+ Support**: Fully refactored with typed properties and modern PHP syntax
- **Updated Dependencies**: All dependencies updated to latest versions
- **Extended Functionality**: New filtering and sorting methods
- **Better Type Safety**: Full type hints throughout the codebase
- **Improved Collections**: Switched to `illuminate/collections` with fluent interface
- **macOS**: scanning moved from the removed `airport` binary to `system_profiler` (see Platform Support for what that can and cannot report)
- **New Features**:
  - Filter networks by frequency band (2.4GHz / 5GHz)
  - Filter by signal strength
  - Find strongest network
  - Sort networks by various criteria
  - Enhanced security filtering

## Upgrading from v1.x

The main breaking changes:
1. Minimum PHP version is now 8.1 (was 7.2)
2. Collection methods now have proper return types
3. `tightenco/collect` replaced with `illuminate/collections`

Most of the existing API remains compatible. New methods are additive.

## Testing

```bash
composer test
```

## Code Style

```bash
# Check code style
composer lint

# Fix code style automatically
composer fix
```

## Static Analysis

```bash
composer analyse
```

Runs PHPStan at level 5.

## Platform-Specific Notes

### Device Names
- **macOS**: Use `en0`, `en1`, etc. Find your device with `networksetup -listallhardwareports`
- **Linux**: Use `wlan0`, `wlan1`, etc. Find your device with `ip link show`
- **Windows**: Device is auto-detected by the library