# CLAUDE.md — StackWatch Laravel SDK (`stackwatch/laravel`)

Composer package that Laravel apps install to send logs, exceptions, performance data, deploys and Spatie backup/health/activity events to the StackWatch platform (separate repo: `Etrexio/stackwatch_system`). It runs **inside customers' production apps**, so stability and backward compatibility come first.

## Language

- **Talk to the user in Turkish.** All chat replies, summaries, questions and explanations are in Turkish.
- **Everything in the repo is in English:** code, identifiers, comments, docblocks, commit messages, PR texts, README/CHANGELOG, console output, test names.

## Supported versions

- PHP `^8.0`, Laravel 8–12 (`illuminate/*` `^8.0|...|^12.0`), Guzzle 7.
- Do not use language or framework features that break the lowest supported versions (e.g. no PHP 8.1+ enums/readonly props, no Laravel-11-only APIs) unless the user decides to drop a version — that is a breaking change and goes in `CHANGELOG.md`.

## Layout (keep it modular)

```
src/
  StackWatch.php                  # core client API (behind the Facade)
  StackWatchServiceProvider.php   # registration, config publishing, auto-discovery
  Facades/StackWatch.php
  Transport/HttpTransport.php     # all network I/O lives here
  Jobs/                           # SendEventJob, FlushEventBufferJob
  PerformanceBuffer.php, FloodProtection.php
  Exceptions/  Middleware/  Logging/  Listeners/ (Spatie integrations)  Console/Commands/
config/stackwatch.php
tests/
```

### Modularity rules — always preserve

- One responsibility per class. Network I/O only in `Transport/`; buffering/flood logic in their own classes; integrations as separate `Listeners/`.
- Optional integrations (Spatie backup/health/activitylog) must stay optional: register them only when the package class exists (`class_exists`), never add them to `require`.
- New features get their own class and a config switch in `config/stackwatch.php` (with an `env()` default), not branches inside existing classes.
- Public API (`StackWatch` facade methods, config keys, artisan command signatures, event payload shape) is a contract. Don't rename/remove; deprecate first and document in `CHANGELOG.md`.
- Payload changes must stay compatible with the ingestion API in `stackwatch_system` (`Api/V1`).

## Laravel package conventions

- Follow Laravel package conventions: service provider with `register()`/`boot()`, `mergeConfigFrom` + `publishes`, auto-discovery via `composer.json` `extra.laravel`.
- Use `config('stackwatch.*')`, never `env()` outside `config/stackwatch.php`.
- Namespace `StackWatch\Laravel\…`, PSR-4, PSR-12 style matching existing code.

## Never break the host app

- The SDK must **never throw into the host application**. Catch and swallow (or log locally) transport/serialization failures; a StackWatch outage must not take down a customer's app.
- Never block the request: heavy work goes through the buffer/queue jobs; respect timeouts.
- Avoid recursion: the SDK's own errors/logs must not be re-captured and sent (see `LogDeduplicationTest`).
- Never send secrets: keep scrubbing of passwords, tokens, cookies, auth headers intact.

## Data safety in tests

- Tests must never make real HTTP calls to a StackWatch server or any external host — mock `HttpTransport` / use Guzzle `MockHandler`.
- Tests must not touch real files, caches or databases outside a temp directory they create and remove themselves.
- Never run commands that send data (`stackwatch:test`, `stackwatch:deploy`) against a real API key unless the user asks.

## Testing

```bash
composer install
vendor/bin/phpunit            # or: composer test
vendor/bin/phpunit --filter=HttpTransportTest
```

- Bug fix or new behaviour → add or update a test.
- Keep tests green on the lowest and highest supported PHP/Laravel versions when feasible.

## Git & releases

- Small, focused commits with English, imperative messages.
- Every user-visible change gets a `CHANGELOG.md` entry; follow SemVer (breaking → major).
- Do not push to `main`, tag releases or force-push without the user's approval.
