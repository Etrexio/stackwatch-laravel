<?php

return [
    /*
    |--------------------------------------------------------------------------
    | StackWatch API Key
    |--------------------------------------------------------------------------
    |
    | Your StackWatch project API key. You can find this in your StackWatch
    | dashboard under Project Settings > API Keys.
    |
    */
    'api_key' => env('STACKWATCH_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | StackWatch API Endpoint
    |--------------------------------------------------------------------------
    |
    | The API endpoint where events will be sent. You typically don't need
    | to change this unless you're using a self-hosted instance.
    |
    */
    'endpoint' => env('STACKWATCH_ENDPOINT', 'https://api.stackwatch.dev/v1'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The environment name that will be attached to all events. This helps
    | you filter events by environment in the StackWatch dashboard.
    |
    */
    'environment' => env('STACKWATCH_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Release Version
    |--------------------------------------------------------------------------
    |
    | The release version of your application. This helps track which
    | version introduced specific errors.
    |
    */
    'release' => env('STACKWATCH_RELEASE', env('APP_VERSION')),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable StackWatch. When disabled, no events will be sent.
    | Useful for disabling in local development.
    |
    */
    'enabled' => env('STACKWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Capture Exceptions
    |--------------------------------------------------------------------------
    |
    | Automatically capture unhandled exceptions. Set to false if you want
    | to manually report exceptions only.
    |
    */
    'capture_exceptions' => env('STACKWATCH_CAPTURE_EXCEPTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Capture Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | Capture breadcrumbs (logs, queries, HTTP requests) leading up to errors.
    |
    */
    'breadcrumbs' => [
        'enabled' => true,
        'max_breadcrumbs' => 50,
        'capture_logs' => true,
        'capture_queries' => true,
        'capture_http_requests' => true,
        'capture_cache_operations' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how Laravel logs are captured and sent to StackWatch.
    |
    */
    'logging' => [
        // Minimum log level to capture: debug, info, warning, error, critical
        'level' => env('STACKWATCH_LOG_LEVEL', 'debug'),

        // Send logs as separate events (true) or just as breadcrumbs (false)
        'capture_as_events' => env('STACKWATCH_CAPTURE_LOGS_AS_EVENTS', true),

        // Auto-register stackwatch channel to Laravel's log stack
        'auto_register' => env('STACKWATCH_AUTO_REGISTER_LOG', false),

        // Sampling rate for non-error logs (0.0 to 1.0)
        // Set to 0.1 to only send 10% of info/debug logs
        'sample_rate' => env('STACKWATCH_LOG_SAMPLE_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting to prevent overwhelming the API.
    | When rate limited, events are buffered and sent later.
    |
    */
    'rate_limit' => [
        // Maximum events per minute (local rate limiting)
        'per_minute' => env('STACKWATCH_RATE_LIMIT_PER_MINUTE', 60),

        // Buffer events when rate limited instead of dropping them
        'buffer_on_limit' => true,

        // Maximum number of events to buffer
        'max_buffer_size' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Flood Protection
    |--------------------------------------------------------------------------
    |
    | Protect against log floods that could crash your application or create
    | massive log files. Detects duplicate messages and rate spikes.
    |
    */
    'flood_protection' => [
        // Enable/disable flood protection entirely
        'enabled' => env('STACKWATCH_FLOOD_PROTECTION', true),

        // Time window in seconds for duplicate detection
        // Same message within this window counts as duplicate
        'duplicate_window' => env('STACKWATCH_FLOOD_DUPLICATE_WINDOW', 60),

        // Maximum times the same message can be sent within the window
        // After this, duplicates are suppressed until window resets
        'max_duplicates' => env('STACKWATCH_FLOOD_MAX_DUPLICATES', 5),

        // Circuit breaker - trips when log volume is too high
        // Protects against infinite loops and log storms
        'circuit_breaker' => [
            'enabled' => env('STACKWATCH_CIRCUIT_BREAKER', true),

            // Number of logs within window that trips the breaker
            'threshold' => env('STACKWATCH_CIRCUIT_BREAKER_THRESHOLD', 100),

            // Time window in seconds for threshold detection
            'window' => env('STACKWATCH_CIRCUIT_BREAKER_WINDOW', 10),

            // Cooldown period in seconds after breaker trips
            // During this time, all logs are blocked
            'cooldown' => env('STACKWATCH_CIRCUIT_BREAKER_COOLDOWN', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Enable performance monitoring to track request times, database queries,
    | and other performance metrics.
    |
    */
    'performance' => [
        'enabled' => env('STACKWATCH_PERFORMANCE_ENABLED', true),
        
        // How to group/name transactions for aggregation
        // 'route' = Use route name (e.g., "GET cases.show") - recommended, groups same endpoint
        // 'path'  = Use full path (e.g., "GET /cases/my-case") - each unique URL separate
        'group_by' => env('STACKWATCH_PERFORMANCE_GROUP_BY', 'path'),
        
        // Sampling rate for normal requests (0.0 to 1.0)
        // Default 0.1 = only 10% of requests are sampled
        'sample_rate' => env('STACKWATCH_PERFORMANCE_SAMPLE_RATE', 0.1),
        
        // Slow request threshold in milliseconds
        // Requests slower than this are ALWAYS sent (ignores sample_rate)
        'slow_request_threshold' => env('STACKWATCH_SLOW_REQUEST_THRESHOLD', 3000),
        
        // Slow query threshold for breadcrumbs
        'slow_query_threshold' => 100, // milliseconds
        
        // Aggregation settings
        // Instead of sending each request, aggregate N requests and send summary
        'aggregate' => [
            'enabled' => env('STACKWATCH_PERFORMANCE_AGGREGATE', true),
            
            // Number of requests to collect before sending aggregate
            'batch_size' => env('STACKWATCH_PERFORMANCE_BATCH_SIZE', 50),
            
            // Seconds - flush even if batch not full (requires min_flush_count)
            'flush_interval' => env('STACKWATCH_PERFORMANCE_FLUSH_INTERVAL', 60),
            
            // Minimum requests required for time-based flush
            // Prevents sending aggregates with only 1-2 requests
            'min_flush_count' => env('STACKWATCH_PERFORMANCE_MIN_FLUSH_COUNT', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Context
    |--------------------------------------------------------------------------
    |
    | Automatically capture user context from the authenticated user.
    |
    */
    'user_context' => [
        'enabled' => true,
        'fields' => ['id', 'email', 'name'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Context
    |--------------------------------------------------------------------------
    |
    | Capture request context including URL, method, headers, and body.
    |
    */
    'request_context' => [
        'enabled' => true,
        'capture_headers' => true,
        'capture_body' => false,
        'headers_blacklist' => [
            'authorization',
            'cookie',
            'x-xsrf-token',
        ],
        'body_blacklist' => [
            'password',
            'password_confirmation',
            'credit_card',
            'card_number',
            'cvv',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | Exceptions that should not be reported to StackWatch.
    |
    */
    'ignored_exceptions' => [
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
        Illuminate\Session\TokenMismatchException::class,
        Illuminate\Validation\ValidationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | Configure integrations with third-party packages.
    |
    */
    'integrations' => [
        // Spatie Laravel Backup integration
        'spatie_backup' => [
            'enabled' => env('STACKWATCH_SPATIE_BACKUP_ENABLED', true),
        ],

        // Spatie Laravel Health integration
        'spatie_health' => [
            'enabled' => env('STACKWATCH_SPATIE_HEALTH_ENABLED', true),
        ],

        // Spatie Laravel Activity Log integration
        'spatie_activitylog' => [
            'enabled' => env('STACKWATCH_SPATIE_ACTIVITYLOG_ENABLED', true),
            // Only capture specific log names (empty = all)
            'log_names' => [],
            // Only capture specific event types (empty = all)
            'event_types' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    |
    | Configure the HTTP client used to send events.
    |
    */
    'http' => [
        'timeout' => 5,
        'retry_attempts' => 3,
        'retry_delay' => 100, // milliseconds
    ],
];
