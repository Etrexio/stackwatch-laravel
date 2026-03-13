<?php

namespace StackWatch\Laravel;

use Illuminate\Support\Facades\Auth;
use StackWatch\Laravel\Transport\HttpTransport;
use Throwable;

class StackWatch
{
    protected HttpTransport $transport;
    protected array $context = [];
    protected array $breadcrumbs = [];
    protected ?array $user = null;
    protected array $tags = [];
    protected array $extra = [];

    public function __construct(HttpTransport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Capture and report an exception.
     */
    public function captureException(Throwable $exception, array $context = []): ?string
    {
        if (! $this->shouldCapture($exception)) {
            return null;
        }

        $event = $this->buildExceptionEvent($exception, $context);

        return $this->transport->send($event);
    }

    /**
     * Capture and report a message.
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): ?string
    {
        $event = $this->buildMessageEvent($message, $level, $context);

        return $this->transport->send($event);
    }

    /**
     * Capture performance data.
     */
    public function capturePerformance(array $data): ?string
    {
        if (! config('stackwatch.performance.enabled', true)) {
            return null;
        }

        // Build event with API-expected format
        $event = array_merge([
            'type' => 'performance',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('stackwatch.environment'),
            'release' => config('stackwatch.release'),
        ], $data);

        // Add context if not already present
        if (!isset($event['context'])) {
            $event['context'] = $this->getFullContext();
        }

        return $this->transport->send($event);
    }

    /**
     * Capture a log event.
     */
    public function captureLog(string $message, string $level = 'info', array $context = []): ?string
    {
        if (! config('stackwatch.enabled', true)) {
            return null;
        }

        $event = [
            'type' => 'log',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('stackwatch.environment'),
            'release' => config('stackwatch.release'),
            'level' => $level,
            'message' => $message,
            'context' => array_merge($this->getFullContext(), $context),
            'user' => $this->user,
            'tags' => $this->tags,
        ];

        return $this->transport->send($event);
    }

    /**
     * Capture a generic event (backup, health, activity, etc.).
     */
    public function captureEvent(string $type, string $level, string $message, array $context = []): ?string
    {
        if (! config('stackwatch.enabled', true)) {
            return null;
        }

        $event = [
            'type' => $type,
            'timestamp' => now()->toIso8601String(),
            'environment' => config('stackwatch.environment'),
            'release' => config('stackwatch.release'),
            'level' => $level,
            'message' => $message,
            'breadcrumbs' => $this->breadcrumbs,
            'context' => array_merge($this->getFullContext(), $context),
            'user' => $this->user,
            'tags' => $this->tags,
            'extra' => $this->extra,
        ];

        return $this->transport->send($event);
    }

    /**
     * Add a breadcrumb.
     */
    public function addBreadcrumb(string $category, string $message, array $data = [], string $level = 'info'): self
    {
        if (! config('stackwatch.breadcrumbs.enabled', true)) {
            return $this;
        }

        $maxBreadcrumbs = config('stackwatch.breadcrumbs.max_breadcrumbs', 50);

        $this->breadcrumbs[] = [
            'timestamp' => now()->toIso8601String(),
            'category' => $category,
            'message' => $message,
            'data' => $data,
            'level' => $level,
        ];

        // Keep only the most recent breadcrumbs
        if (count($this->breadcrumbs) > $maxBreadcrumbs) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, -$maxBreadcrumbs);
        }

        return $this;
    }

    /**
     * Set the current user context.
     */
    public function setUser(?array $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Set user from authenticated user.
     */
    public function setUserFromAuth(): self
    {
        if (! config('stackwatch.user_context.enabled', true)) {
            return $this;
        }

        $user = Auth::user();

        if ($user) {
            $fields = config('stackwatch.user_context.fields', ['id', 'email', 'name']);
            $userData = [];

            foreach ($fields as $field) {
                if (isset($user->{$field})) {
                    $userData[$field] = $user->{$field};
                }
            }

            $this->user = $userData;
        }

        return $this;
    }

    /**
     * Add a tag.
     */
    public function setTag(string $key, string $value): self
    {
        $this->tags[$key] = $value;

        return $this;
    }

    /**
     * Set multiple tags.
     */
    public function setTags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);

        return $this;
    }

    /**
     * Set extra context data.
     */
    public function setExtra(string $key, mixed $value): self
    {
        $this->extra[$key] = $value;

        return $this;
    }

    /**
     * Set additional context.
     */
    public function setContext(string $key, array $value): self
    {
        $this->context[$key] = $value;

        return $this;
    }

    /**
     * Clear all breadcrumbs.
     */
    public function clearBreadcrumbs(): self
    {
        $this->breadcrumbs = [];

        return $this;
    }

    /**
     * Get current breadcrumbs.
     */
    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * Check if exception should be captured.
     */
    protected function shouldCapture(Throwable $exception): bool
    {
        if (! config('stackwatch.enabled', true)) {
            return false;
        }

        if (! config('stackwatch.capture_exceptions', true)) {
            return false;
        }

        $ignoredExceptions = config('stackwatch.ignored_exceptions', []);

        foreach ($ignoredExceptions as $ignoredException) {
            if ($exception instanceof $ignoredException) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build an exception event payload.
     */
    protected function buildExceptionEvent(Throwable $exception, array $context = []): array
    {
        return [
            'type' => 'error',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('stackwatch.environment'),
            'release' => config('stackwatch.release'),
            'level' => 'error',
            'message' => $exception->getMessage(),
            'exception' => [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'stack_trace' => $this->formatStackTrace($exception),
            ],
            'breadcrumbs' => $this->breadcrumbs,
            'context' => array_merge($this->getFullContext(), $context),
            'user' => $this->user,
            'tags' => $this->tags,
            'extra' => $this->extra,
        ];
    }

    /**
     * Build a message event payload.
     */
    protected function buildMessageEvent(string $message, string $level, array $context = []): array
    {
        return [
            'type' => 'message',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('stackwatch.environment'),
            'release' => config('stackwatch.release'),
            'level' => $level,
            'message' => $message,
            'breadcrumbs' => $this->breadcrumbs,
            'context' => array_merge($this->getFullContext(), $context),
            'user' => $this->user,
            'tags' => $this->tags,
            'extra' => $this->extra,
        ];
    }

    /**
     * Format the exception stack trace.
     */
    protected function formatStackTrace(Throwable $exception): array
    {
        $frames = [];

        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'args' => $this->sanitizeArguments($frame['args'] ?? []),
            ];
        }

        return $frames;
    }

    /**
     * Sanitize function arguments for safe transmission.
     */
    protected function sanitizeArguments(array $args): array
    {
        $sanitized = [];

        foreach ($args as $key => $arg) {
            if (is_object($arg)) {
                $sanitized[$key] = get_class($arg);
            } elseif (is_array($arg)) {
                $sanitized[$key] = '[array]';
            } elseif (is_resource($arg)) {
                $sanitized[$key] = '[resource]';
            } elseif (is_string($arg) && strlen($arg) > 200) {
                $sanitized[$key] = substr($arg, 0, 200) . '...';
            } else {
                $sanitized[$key] = $arg;
            }
        }

        return $sanitized;
    }

    /**
     * Get the full context including request context.
     */
    protected function getFullContext(): array
    {
        return array_merge($this->context, [
            'runtime' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
            'os' => [
                'name' => PHP_OS,
                'hostname' => gethostname(),
            ],
        ]);
    }
}
