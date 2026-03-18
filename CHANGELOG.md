# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
