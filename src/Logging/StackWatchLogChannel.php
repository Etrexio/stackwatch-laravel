<?php

namespace StackWatch\Laravel\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use StackWatch\Laravel\StackWatch;

class StackWatchLogChannel
{
    public function __invoke(array $config): Logger
    {
        // Get log level from config, env, or default to DEBUG to capture all logs
        $levelName = $config['level'] ?? env('STACKWATCH_LOG_LEVEL', 'debug');
        $level = $this->parseLevel($levelName);

        $logger = new Logger('stackwatch');
        $logger->pushHandler(new StackWatchHandler($level));

        return $logger;
    }

    /**
     * Parse log level from string or integer.
     */
    protected function parseLevel(string|int $level): int
    {
        if (is_int($level)) {
            return $level;
        }

        return match (strtolower($level)) {
            'debug' => Logger::DEBUG,
            'info' => Logger::INFO,
            'notice' => Logger::NOTICE,
            'warning', 'warn' => Logger::WARNING,
            'error' => Logger::ERROR,
            'critical' => Logger::CRITICAL,
            'alert' => Logger::ALERT,
            'emergency' => Logger::EMERGENCY,
            default => Logger::DEBUG,
        };
    }
}

class StackWatchHandler extends AbstractProcessingHandler
{
    protected float $sampleRate;
    protected bool $captureAsEvents;

    public function __construct(int|string|\Monolog\Level $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->sampleRate = config('stackwatch.logging.sample_rate', 1.0);
        $this->captureAsEvents = config('stackwatch.logging.capture_as_events', true);
    }

    protected function write(LogRecord $record): void
    {
        // Apply sampling rate for non-error logs to prevent overwhelming the API
        $level = strtolower($record->level->name);
        
        if (!in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            if ($this->sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $this->sampleRate) {
                return; // Skip this log based on sampling
            }
        }

        // Map Monolog levels to StackWatch levels
        $levelMap = [
            'debug' => 'debug',
            'info' => 'info',
            'notice' => 'info',
            'warning' => 'warning',
            'error' => 'error',
            'critical' => 'critical',
            'alert' => 'critical',
            'emergency' => 'critical',
        ];

        $stackwatchLevel = $levelMap[$level] ?? 'info';

        // For error+ levels, capture as exception if available
        if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            if (isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
                app(StackWatch::class)->captureException(
                    $record->context['exception'],
                    ['log_message' => $record->message]
                );

                return;
            }
        }

        // Skip if not configured to capture logs as separate events
        if (!$this->captureAsEvents) {
            // Just add as breadcrumb instead
            app(StackWatch::class)->addBreadcrumb(
                'log',
                $record->message,
                $record->context,
                $stackwatchLevel
            );
            return;
        }

        // Capture as separate log event
        app(StackWatch::class)->captureLog(
            $record->message,
            $stackwatchLevel,
            array_merge($record->context, [
                'channel' => $record->channel,
                'datetime' => $record->datetime->format('c'),
                'extra' => $record->extra,
            ])
        );
    }
}
