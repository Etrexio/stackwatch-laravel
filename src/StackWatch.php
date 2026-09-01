<?php

namespace StackWatch\Laravel;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use StackWatch\Laravel\Transport\HttpTransport;
use Throwable;

class StackWatch
{
    /**
     * Maximum message length accepted by the StackWatch API (characters).
     * Longer messages are split into several "[part i/n]" events.
     */
    public const DEFAULT_MAX_MESSAGE_LENGTH = 25000;

    /**
     * Maximum stack trace length accepted by the exception endpoint.
     */
    public const MAX_STACK_TRACE_LENGTH = 50000;

    /**
     * Characters reserved for the "[part i/n] " prefix on split messages.
     */
    protected const PART_PREFIX_RESERVE = 20;

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

        return $this->sendChunked($event);
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

        return $this->sendChunked($event);
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

        return $this->sendChunked($event);
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
        // An exception must stay a single event (one stack trace), so the
        // message is truncated instead of split.
        $message = $this->truncate($exception->getMessage(), $this->maxMessageLength());

        return [
            'type' => 'error',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('stackwatch.environment'),
            'release' => config('stackwatch.release'),
            'level' => 'error',
            'message' => $message,
            'exception' => [
                'type' => get_class($exception),
                'message' => $message,
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'stack_trace' => $this->truncate($this->formatStackTrace($exception), self::MAX_STACK_TRACE_LENGTH),
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
    protected function formatStackTrace(Throwable $exception): string
    {
        return $exception->getTraceAsString();
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
     * Maximum message length accepted by the API.
     */
    protected function maxMessageLength(): int
    {
        $max = (int) config('stackwatch.max_message_length', self::DEFAULT_MAX_MESSAGE_LENGTH);

        return max(self::PART_PREFIX_RESERVE + 1, $max);
    }

    /**
     * Split a message into API-sized chunks (multibyte safe).
     * Returns a single-element array when the message already fits.
     */
    protected function splitMessage(string $message): array
    {
        $max = $this->maxMessageLength();

        if (mb_strlen($message) <= $max) {
            return [$message];
        }

        return mb_str_split($message, $max - self::PART_PREFIX_RESERVE);
    }

    /**
     * Send an event, splitting an oversized message into several
     * "[part i/n]" events that share a `message_part.group` id.
     * Short messages are sent unchanged.
     */
    protected function sendChunked(array $event): ?string
    {
        $message = (string) ($event['message'] ?? '');
        $parts = $this->splitMessage($message);

        if (count($parts) <= 1) {
            return $this->transport->send($event);
        }

        $total = count($parts);
        $groupId = (string) Str::uuid();
        $originalLength = mb_strlen($message);
        $result = null;

        foreach ($parts as $index => $chunk) {
            $number = $index + 1;

            $partEvent = $event;
            $partEvent['message'] = "[part {$number}/{$total}] " . $chunk;
            $partEvent['context'] = array_merge($event['context'] ?? [], [
                'message_part' => [
                    'index' => $number,
                    'total' => $total,
                    'group' => $groupId,
                    'original_length' => $originalLength,
                ],
            ]);

            $partResult = $this->transport->send($partEvent);

            // Report the first successfully sent part's id
            $result ??= $partResult;
        }

        return $result;
    }

    /**
     * Truncate a string to a maximum length (multibyte safe), marking the cut.
     */
    protected function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $marker = '… [truncated]';

        return mb_substr($value, 0, max(0, $max - mb_strlen($marker))) . $marker;
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
