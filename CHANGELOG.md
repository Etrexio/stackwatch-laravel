# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.3] - 2026-03-24

### Fixed
- **Spatie Backup v8+ compatibility** - Fixed `Call to undefined method amountOfBackups()` error in `SpatieBackupListener`
- Added backward-compatible helper methods that work with both Spatie Backup v7 and v8+:
  - `getBackupCount()` - gets backup count using `amountOfBackups()` (v7) or `backups()->count()` (v8)
  - `getNewestBackupAge()` - gets newest backup age in days
  - `getUsedStorage()` - gets used storage size
  - `getHealthCheckFailures()` - extracts health check failure messages

## [1.2.2] - 2026-03-24

### Changed
- **Per-transaction batch size enforcement** - Each transaction now independently waits until it reaches `batch_size` (default: 50) before being sent. Previously, the total count across all transactions was used, causing premature flushes.
- Transactions that haven't reached batch_size stay in the buffer while ready ones are flushed

### Improved
- `stackwatch:buffer status` now shows count progress (e.g., "23/50") and ready status for each transaction

## [1.2.1] - 2026-03-24

### Changed
- **Performance buffer now uses file storage instead of cache** - Buffer data is stored in `storage/stackwatch/` directory using JSON files with file locking. This ensures aggregation works reliably regardless of cache driver configuration.

### Added
- New `PerformanceBuffer` class for file-based performance data storage
- New `stackwatch:buffer` Artisan command to manage the performance buffer:
  - `stackwatch:buffer status` - View buffer statistics and buffered transactions
  - `stackwatch:buffer flush` - Manually flush buffer and send aggregated metrics
  - `stackwatch:buffer clear` - Clear buffer without sending (discards data)

### Fixed
- Performance aggregation now works with any cache driver (file, array, redis, etc.)
- Buffer data persists reliably between HTTP requests

## [1.2.0] - 2026-03-18

### Added
- **Flood Protection**: Prevents log storms and infinite loops from crashing applications
  - Fingerprint-based duplicate detection (same message max 5x per minute by default)
  - Circuit breaker that trips when 100+ logs occur in 10 seconds
  - Automatic message normalization (removes UUIDs, timestamps, IPs, etc.)
  - In-memory caching for high performance
- New `FloodProtection` class with static methods for manual control
- New configuration section `flood_protection` with customizable thresholds
- Environment variables: `STACKWATCH_FLOOD_PROTECTION`, `STACKWATCH_FLOOD_DUPLICATE_WINDOW`, `STACKWATCH_FLOOD_MAX_DUPLICATES`, `STACKWATCH_CIRCUIT_BREAKER`, `STACKWATCH_CIRCUIT_BREAKER_THRESHOLD`, `STACKWATCH_CIRCUIT_BREAKER_WINDOW`, `STACKWATCH_CIRCUIT_BREAKER_COOLDOWN`

## [1.1.1] - 2026-03-18

### Fixed
- Fixed infinite recursion loop in log listener (Laravel 8 compatibility)
- Added static re-entrancy guard to prevent recursive log capture
- Changed `isset()` to `!empty()` for safer null context handling
- Fixed potential stack overflow when rate limiting triggers internal logs

## [1.1.0] - 2026-03-18

### Added
- Laravel 8.x and 9.x compatibility
- PHP 8.0 support (lowered from PHP 8.1 requirement)

### Changed
- Updated `illuminate/*` package requirements to support `^8.0|^9.0|^10.0|^11.0|^12.0`
- Updated `orchestra/testbench` to `^6.0|^7.0|^8.0|^9.0` for broader test coverage
- Updated PHPUnit requirement to `^9.0|^10.0|^11.0`

## [1.0.1] - 2026-03-18

### Added
- AI-Assisted Installation section in README for vibe coders
- Complete environment variables reference with defaults in README
- Copy-paste ready prompt for AI coding assistants
- `.github/copilot-instructions.md` for AI agent guidance
- This CHANGELOG file

### Changed
- Improved README documentation structure

### Fixed
- Removed duplicate License section and stray PHP code from README

## [1.0.0] - 2026-03-18

### Added
- Initial release
- Automatic exception capture with full stack traces
- AI-powered insights and fix suggestions
- Performance monitoring with aggregation
- Breadcrumb support for error context
- User context capture
- Laravel log integration
- Spatie Laravel Backup integration
- Spatie Laravel Health integration
- Spatie Activity Log integration
- Rate limiting with event buffering
- Middleware for request tracking
- Console commands: `stackwatch:install`, `stackwatch:test`, `stackwatch:deploy`
