<?php

namespace StackWatch\Laravel\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HttpTransport
{
    protected Client $client;
    protected string $endpoint;
    protected string $apiKey;
    protected int $timeout;
    protected int $retryAttempts;
    protected int $retryDelay;
    protected int $rateLimitPerMinute;
    protected bool $bufferOnRateLimit;
    protected static array $eventBuffer = [];
    protected static ?int $rateLimitResetTime = null;

    private const RATE_LIMIT_CACHE_KEY = 'stackwatch:rate_limit';
    private const BUFFER_CACHE_KEY = 'stackwatch:event_buffer';

    public function __construct()
    {
        $this->apiKey = config('stackwatch.api_key');
        $this->endpoint = rtrim(config('stackwatch.endpoint', 'https://api.stackwatch.dev/v1'), '/');
        $this->timeout = config('stackwatch.http.timeout', 5);
        $this->retryAttempts = config('stackwatch.http.retry_attempts', 3);
        $this->retryDelay = config('stackwatch.http.retry_delay', 100);
        $this->rateLimitPerMinute = config('stackwatch.rate_limit.per_minute', 60);
        $this->bufferOnRateLimit = config('stackwatch.rate_limit.buffer_on_limit', true);

        $this->client = new Client([
            'base_uri' => $this->endpoint,
            'timeout' => $this->timeout,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
                'User-Agent' => 'StackWatch-Laravel/1.0',
            ],
        ]);
    }

    /**
     * Send an event to StackWatch with rate limiting support.
     */
    public function send(array $event): ?string
    {
        if (empty($this->apiKey)) {
            return null;
        }

        // Check rate limiting
        if ($this->isRateLimited()) {
            return $this->handleRateLimited($event);
        }

        $queueConnection = config('stackwatch.queue.connection', 'sync');

        if ($queueConnection === 'sync') {
            return $this->sendNow($event);
        }

        return $this->sendAsync($event);
    }

    /**
     * Check if we're currently rate limited.
     */
    protected function isRateLimited(): bool
    {
        $currentCount = Cache::get(self::RATE_LIMIT_CACHE_KEY, 0);
        return $currentCount >= $this->rateLimitPerMinute;
    }

    /**
     * Handle rate-limited event by buffering.
     */
    protected function handleRateLimited(array $event): ?string
    {
        if (!$this->bufferOnRateLimit) {
            Log::debug('StackWatch: Event dropped due to rate limiting');
            return null;
        }

        // Buffer event for later sending
        $buffer = Cache::get(self::BUFFER_CACHE_KEY, []);
        $maxBufferSize = config('stackwatch.rate_limit.max_buffer_size', 1000);
        
        if (count($buffer) < $maxBufferSize) {
            $buffer[] = $event;
            Cache::put(self::BUFFER_CACHE_KEY, $buffer, now()->addHours(1));
            
            // Schedule buffer flush
            $this->scheduleBufferFlush();
            
            return 'buffered';
        }

        Log::warning('StackWatch: Event buffer full, event dropped');
        return null;
    }

    /**
     * Schedule a job to flush the buffer when rate limit resets.
     */
    protected function scheduleBufferFlush(): void
    {
        $flushJobKey = 'stackwatch:buffer_flush_scheduled';
        
        if (!Cache::has($flushJobKey)) {
            Cache::put($flushJobKey, true, 60);
            
            // Dispatch delayed job to flush buffer
            dispatch(new \StackWatch\Laravel\Jobs\FlushEventBufferJob())
                ->delay(now()->addMinute())
                ->onQueue(config('stackwatch.queue.queue_name', 'stackwatch'));
        }
    }

    /**
     * Increment the rate limit counter.
     */
    protected function incrementRateLimit(): void
    {
        $key = self::RATE_LIMIT_CACHE_KEY;
        
        if (Cache::has($key)) {
            Cache::increment($key);
        } else {
            Cache::put($key, 1, 60); // Reset every minute
        }
    }

    /**
     * Send event synchronously.
     */
    public function sendNow(array $event): ?string
    {
        $attempt = 0;

        while ($attempt < $this->retryAttempts) {
            try {
                // Determine the correct endpoint based on event type
                $endpoint = $this->getEndpointForEvent($event);
                
                $response = $this->client->post($endpoint, [
                    RequestOptions::JSON => $event,
                ]);

                // Increment rate limit counter on success
                $this->incrementRateLimit();

                // Check for rate limit headers from server
                $this->handleRateLimitHeaders($response);

                $body = json_decode($response->getBody()->getContents(), true);

                return $body['event_id'] ?? null;
            } catch (GuzzleException $e) {
                // Check if this is a rate limit response (429)
                if ($e->getCode() === 429) {
                    return $this->handleRateLimited($event);
                }

                $attempt++;

                if ($attempt >= $this->retryAttempts) {
                    Log::warning('StackWatch: Failed to send event after ' . $this->retryAttempts . ' attempts', [
                        'error' => $e->getMessage(),
                    ]);

                    // Buffer failed events if configured
                    if ($this->bufferOnRateLimit) {
                        return $this->handleRateLimited($event);
                    }

                    return null;
                }

                usleep($this->retryDelay * 1000);
            }
        }

        return null;
    }

    /**
     * Get the appropriate API endpoint for the event type.
     */
    protected function getEndpointForEvent(array $event): string
    {
        $type = $event['type'] ?? 'event';

        return match ($type) {
            'exception', 'error' => '/events/exception',
            'performance' => '/events/performance',
            default => '/events',
        };
    }

    /**
     * Handle rate limit headers from server response.
     */
    protected function handleRateLimitHeaders($response): void
    {
        $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
        $resetTime = $response->getHeaderLine('X-RateLimit-Reset');

        if ($remaining !== '' && (int) $remaining <= 0) {
            // We've hit the server rate limit
            if ($resetTime !== '') {
                self::$rateLimitResetTime = (int) $resetTime;
            }
        }
    }

    /**
     * Send batch of events (for flushing buffer).
     */
    public function sendBatch(array $events): array
    {
        if (empty($events)) {
            return [];
        }

        $results = [];
        $chunks = array_chunk($events, 100); // API accepts max 100 per batch

        foreach ($chunks as $chunk) {
            try {
                $response = $this->client->post('/events/batch', [
                    RequestOptions::JSON => ['events' => $chunk],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                $results = array_merge($results, $body['event_ids'] ?? []);
                
                // Increment rate limit for batch
                $this->incrementRateLimit();
            } catch (GuzzleException $e) {
                Log::warning('StackWatch: Failed to send batch', ['error' => $e->getMessage()]);
                
                // Re-buffer failed events
                foreach ($chunk as $event) {
                    $this->handleRateLimited($event);
                }
            }
        }

        return $results;
    }

    /**
     * Flush the event buffer.
     */
    public function flushBuffer(): array
    {
        $buffer = Cache::get(self::BUFFER_CACHE_KEY, []);
        
        if (empty($buffer)) {
            return [];
        }

        // Clear the buffer first
        Cache::forget(self::BUFFER_CACHE_KEY);
        Cache::forget('stackwatch:buffer_flush_scheduled');

        // Send buffered events
        return $this->sendBatch($buffer);
    }

    /**
     * Get buffer size.
     */
    public function getBufferSize(): int
    {
        return count(Cache::get(self::BUFFER_CACHE_KEY, []));
    }

    /**
     * Queue event for async sending.
     */
    protected function sendAsync(array $event): ?string
    {
        $queueName = config('stackwatch.queue.queue_name', 'stackwatch');
        $connection = config('stackwatch.queue.connection', 'redis');

        dispatch(new \StackWatch\Laravel\Jobs\SendEventJob($event))
            ->onConnection($connection)
            ->onQueue($queueName);

        return 'queued';
    }

    /**
     * Check connectivity to StackWatch API.
     */
    public function ping(): bool
    {
        try {
            $response = $this->client->get('/health');

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }
}
