# StackWatch Laravel SDK

AI-powered application monitoring for Laravel applications.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/stackwatch/laravel.svg?style=flat-square)](https://packagist.org/packages/stackwatch/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/stackwatch/laravel.svg?style=flat-square)](https://packagist.org/packages/stackwatch/laravel)
[![License](https://img.shields.io/packagist/l/stackwatch/laravel.svg?style=flat-square)](https://packagist.org/packages/stackwatch/laravel)

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
- Configuring queue connection
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

## Rate Limiting

StackWatch includes smart rate limiting to prevent overwhelming the API:

```env
# Maximum events per minute
STACKWATCH_RATE_LIMIT_PER_MINUTE=60
```

When rate limited, events are automatically buffered and sent when the limit resets. No logs are lost!

To disable buffering (drop events when rate limited):

```php
// config/stackwatch.php
'rate_limit' => [
    'buffer_on_limit' => false,
],
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

## Queue Configuration

For production, use async queuing:

```env
STACKWATCH_QUEUE_CONNECTION=redis
STACKWATCH_QUEUE_NAME=stackwatch
```

Then run a dedicated worker:

```bash
php artisan queue:work redis --queue=stackwatch
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
| `STACKWATCH_PERFORMANCE_SAMPLE_RATE` | Performance sampling | `1.0` |
| `STACKWATCH_QUEUE_CONNECTION` | Queue connection | `sync` |
| `STACKWATCH_QUEUE_NAME` | Queue name | `stackwatch` |
| `STACKWATCH_SPATIE_BACKUP_ENABLED` | Backup integration | `true` |
| `STACKWATCH_SPATIE_HEALTH_ENABLED` | Health integration | `true` |
| `STACKWATCH_SPATIE_ACTIVITYLOG_ENABLED` | Activity log integration | `true` |

## Troubleshooting

### Events not appearing

1. Check your API key:
   ```bash
   php artisan stackwatch:test
   ```

2. Verify queue is running (if using async):
   ```bash
   php artisan queue:work --queue=stackwatch
   ```

3. Check for rate limiting:
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
        'max_breadcrumbs' => 50,
        'capture_logs' => true,
        'capture_queries' => true,
    ],
    
    // Performance monitoring
    'performance' => [
        'enabled' => true,
        'sample_rate' => 1.0, // 0.0 to 1.0
        'slow_query_threshold' => 100, // ms
    ],
    
    // Ignored exceptions
    'ignored_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],
];
```

## Testing

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE) for details.
