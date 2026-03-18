<?php

namespace StackWatch\Laravel;

use Illuminate\Support\Facades\Cache;

class FloodProtection
{
    /**
     * Cache key prefixes.
     */
    private const FINGERPRINT_PREFIX = 'stackwatch:fp:';
    private const CIRCUIT_BREAKER_KEY = 'stackwatch:circuit_breaker';
    private const LOG_COUNT_KEY = 'stackwatch:log_count';
    private const SUPPRESSED_KEY = 'stackwatch:suppressed:';

    /**
     * In-memory cache for performance (avoids repeated cache hits).
     */
    private static array $memoryCache = [];
    private static int $memoryCacheLogCount = 0;
    private static ?float $memoryCacheLastReset = null;

    /**
     * Check if an event should be allowed through flood protection.
     * Returns true if allowed, false if should be dropped.
     */
    public static function shouldAllow(string $message, string $level, array $context = []): bool
    {
        if (!config('stackwatch.flood_protection.enabled', true)) {
            return true;
        }

        // Circuit breaker check first (fastest path to rejection)
        if (self::isCircuitOpen()) {
            return false;
        }

        // Increment global log counter for circuit breaker
        self::incrementLogCount();

        // Check for duplicate message
        return self::checkDuplicate($message, $level, $context);
    }

    /**
     * Generate a fingerprint for a log message.
     */
    public static function generateFingerprint(string $message, string $level, array $context = []): string
    {
        // Normalize message - remove dynamic parts like timestamps, IDs, etc.
        $normalizedMessage = self::normalizeMessage($message);
        
        // Include level in fingerprint
        $data = $level . ':' . $normalizedMessage;
        
        // Optionally include specific context keys that identify the log type
        $contextKeys = ['exception', 'code', 'file', 'line'];
        foreach ($contextKeys as $key) {
            if (isset($context[$key])) {
                $value = is_scalar($context[$key]) ? $context[$key] : json_encode($context[$key]);
                $data .= ':' . $key . '=' . $value;
            }
        }

        return md5($data);
    }

    /**
     * Normalize a message by removing dynamic parts.
     */
    protected static function normalizeMessage(string $message): string
    {
        // Remove UUIDs
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $message);
        
        // Remove numeric IDs (standalone numbers)
        $message = preg_replace('/\b\d{4,}\b/', '{id}', $message);
        
        // Remove timestamps (various formats)
        $message = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', '{timestamp}', $message);
        
        // Remove IP addresses
        $message = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '{ip}', $message);
        
        // Remove email addresses
        $message = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '{email}', $message);

        return $message;
    }

    /**
     * Check if a message is a duplicate and should be rate limited.
     */
    protected static function checkDuplicate(string $message, string $level, array $context): bool
    {
        $fingerprint = self::generateFingerprint($message, $level, $context);
        $window = config('stackwatch.flood_protection.duplicate_window', 60);
        $maxDuplicates = config('stackwatch.flood_protection.max_duplicates', 5);

        $cacheKey = self::FINGERPRINT_PREFIX . $fingerprint;

        // Use memory cache first for performance
        $now = microtime(true);
        if (isset(self::$memoryCache[$fingerprint])) {
            $entry = self::$memoryCache[$fingerprint];
            
            // Check if window has expired
            if ($now - $entry['first_seen'] > $window) {
                // Reset for new window
                self::$memoryCache[$fingerprint] = [
                    'count' => 1,
                    'first_seen' => $now,
                ];
                return true;
            }

            // Increment count
            self::$memoryCache[$fingerprint]['count']++;
            $count = self::$memoryCache[$fingerprint]['count'];

            if ($count > $maxDuplicates) {
                // Track suppressed count for later reporting
                self::trackSuppressed($fingerprint, $message, $level);
                return false;
            }

            return true;
        }

        // First occurrence in memory - also check persistent cache
        $cached = Cache::get($cacheKey);
        
        if ($cached !== null) {
            $count = $cached + 1;
            
            if ($count > $maxDuplicates) {
                self::trackSuppressed($fingerprint, $message, $level);
                Cache::put($cacheKey, $count, $window);
                return false;
            }

            Cache::put($cacheKey, $count, $window);
            self::$memoryCache[$fingerprint] = [
                'count' => $count,
                'first_seen' => $now - ($window / 2), // Approximate
            ];
            return true;
        }

        // First occurrence ever
        Cache::put($cacheKey, 1, $window);
        self::$memoryCache[$fingerprint] = [
            'count' => 1,
            'first_seen' => $now,
        ];

        return true;
    }

    /**
     * Track suppressed messages for later summary reporting.
     */
    protected static function trackSuppressed(string $fingerprint, string $message, string $level): void
    {
        $key = self::SUPPRESSED_KEY . $fingerprint;
        $data = Cache::get($key, [
            'message' => $message,
            'level' => $level,
            'count' => 0,
            'first_suppressed' => now()->toIso8601String(),
        ]);

        $data['count']++;
        $data['last_suppressed'] = now()->toIso8601String();

        Cache::put($key, $data, 300); // Keep for 5 minutes
    }

    /**
     * Get and clear suppressed message summaries.
     */
    public static function getAndClearSuppressed(): array
    {
        $summaries = [];
        
        // Get all suppressed keys from memory cache fingerprints
        foreach (array_keys(self::$memoryCache) as $fingerprint) {
            $key = self::SUPPRESSED_KEY . $fingerprint;
            $data = Cache::get($key);
            
            if ($data && $data['count'] > 0) {
                $summaries[] = $data;
                Cache::forget($key);
            }
        }

        return $summaries;
    }

    /**
     * Increment the global log counter for circuit breaker detection.
     */
    protected static function incrementLogCount(): void
    {
        $now = microtime(true);
        $window = config('stackwatch.flood_protection.circuit_breaker.window', 10);

        // Reset counter if window expired
        if (self::$memoryCacheLastReset === null || ($now - self::$memoryCacheLastReset) > $window) {
            self::$memoryCacheLogCount = 0;
            self::$memoryCacheLastReset = $now;
        }

        self::$memoryCacheLogCount++;

        // Check if we need to trip the circuit breaker
        $threshold = config('stackwatch.flood_protection.circuit_breaker.threshold', 100);
        
        if (self::$memoryCacheLogCount >= $threshold) {
            self::tripCircuitBreaker();
        }
    }

    /**
     * Check if the circuit breaker is open (blocking all logs).
     */
    public static function isCircuitOpen(): bool
    {
        if (!config('stackwatch.flood_protection.circuit_breaker.enabled', true)) {
            return false;
        }

        $state = Cache::get(self::CIRCUIT_BREAKER_KEY);
        
        if ($state === null) {
            return false;
        }

        // Check if cooldown has passed
        $cooldown = config('stackwatch.flood_protection.circuit_breaker.cooldown', 30);
        
        if (time() - $state['tripped_at'] > $cooldown) {
            // Reset circuit breaker
            Cache::forget(self::CIRCUIT_BREAKER_KEY);
            self::$memoryCacheLogCount = 0;
            return false;
        }

        return true;
    }

    /**
     * Trip the circuit breaker.
     */
    protected static function tripCircuitBreaker(): void
    {
        $cooldown = config('stackwatch.flood_protection.circuit_breaker.cooldown', 30);
        
        Cache::put(self::CIRCUIT_BREAKER_KEY, [
            'tripped_at' => time(),
            'log_count' => self::$memoryCacheLogCount,
        ], $cooldown + 10); // Keep slightly longer than cooldown

        // Log this event (but mark as internal to avoid recursion)
        // This single log will get through because we haven't returned yet
    }

    /**
     * Get the current circuit breaker state for debugging.
     */
    public static function getCircuitBreakerState(): array
    {
        $state = Cache::get(self::CIRCUIT_BREAKER_KEY);
        
        if ($state === null) {
            return [
                'status' => 'closed',
                'log_count' => self::$memoryCacheLogCount,
            ];
        }

        $cooldown = config('stackwatch.flood_protection.circuit_breaker.cooldown', 30);
        $elapsed = time() - $state['tripped_at'];

        return [
            'status' => 'open',
            'tripped_at' => $state['tripped_at'],
            'remaining_cooldown' => max(0, $cooldown - $elapsed),
            'log_count_at_trip' => $state['log_count'],
        ];
    }

    /**
     * Manually reset flood protection state (useful for testing).
     */
    public static function reset(): void
    {
        self::$memoryCache = [];
        self::$memoryCacheLogCount = 0;
        self::$memoryCacheLastReset = null;
        Cache::forget(self::CIRCUIT_BREAKER_KEY);
    }

    /**
     * Get statistics about current flood protection state.
     */
    public static function getStats(): array
    {
        return [
            'memory_cache_entries' => count(self::$memoryCache),
            'current_log_count' => self::$memoryCacheLogCount,
            'circuit_breaker' => self::getCircuitBreakerState(),
        ];
    }
}
