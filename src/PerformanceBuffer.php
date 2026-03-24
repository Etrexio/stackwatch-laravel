<?php

namespace StackWatch\Laravel;

use Illuminate\Support\Facades\Log;

/**
 * File-based performance buffer that persists across requests.
 * Uses direct file storage instead of Laravel cache to ensure reliability.
 */
class PerformanceBuffer
{
    private const BUFFER_FILE = 'stackwatch_perf_buffer.json';
    private const META_FILE = 'stackwatch_perf_meta.json';

    private static ?string $storagePath = null;

    /**
     * Get the storage path for buffer files.
     */
    protected static function getStoragePath(): string
    {
        if (self::$storagePath === null) {
            self::$storagePath = storage_path('stackwatch');
            
            if (!is_dir(self::$storagePath)) {
                mkdir(self::$storagePath, 0755, true);
            }
        }

        return self::$storagePath;
    }

    /**
     * Get full path for a buffer file.
     */
    protected static function getFilePath(string $filename): string
    {
        return self::getStoragePath() . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Acquire a file lock for atomic operations.
     */
    protected static function withLock(string $file, callable $callback): mixed
    {
        $lockFile = $file . '.lock';
        $handle = fopen($lockFile, 'c+');
        
        if ($handle === false) {
            Log::warning('StackWatch: Could not open lock file', ['stackwatch_internal' => true]);
            return $callback();
        }

        $locked = flock($handle, LOCK_EX);
        
        try {
            return $callback();
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    /**
     * Read data from a JSON file.
     */
    protected static function readFile(string $filename): array
    {
        $path = self::getFilePath($filename);
        
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        
        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }

    /**
     * Write data to a JSON file.
     */
    protected static function writeFile(string $filename, array $data): bool
    {
        $path = self::getFilePath($filename);
        $content = json_encode($data, JSON_PRETTY_PRINT);
        
        return file_put_contents($path, $content, LOCK_EX) !== false;
    }

    /**
     * Get the current buffer data.
     */
    public static function getBuffer(): array
    {
        return self::withLock(self::getFilePath(self::BUFFER_FILE), function () {
            return self::readFile(self::BUFFER_FILE);
        });
    }

    /**
     * Add performance data to the buffer.
     */
    public static function add(array $perfData): void
    {
        self::withLock(self::getFilePath(self::BUFFER_FILE), function () use ($perfData) {
            $buffer = self::readFile(self::BUFFER_FILE);
            $transactionName = $perfData['name'];

            if (!isset($buffer[$transactionName])) {
                $buffer[$transactionName] = [
                    'count' => 0,
                    'total_duration' => 0,
                    'min_duration' => $perfData['duration_ms'],
                    'max_duration' => $perfData['duration_ms'],
                    'total_memory' => 0,
                    'error_count' => 0,
                    'status_codes' => [],
                    'route' => $perfData['route'] ?? null,
                    'first_seen' => time(),
                ];
            }

            $buffer[$transactionName]['count']++;
            $buffer[$transactionName]['total_duration'] += $perfData['duration_ms'];
            $buffer[$transactionName]['min_duration'] = min(
                $buffer[$transactionName]['min_duration'], 
                $perfData['duration_ms']
            );
            $buffer[$transactionName]['max_duration'] = max(
                $buffer[$transactionName]['max_duration'], 
                $perfData['duration_ms']
            );
            $buffer[$transactionName]['total_memory'] += $perfData['memory_peak_mb'];
            
            if ($perfData['is_error']) {
                $buffer[$transactionName]['error_count']++;
            }

            $statusCode = (string) $perfData['status_code'];
            $buffer[$transactionName]['status_codes'][$statusCode] = 
                ($buffer[$transactionName]['status_codes'][$statusCode] ?? 0) + 1;

            self::writeFile(self::BUFFER_FILE, $buffer);
        });
    }

    /**
     * Get the last flush timestamp.
     */
    public static function getLastFlushTime(): ?int
    {
        $meta = self::readFile(self::META_FILE);
        return $meta['last_flush'] ?? null;
    }

    /**
     * Update the last flush timestamp.
     */
    public static function setLastFlushTime(int $timestamp): void
    {
        self::writeFile(self::META_FILE, ['last_flush' => $timestamp]);
    }

    /**
     * Get total count of buffered requests.
     */
    public static function getTotalCount(): int
    {
        $buffer = self::getBuffer();
        return array_sum(array_column($buffer, 'count'));
    }

    /**
     * Clear the buffer and return its contents.
     */
    public static function flush(): array
    {
        return self::withLock(self::getFilePath(self::BUFFER_FILE), function () {
            $buffer = self::readFile(self::BUFFER_FILE);
            self::writeFile(self::BUFFER_FILE, []);
            self::setLastFlushTime(time());
            return $buffer;
        });
    }

    /**
     * Check if flush conditions are met.
     */
    public static function shouldFlush(): bool
    {
        $totalCount = self::getTotalCount();
        
        if ($totalCount === 0) {
            return false;
        }

        $batchSize = config('stackwatch.performance.aggregate.batch_size', 50);
        $flushInterval = config('stackwatch.performance.aggregate.flush_interval', 60);
        $minFlushCount = config('stackwatch.performance.aggregate.min_flush_count', 5);
        
        // Initialize last flush time if not set
        $lastFlush = self::getLastFlushTime();
        if ($lastFlush === null) {
            self::setLastFlushTime(time());
            return false; // Don't flush on first request, start accumulating
        }
        
        $timeSinceLastFlush = time() - $lastFlush;

        // Flush if:
        // 1. Batch size reached (always flush)
        // 2. OR interval passed AND minimum count reached
        return $totalCount >= $batchSize || 
               ($totalCount >= $minFlushCount && $timeSinceLastFlush >= $flushInterval);
    }

    /**
     * Get buffer statistics for debugging.
     */
    public static function getStats(): array
    {
        $buffer = self::getBuffer();
        $lastFlush = self::getLastFlushTime();
        
        return [
            'total_count' => array_sum(array_column($buffer, 'count')),
            'unique_transactions' => count($buffer),
            'last_flush' => $lastFlush,
            'seconds_since_flush' => $lastFlush ? time() - $lastFlush : null,
            'storage_path' => self::getStoragePath(),
        ];
    }
}
