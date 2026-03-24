<?php

namespace StackWatch\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use StackWatch\Laravel\PerformanceBuffer;
use StackWatch\Laravel\StackWatch;
use Symfony\Component\HttpFoundation\Response;

class StackWatchMiddleware
{
    protected StackWatch $stackWatch;

    public function __construct(StackWatch $stackWatch)
    {
        $this->stackWatch = $stackWatch;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Set user context
        $this->stackWatch->setUserFromAuth();

        // Add request breadcrumb
        $this->stackWatch->addBreadcrumb(
            'request',
            $request->method() . ' ' . $request->path(),
            $this->getRequestContext($request)
        );

        // Capture start time for performance monitoring
        $startTime = microtime(true);

        // Process request
        $response = $next($request);

        // Capture performance data
        if (config('stackwatch.performance.enabled', true)) {
            $this->capturePerformance($request, $response, $startTime);
        }

        return $response;
    }

    protected function getRequestContext(Request $request): array
    {
        $context = [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        if (config('stackwatch.request_context.capture_headers', true)) {
            $headers = $request->headers->all();
            $blacklist = config('stackwatch.request_context.headers_blacklist', []);

            foreach ($blacklist as $key) {
                unset($headers[strtolower($key)]);
            }

            $context['headers'] = $headers;
        }

        if (config('stackwatch.request_context.capture_body', false)) {
            $body = $request->all();
            $blacklist = config('stackwatch.request_context.body_blacklist', []);

            foreach ($blacklist as $key) {
                unset($body[$key]);
            }

            $context['body'] = $body;
        }

        return $context;
    }

    protected function capturePerformance(Request $request, Response $response, float $startTime): void
    {
        $duration = (microtime(true) - $startTime) * 1000;
        $slowThreshold = config('stackwatch.performance.slow_request_threshold', 3000);
        $isSlowRequest = $duration >= $slowThreshold;

        // Build transaction name based on config
        $groupBy = config('stackwatch.performance.group_by', 'path');
        $routeName = $request->route()?->getName();
        
        if ($groupBy === 'route' && $routeName) {
            // Group by route name: "GET cases.show"
            $transactionName = $request->method() . ' ' . $routeName;
        } else {
            // Group by path: "GET /cases/my-case-slug"
            $transactionName = $request->method() . ' /' . ltrim($request->path(), '/');
        }

        $perfData = [
            'name' => $transactionName,
            'duration_ms' => round($duration, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'status_code' => $response->getStatusCode(),
            'is_error' => $response->getStatusCode() >= 400,
            'route' => $routeName,
        ];

        // Slow requests are always sent immediately
        if ($isSlowRequest) {
            $this->sendPerformanceEvent($perfData, $request, true);
            return;
        }

        // Check if aggregation is enabled
        $aggregateEnabled = config('stackwatch.performance.aggregate.enabled', true);
        
        if (!$aggregateEnabled) {
            // No aggregation - apply sampling and send
            $sampleRate = config('stackwatch.performance.sample_rate', 0.1);
            if (mt_rand() / mt_getrandmax() <= $sampleRate) {
                $this->sendPerformanceEvent($perfData, $request, false);
            }
            return;
        }

        // Aggregation enabled - buffer the request using file-based storage
        PerformanceBuffer::add($perfData);
        
        // Check if any transactions are ready to flush
        $readyTransactions = PerformanceBuffer::getReadyTransactions();
        if (!empty($readyTransactions)) {
            $this->flushReadyTransactions($readyTransactions);
        }
    }

    /**
     * Send a single performance event.
     */
    protected function sendPerformanceEvent(array $perfData, Request $request, bool $isSlow): void
    {
        $message = $perfData['name'] . ' - ' . $perfData['duration_ms'] . 'ms';
        if ($isSlow) {
            $message .= ' (slow)';
        }

        $this->stackWatch->capturePerformance([
            'name' => $perfData['name'],
            'message' => $message,
            'duration_ms' => $perfData['duration_ms'],
            'operation' => 'http',
            'status' => $perfData['is_error'] ? 'error' : 'ok',
            'memory_peak_mb' => $perfData['memory_peak_mb'],
            'context' => [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'status_code' => $perfData['status_code'],
                'slow_request' => $isSlow,
            ],
            'tags' => [
                'http.method' => $request->method(),
                'http.status_code' => (string) $perfData['status_code'],
                'slow' => $isSlow ? 'true' : 'false',
            ],
        ]);
    }

    /**
     * Flush only the transactions that are ready (reached batch_size or time+min_count).
     * Other transactions stay in the buffer until they're ready.
     */
    protected function flushReadyTransactions(array $transactionNames): void
    {
        $buffer = PerformanceBuffer::flushTransactions($transactionNames);
        
        if (empty($buffer)) {
            return;
        }

        $this->sendAggregatedMetrics($buffer);
    }

    /**
     * Flush the entire performance buffer and send aggregated metrics.
     * Used by artisan command for manual flush.
     */
    protected function flushPerformanceBuffer(): void
    {
        $buffer = PerformanceBuffer::flush();
        
        if (empty($buffer)) {
            return;
        }

        $this->sendAggregatedMetrics($buffer);
    }

    /**
     * Send aggregated metrics for the given buffer data.
     */
    protected function sendAggregatedMetrics(array $buffer): void
    {
        // Send aggregated metrics for each transaction
        foreach ($buffer as $transactionName => $data) {
            if ($data['count'] === 0) {
                continue;
            }

            $avgDuration = $data['total_duration'] / $data['count'];
            $avgMemory = $data['total_memory'] / $data['count'];
            $errorRate = ($data['error_count'] / $data['count']) * 100;

            $message = $transactionName . ' - avg ' . round($avgDuration, 2) . 'ms (' . $data['count'] . ' requests)';
            if ($data['error_count'] > 0) {
                $message .= ' [' . $data['error_count'] . ' errors]';
            }

            $this->stackWatch->capturePerformance([
                'name' => $transactionName,
                'message' => $message,
                'duration_ms' => round($avgDuration, 2),
                'operation' => 'http.aggregated',
                'status' => $data['error_count'] > 0 ? 'degraded' : 'ok',
                'memory_peak_mb' => round($avgMemory, 2),
                'context' => [
                    'aggregated' => true,
                    'route' => $data['route'] ?? null,
                    'request_count' => $data['count'],
                    'min_duration_ms' => round($data['min_duration'], 2),
                    'max_duration_ms' => round($data['max_duration'], 2),
                    'avg_duration_ms' => round($avgDuration, 2),
                    'error_count' => $data['error_count'],
                    'error_rate_percent' => round($errorRate, 2),
                    'status_codes' => $data['status_codes'],
                ],
                'tags' => [
                    'aggregated' => 'true',
                    'request_count' => (string) $data['count'],
                ],
            ]);
        }
    }
}
