<?php

namespace StackWatch\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $startMemory = memory_get_usage();

        // Process request
        $response = $next($request);

        // Capture performance data
        if (config('stackwatch.performance.enabled', true)) {
            $this->capturePerformance($request, $response, $startTime, $startMemory);
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

    protected function capturePerformance(Request $request, Response $response, float $startTime, int $startMemory): void
    {
        $sampleRate = config('stackwatch.performance.sample_rate', 1.0);

        // Apply sample rate
        if ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
            return;
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $memoryUsed = memory_get_usage() - $startMemory;

        $this->stackWatch->capturePerformance([
            'request' => [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
            ],
            'response' => [
                'status_code' => $response->getStatusCode(),
            ],
            'metrics' => [
                'duration_ms' => round($duration, 2),
                'memory_bytes' => $memoryUsed,
                'memory_peak_bytes' => memory_get_peak_usage(),
            ],
            'breadcrumbs' => $this->stackWatch->getBreadcrumbs(),
        ]);
    }
}
