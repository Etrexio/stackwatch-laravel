<?php

namespace StackWatch\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use StackWatch\Laravel\Exceptions\StackWatchExceptionHandler;
use StackWatch\Laravel\Listeners\SpatieActivityLogListener;
use StackWatch\Laravel\Listeners\SpatieBackupListener;
use StackWatch\Laravel\Listeners\SpatieHealthListener;
use StackWatch\Laravel\Logging\StackWatchLogChannel;
use StackWatch\Laravel\Middleware\StackWatchMiddleware;
use StackWatch\Laravel\Transport\HttpTransport;

class StackWatchServiceProvider extends ServiceProvider
{
    /**
     * Static flag to prevent recursive log capture.
     */
    private static bool $isCapturingLog = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/stackwatch.php', 'stackwatch');

        // Register HTTP Transport
        $this->app->singleton(HttpTransport::class, function () {
            return new HttpTransport();
        });

        // Register StackWatch
        $this->app->singleton(StackWatch::class, function ($app) {
            return new StackWatch($app->make(HttpTransport::class));
        });

        // Extend exception handler
        if (config('stackwatch.capture_exceptions', true)) {
            $this->app->extend(ExceptionHandler::class, function (ExceptionHandler $handler, $app) {
                return new StackWatchExceptionHandler($handler, $app->make(StackWatch::class));
            });
        }
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/stackwatch.php' => config_path('stackwatch.php'),
        ], 'stackwatch-config');

        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('stackwatch', StackWatchMiddleware::class);

        // Register log channel
        $this->registerLogChannel();

        // Register breadcrumb listeners
        $this->registerBreadcrumbListeners();

        // Register integration listeners
        $this->registerIntegrationListeners();

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\TestCommand::class,
                Console\Commands\DeployCommand::class,
                Console\Commands\InstallCommand::class,
            ]);
        }
    }

    /**
     * Register the StackWatch log channel.
     */
    protected function registerLogChannel(): void
    {
        // Register as custom log channel
        $this->app['config']->set('logging.channels.stackwatch', [
            'driver' => 'custom',
            'via' => StackWatchLogChannel::class,
            'level' => config('stackwatch.logging.level', 'debug'),
        ]);

        // Auto-register to log stack if configured
        if (config('stackwatch.logging.auto_register', false)) {
            $stack = $this->app['config']->get('logging.channels.stack.channels', ['single']);
            
            if (!in_array('stackwatch', $stack)) {
                $stack[] = 'stackwatch';
                $this->app['config']->set('logging.channels.stack.channels', $stack);
            }
        }
    }

    protected function registerBreadcrumbListeners(): void
    {
        // Capture logs as events AND/OR breadcrumbs
        $this->registerLogListener();

        // Query breadcrumbs
        if (config('stackwatch.breadcrumbs.enabled', true) && config('stackwatch.breadcrumbs.capture_queries', true)) {
            DB::listen(function ($query) {
                $slowThreshold = config('stackwatch.performance.slow_query_threshold', 100);

                app(StackWatch::class)->addBreadcrumb(
                    'query',
                    $query->sql,
                    [
                        'bindings' => $query->bindings,
                        'time' => $query->time,
                        'slow' => $query->time > $slowThreshold,
                    ],
                    $query->time > $slowThreshold ? 'warning' : 'info'
                );
            });
        }
    }

    /**
     * Register log listener to capture Laravel logs.
     */
    protected function registerLogListener(): void
    {
        $captureAsEvents = config('stackwatch.logging.capture_as_events', true);
        $captureBreadcrumbs = config('stackwatch.breadcrumbs.enabled', true) 
            && config('stackwatch.breadcrumbs.capture_logs', true);

        if (!$captureAsEvents && !$captureBreadcrumbs) {
            return;
        }

        $minLevel = config('stackwatch.logging.level', 'debug');
        $sampleRate = config('stackwatch.logging.sample_rate', 1.0);
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $minLevelIndex = array_search($minLevel, $levels);

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (
            $captureAsEvents, $captureBreadcrumbs, $levels, $minLevelIndex, $sampleRate
        ) {
            // Prevent infinite loop - re-entrancy guard (most reliable)
            if (self::$isCapturingLog) {
                return;
            }

            // Check minimum level
            $eventLevelIndex = array_search($event->level, $levels);
            if ($eventLevelIndex === false || $eventLevelIndex < $minLevelIndex) {
                return;
            }

            // Prevent infinite loop - don't capture our own logs
            // Use !empty() for safer null handling (Laravel 8 compatibility)
            $context = $event->context ?? [];
            if (!empty($context['stackwatch_internal'])) {
                return;
            }

            self::$isCapturingLog = true;

            try {
                $stackWatch = app(StackWatch::class);

                // Add as breadcrumb
                if ($captureBreadcrumbs) {
                    $stackWatch->addBreadcrumb(
                        'log',
                        $event->message,
                        $context,
                        $event->level
                    );
                }

                // Send as event
                if ($captureAsEvents) {
                    // Apply sampling for non-error logs
                    $isError = in_array($event->level, ['error', 'critical', 'alert', 'emergency']);
                    if (!$isError && $sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
                        return;
                    }

                    $stackWatch->captureLog($event->message, $event->level, $context);
                }
            } finally {
                self::$isCapturingLog = false;
            }
        });
    }

    /**
     * Register third-party integration listeners.
     */
    protected function registerIntegrationListeners(): void
    {
        // Spatie Laravel Backup integration
        if (config('stackwatch.integrations.spatie_backup.enabled', true)
            && class_exists('Spatie\Backup\Events\BackupWasSuccessful')
        ) {
            Event::subscribe(SpatieBackupListener::class);
        }

        // Spatie Laravel Health integration
        if (config('stackwatch.integrations.spatie_health.enabled', true)
            && class_exists('Spatie\Health\Events\CheckEndedEvent')
        ) {
            Event::subscribe(SpatieHealthListener::class);
        }

        // Spatie Laravel Activity Log integration
        if (config('stackwatch.integrations.spatie_activitylog.enabled', true)
            && class_exists('Spatie\Activitylog\Events\ActivityLoggedEvent')
        ) {
            Event::subscribe(SpatieActivityLogListener::class);
        }
    }
}
