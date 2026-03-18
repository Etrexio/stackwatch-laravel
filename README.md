# StackWatch Laravel SDK

AI-powered application monitoring for Laravel applications.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stackwatch/laravel.svg?style=flat-square)](https://packagist.org/packages/stackwatch/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/stackwatch/laravel.svg?style=flat-square)](https://packagist.org/packages/stackwatch/laravel)
[![License](https://img.shields.io/packagist/l/stackwatch/laravel.svg?style=flat-square)](https://packagist.org/packages/stackwatch/laravel)

---

## 🤖 AI-Assisted Installation (For Vibe Coders)

Using an AI coding assistant like GitHub Copilot, Cursor, Claude, or ChatGPT? Copy and paste the prompt below to have your AI handle the entire setup:

### Copy This Entire Prompt

```
Install StackWatch Laravel SDK for application monitoring in my Laravel project.

================================================================================
MY PROJECT DETAILS
================================================================================
- API Key: [YOUR_STACKWATCH_API_KEY]
- Environment: [production/staging/local]
- Laravel Version: [10/11/12]

================================================================================
WHAT I NEED YOU TO DO
================================================================================
1. Install the package: composer require stackwatch/laravel
2. Run setup: php artisan stackwatch:install (or manually publish config)
3. Add environment variables to .env file
4. Register StackWatchMiddleware in my application
5. Enable log integration with auto-register
6. Test the connection: php artisan stackwatch:test

================================================================================
OPTIONAL INTEGRATIONS (check the ones you want)
================================================================================
- [ ] Spatie Laravel Backup monitoring
- [ ] Spatie Laravel Health checks
- [ ] Spatie Activity Log tracking
- [ ] Performance monitoring with aggregation
- [ ] Slow request alerts (threshold: ___ms)

================================================================================
MY PREFERENCES
================================================================================
- Log level: [debug/info/warning/error]
- Rate limit per minute: [60]
- Custom exceptions to ignore: [list any]

================================================================================
TECHNICAL REFERENCE FOR AI
================================================================================
Package: stackwatch/laravel
Namespace: StackWatch\Laravel
Facade: StackWatch\Laravel\Facades\StackWatch
Middleware: StackWatch\Laravel\Middleware\StackWatchMiddleware
Config File: config/stackwatch.php
Install Command: php artisan stackwatch:install
Test Command: php artisan stackwatch:test
Deploy Command: php artisan stackwatch:deploy --release=VERSION

================================================================================
ALL ENVIRONMENT VARIABLES (with defaults)
================================================================================

# CORE SETTINGS
STACKWATCH_API_KEY=your-api-key-here           # Required - Get from dashboard
STACKWATCH_ENDPOINT=https://api.stackwatch.dev/v1  # API endpoint
STACKWATCH_ENVIRONMENT=production              # Environment name (default: APP_ENV)
STACKWATCH_RELEASE=                            # Release version (default: APP_VERSION)
STACKWATCH_ENABLED=true                        # Enable/disable SDK entirely

# EXCEPTION TRACKING
STACKWATCH_CAPTURE_EXCEPTIONS=true             # Auto-capture exceptions

# LOG INTEGRATION
STACKWATCH_LOG_LEVEL=debug                     # Minimum log level to capture
STACKWATCH_CAPTURE_LOGS_AS_EVENTS=true         # Send logs as separate events
STACKWATCH_AUTO_REGISTER_LOG=false             # Auto-add to Laravel log stack
STACKWATCH_LOG_SAMPLE_RATE=1.0                 # Log sampling rate (0.0-1.0)

# RATE LIMITING
STACKWATCH_RATE_LIMIT_PER_MINUTE=60            # Max events per minute

# PERFORMANCE MONITORING
STACKWATCH_PERFORMANCE_ENABLED=true            # Enable performance monitoring
STACKWATCH_PERFORMANCE_GROUP_BY=path           # Group by 'path' or 'route'
STACKWATCH_PERFORMANCE_SAMPLE_RATE=0.1         # Sampling when aggregation disabled
STACKWATCH_PERFORMANCE_AGGREGATE=true          # Aggregate metrics before sending
STACKWATCH_PERFORMANCE_BATCH_SIZE=50           # Requests before sending aggregate
STACKWATCH_PERFORMANCE_FLUSH_INTERVAL=60       # Seconds before time-based flush
STACKWATCH_PERFORMANCE_MIN_FLUSH_COUNT=5       # Min requests for time-based flush
STACKWATCH_SLOW_REQUEST_THRESHOLD=3000         # Slow request threshold in ms

# SPATIE INTEGRATIONS
STACKWATCH_SPATIE_BACKUP_ENABLED=true          # Laravel Backup integration
STACKWATCH_SPATIE_HEALTH_ENABLED=true          # Laravel Health integration
STACKWATCH_SPATIE_ACTIVITYLOG_ENABLED=true     # Activity Log integration

================================================================================
MINIMUM REQUIRED .ENV
================================================================================
STACKWATCH_API_KEY=your-api-key-here
STACKWATCH_ENVIRONMENT=production

================================================================================
RECOMMENDED PRODUCTION .ENV
================================================================================
STACKWATCH_API_KEY=your-api-key-here
STACKWATCH_ENVIRONMENT=production
STACKWATCH_AUTO_REGISTER_LOG=true
STACKWATCH_CAPTURE_LOGS_AS_EVENTS=true
STACKWATCH_PERFORMANCE_ENABLED=true
STACKWATCH_PERFORMANCE_AGGREGATE=true
STACKWATCH_SLOW_REQUEST_THRESHOLD=3000
```

---

## Features

- 🔴 **Error Tracking** - Automatic exception capture with full stack traces
- 🤖 **AI Analysis** - Get AI-powered insights and fix suggestions
- ⚡ **Performance Monitoring** - Track response times and slow queries
- 🍞 **Breadcrumbs** - Automatic logging of events leading up to errors
- 👤 **User Context** - Automatically capture authenticated user info
- 📝 **Log Integration** - Capture all Laravel logs (debug, info, warning, error)
- 💾 **Backup Monitoring** - Spatie Laravel Backup integration
- 🏥 **Health Checks** - Spatie Laravel Health integration
- 📊 **Activity Logging** - Spatie Activity Log integration
- 🔔 **Notifications** - Get alerted via Slack, Discord, or email
- ⏱️ **Rate Limiting** - Smart rate limiting with event buffering

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x

## Installation

### Quick Install (Recommended)

```bash
composer require stackwatch/laravel
php artisan stackwatch:install
```

The install command will guide you through:
- Publishing the configuration
- Setting up your API key
- Testing the connection

### Manual Install

```bash
composer require stackwatch/laravel
php artisan vendor:publish --tag=stackwatch-config
```

Add to `.env`:

```env
STACKWATCH_API_KEY=your-api-key-here
STACKWATCH_ENVIRONMENT=production
```

Test your installation:

```bash
php artisan stackwatch:test
```

## Usage

### Automatic Exception Capture

Exceptions are automatically captured and sent to StackWatch. No additional code needed!

### Manual Exception Reporting

```php
use StackWatch\Laravel\Facades\StackWatch;

try {
    // Your code
} catch (Exception $e) {
    StackWatch::captureException($e);
}
```

### Capture Messages

```php
use StackWatch\Laravel\Facades\StackWatch;

// Info message
StackWatch::captureMessage('User signed up', 'info', [
    'plan' => 'pro',
]);

// Warning
StackWatch::captureMessage('Rate limit approaching', 'warning');

// Error
StackWatch::captureMessage('Payment failed', 'error', [
    'user_id' => $user->id,
]);
```

### Capture Custom Events

```php
use StackWatch\Laravel\Facades\StackWatch;

// Backup event
StackWatch::captureEvent('backup', 'info', 'Daily backup completed', [
    'size' => '2.5 GB',
    'duration' => '5 minutes',
]);

// Health check
StackWatch::captureEvent('health', 'warning', 'Disk space low', [
    'disk' => 'primary',
    'usage' => '85%',
]);
```

### Add Custom Context

```php
use StackWatch\Laravel\Facades\StackWatch;

// Set user context
StackWatch::setUser([
    'id' => $user->id,
    'email' => $user->email,
    'name' => $user->name,
]);

// Add tags
StackWatch::setTag('feature', 'checkout');
StackWatch::setTags([
    'version' => '2.0',
    'region' => 'eu-west',
]);

// Add extra context
StackWatch::setExtra('order_id', $order->id);

// Add custom context
StackWatch::setContext('payment', [
    'provider' => 'stripe',
    'amount' => 9900,
]);
```

### Add Breadcrumbs

```php
use StackWatch\Laravel\Facades\StackWatch;

StackWatch::addBreadcrumb('user', 'Clicked checkout button');
StackWatch::addBreadcrumb('api', 'Called payment API', [
    'provider' => 'stripe',
    'amount' => 9900,
]);
```

## Log Integration

StackWatch can capture all Laravel logs as separate events.

### Option 1: Auto-register (Recommended)

Add to `.env`:

```env
STACKWATCH_AUTO_REGISTER_LOG=true
STACKWATCH_LOG_LEVEL=debug
STACKWATCH_CAPTURE_LOGS_AS_EVENTS=true
```

### Option 2: Manual Configuration

In `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'stackwatch'],
    ],
    
    'stackwatch' => [
        'driver' => 'custom',
        'via' => \StackWatch\Laravel\Logging\StackWatchLogChannel::class,
        'level' => env('STACKWATCH_LOG_LEVEL', 'debug'),
    ],
],
```

### Log Sampling

To reduce volume for high-traffic applications:

```env
# Only send 10% of info/debug logs (errors always sent)
STACKWATCH_LOG_SAMPLE_RATE=0.1
```

## Middleware

Add the middleware to capture request context and performance data:

```php
// app/Http/Kernel.php (Laravel 10)
protected $middleware = [
    // ...
    \StackWatch\Laravel\Middleware\StackWatchMiddleware::class,
];

// Or for specific routes
Route::middleware(['stackwatch'])->group(function () {
    // Routes
});

// Laravel 11+ (bootstrap/app.php)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\StackWatch\Laravel\Middleware\StackWatchMiddleware::class);
})
```

## Integrations

### Spatie Laravel Backup

Automatically monitors your backups when [spatie/laravel-backup](https://github.com/spatie/laravel-backup) is installed.

```bash
composer require spatie/laravel-backup
```

Events captured:
- ✅ Backup successful
- ❌ Backup failed
- 🧹 Cleanup successful/failed
- 🏥 Backup health checks

To disable:

```env
STACKWATCH_SPATIE_BACKUP_ENABLED=false
```

### Spatie Laravel Health

Monitors health checks when [spatie/laravel-health](https://github.com/spatie/laravel-health) is installed.

```bash
composer require spatie/laravel-health
```

Only failed or warning health checks are sent to reduce noise.

To disable:

```env
STACKWATCH_SPATIE_HEALTH_ENABLED=false
```

### Spatie Activity Log

Captures activity logs when [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog) is installed.

```bash
composer require spatie/laravel-activitylog
```

Filter by log name or event type in `config/stackwatch.php`:

```php
'integrations' => [
    'spatie_activitylog' => [
        'enabled' => true,
        'log_names' => ['default', 'audit'], // Only these log names
        'event_types' => ['created', 'deleted'], // Only these events
    ],
],
```

## Ignored Exceptions

By default, StackWatch ignores common exceptions that are not actual errors:

- `NotFoundHttpException` (404 errors)
- `ModelNotFoundException` (404 for missing models)
- `AuthenticationException` (401 errors)
- `AuthorizationException` (403 errors)
- `ValidationException` (422 validation errors)
- `TokenMismatchException` (CSRF errors)

To customize ignored exceptions, modify `config/stackwatch.php`:

```php
'ignored_exceptions' => [
    // Keep defaults
    Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    Illuminate\Database\Eloquent\ModelNotFoundException::class,
    Illuminate\Auth\AuthenticationException::class,
    Illuminate\Auth\Access\AuthorizationException::class,
    Illuminate\Validation\ValidationException::class,
    Illuminate\Session\TokenMismatchException::class,
    
    // Add your own
    App\Exceptions\SomeCustomException::class,
],
```

To report all exceptions (including 404s):

```php
'ignored_exceptions' => [],
```

## Rate Limiting

StackWatch includes smart rate limiting to prevent overwhelming the API:

```env
# Maximum events per minute
STACKWATCH_RATE_LIMIT_PER_MINUTE=60
```

When rate limited, events are automatically buffered and sent with the next successful request. No events are lost!

To disable buffering (drop events when rate limited):

```php
// config/stackwatch.php
'rate_limit' => [
    'buffer_on_limit' => false,
],
```

## Performance Monitoring

Performance monitoring is enabled by default with smart defaults to minimize overhead:

### Transaction Grouping

Configure how requests are grouped for aggregation:

```env
# Group by full path (default) - each unique URL tracked separately
# e.g., "GET /blog/post-1", "GET /blog/post-2"
STACKWATCH_PERFORMANCE_GROUP_BY=path

# Group by route name - same endpoint grouped together
# e.g., "GET blog.show" (includes all blog posts)
STACKWATCH_PERFORMANCE_GROUP_BY=route
```

### Aggregation (Default)

Instead of sending every request, StackWatch aggregates metrics and sends summaries:

- Collects requests until batch size reached (default: 50)
- Sends aggregated stats: avg/min/max duration, error rate, request count
- Time-based flush: after interval passes AND minimum count reached
- Prevents sending useless aggregates with only 1-2 requests

```env
# Disable aggregation (send individual requests with sampling)
STACKWATCH_PERFORMANCE_AGGREGATE=false

# Number of requests to trigger immediate aggregate send
STACKWATCH_PERFORMANCE_BATCH_SIZE=50

# Seconds to wait before time-based flush
STACKWATCH_PERFORMANCE_FLUSH_INTERVAL=60

# Minimum requests required for time-based flush
# If only 3 requests after 60s, wait until 5 or batch_size reached
STACKWATCH_PERFORMANCE_MIN_FLUSH_COUNT=5
```

### Slow Requests

Requests slower than the threshold are **always** sent immediately (not aggregated):

```env
# Requests over 3000ms (3s) are always reported (default)
STACKWATCH_SLOW_REQUEST_THRESHOLD=3000

# Lower threshold for more sensitive monitoring
STACKWATCH_SLOW_REQUEST_THRESHOLD=1000
```

### Sampling (When Aggregation Disabled)

If aggregation is disabled, sampling controls how many requests are sent:

```env
# Only send 10% of requests (default)
STACKWATCH_PERFORMANCE_SAMPLE_RATE=0.1

# Send all requests (not recommended for production)
STACKWATCH_PERFORMANCE_SAMPLE_RATE=1.0
```

### Disable Performance Monitoring

```env
STACKWATCH_PERFORMANCE_ENABLED=false
```

## Deployment Notifications

Notify StackWatch when you deploy:

```bash
php artisan stackwatch:deploy --release=v1.2.3
```

Or in your CI/CD pipeline:

```bash
php artisan stackwatch:deploy --release=$GITHUB_SHA
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `STACKWATCH_API_KEY` | Your API key (required) | - |
| `STACKWATCH_ENDPOINT` | API endpoint | `https://api.stackwatch.dev/v1` |
| `STACKWATCH_ENVIRONMENT` | Environment name | `APP_ENV` |
| `STACKWATCH_RELEASE` | Release version | `APP_VERSION` |
| `STACKWATCH_ENABLED` | Enable/disable | `true` |
| `STACKWATCH_CAPTURE_EXCEPTIONS` | Auto-capture exceptions | `true` |
| `STACKWATCH_LOG_LEVEL` | Minimum log level | `debug` |
| `STACKWATCH_CAPTURE_LOGS_AS_EVENTS` | Send logs as events | `true` |
| `STACKWATCH_AUTO_REGISTER_LOG` | Auto-add to log stack | `false` |
| `STACKWATCH_LOG_SAMPLE_RATE` | Log sampling rate (0-1) | `1.0` |
| `STACKWATCH_RATE_LIMIT_PER_MINUTE` | Rate limit | `60` |
| `STACKWATCH_PERFORMANCE_ENABLED` | Performance monitoring | `true` |
| `STACKWATCH_PERFORMANCE_GROUP_BY` | Group by 'path' or 'route' | `path` |
| `STACKWATCH_PERFORMANCE_SAMPLE_RATE` | Performance sampling (when aggregation disabled) | `0.1` |
| `STACKWATCH_PERFORMANCE_AGGREGATE` | Aggregate performance metrics | `true` |
| `STACKWATCH_PERFORMANCE_BATCH_SIZE` | Requests before sending aggregate | `50` |
| `STACKWATCH_PERFORMANCE_FLUSH_INTERVAL` | Seconds before time-based flush | `60` |
| `STACKWATCH_PERFORMANCE_MIN_FLUSH_COUNT` | Min requests for time-based flush | `5` |
| `STACKWATCH_SLOW_REQUEST_THRESHOLD` | Slow request threshold (ms) | `3000` |
| `STACKWATCH_SPATIE_BACKUP_ENABLED` | Backup integration | `true` |
| `STACKWATCH_SPATIE_HEALTH_ENABLED` | Health integration | `true` |
| `STACKWATCH_SPATIE_ACTIVITYLOG_ENABLED` | Activity log integration | `true` |

## Troubleshooting

### Events not appearing

1. Check your API key:
   ```bash
   php artisan stackwatch:test
   ```

2. Check for rate limiting:
   ```bash
   # View buffered events count
   php artisan tinker
   >>> app(\StackWatch\Laravel\Transport\HttpTransport::class)->getBufferSize()
   ```

### High memory usage

Reduce breadcrumb count:
```php
// config/stackwatch.php
'breadcrumbs' => [
    'max_breadcrumbs' => 20, // Default: 50
],
```

### Too many events

Use sampling:
```env
STACKWATCH_LOG_SAMPLE_RATE=0.1
STACKWATCH_PERFORMANCE_SAMPLE_RATE=0.5
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@stackwatch.dev instead of using the issue tracker.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
