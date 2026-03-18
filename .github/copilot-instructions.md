# StackWatch Laravel SDK - Development Instructions

## Semantic Versioning Rules

This package follows [Semantic Versioning 2.0.0](https://semver.org/).

### Version Format: `MAJOR.MINOR.PATCH`

- **PATCH** (x.x.X): Bug fixes, typo corrections, minor improvements
  - Example: `1.0.0` → `1.0.1`
  - No breaking changes, backward compatible
  
- **MINOR** (x.X.0): New features, new configuration options
  - Example: `1.0.1` → `1.1.0`
  - Backward compatible, no breaking changes
  
- **MAJOR** (X.0.0): Breaking changes, API modifications
  - Example: `1.1.0` → `2.0.0`
  - May require user code changes

### Creating a Release

After making changes, create a git tag:

```bash
# For bug fixes
git tag -a v1.0.1 -m "Fix: description of fix"

# For new features
git tag -a v1.1.0 -m "Feature: description of feature"

# For breaking changes
git tag -a v2.0.0 -m "Breaking: description of change"

# Push the tag
git push origin --tags
```

### Changelog

**IMPORTANT: ALWAYS update `CHANGELOG.md` when making any code changes.**

Before committing:
1. Check the current version in `CHANGELOG.md`
2. Add your changes under the appropriate category
3. If releasing a new version, add a new version header with today's date

Categories to use:
- **Added**: New features or files
- **Changed**: Changes to existing functionality
- **Fixed**: Bug fixes
- **Removed**: Removed features or files
- **Security**: Security-related changes
- **Deprecated**: Features to be removed in future versions

Format example:
```markdown
## [1.0.2] - 2026-03-19

### Added
- New feature description

### Fixed
- Bug fix description
```

**Workflow:**
1. Make your code changes
2. Update CHANGELOG.md with your changes under `[Unreleased]` or new version
3. Commit both code and CHANGELOG.md together
4. If releasing: create git tag matching the version

## Code Standards

### PHP
- PHP 8.1+ required
- PSR-4 autoloading
- PSR-12 coding style
- Use strict types where possible

### Laravel Compatibility
- Support Laravel 10.x, 11.x, and 12.x
- Test against all supported versions

### Namespace
- Root namespace: `StackWatch\Laravel`
- Facades: `StackWatch\Laravel\Facades`
- Middleware: `StackWatch\Laravel\Middleware`

## Testing

Run tests before any release:

```bash
composer test
```

## Environment Variables

All environment variables must:
1. Start with `STACKWATCH_` prefix
2. Have sensible defaults
3. Be documented in README.md
4. Be listed in `config/stackwatch.php`

## Breaking Changes Checklist

Before releasing a major version:
- [ ] Document all breaking changes
- [ ] Provide migration guide
- [ ] Update README examples
- [ ] Test upgrade path from previous version
