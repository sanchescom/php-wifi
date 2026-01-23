# Changelog

All notable changes to this project will be documented in this file.

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
