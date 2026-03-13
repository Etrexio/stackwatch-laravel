<?php

namespace StackWatch\Laravel\Listeners;

use StackWatch\Laravel\StackWatch;

/**
 * Listener for Spatie Laravel Health events.
 *
 * @see https://spatie.be/docs/laravel-health
 */
class SpatieHealthListener
{
    protected StackWatch $stackWatch;

    public function __construct(StackWatch $stackWatch)
    {
        $this->stackWatch = $stackWatch;
    }

    /**
     * Handle health check completed event.
     */
    public function handleCheckEnded($event): void
    {
        $check = $event->check;
        $result = $event->result;

        $status = $result->status->value ?? 'unknown';
        $level = match ($status) {
            'ok' => 'info',
            'warning' => 'warning',
            'failed', 'crashed' => 'error',
            default => 'info',
        };

        // Only send events for non-OK statuses to reduce noise
        if ($status !== 'ok') {
            $this->stackWatch->captureEvent('health', $level, "Health check: {$check->getName()}", [
                'check_name' => $check->getName(),
                'check_label' => $check->getLabel(),
                'status' => $status,
                'message' => $result->notificationMessage ?? null,
                'short_summary' => $result->shortSummary ?? null,
                'meta' => $result->meta ?? [],
            ]);
        }

        // Always add as breadcrumb for context
        $this->stackWatch->addBreadcrumb('health', "Health: {$check->getName()}", [
            'status' => $status,
            'summary' => $result->shortSummary ?? null,
        ], $level);
    }

    /**
     * Handle all checks completed event.
     */
    public function handleAllChecksEnded($event): void
    {
        $results = $event->checkResults ?? [];
        
        $summary = [
            'total' => count($results),
            'ok' => 0,
            'warning' => 0,
            'failed' => 0,
            'crashed' => 0,
        ];

        $failedChecks = [];

        foreach ($results as $result) {
            $status = $result->status->value ?? 'unknown';
            
            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            if (in_array($status, ['failed', 'crashed', 'warning'])) {
                $failedChecks[] = [
                    'name' => $result->check->getName(),
                    'status' => $status,
                    'message' => $result->notificationMessage ?? null,
                ];
            }
        }

        // Only send event if there are any issues
        if ($summary['failed'] > 0 || $summary['crashed'] > 0 || $summary['warning'] > 0) {
            $level = ($summary['failed'] > 0 || $summary['crashed'] > 0) ? 'error' : 'warning';
            
            $this->stackWatch->captureEvent('health', $level, 'Health check summary', [
                'summary' => $summary,
                'failed_checks' => $failedChecks,
                'status' => $level === 'error' ? 'unhealthy' : 'degraded',
            ]);
        }
    }

    /**
     * Subscribe to Spatie Health events.
     *
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            'Spatie\Health\Events\CheckEndedEvent' => 'handleCheckEnded',
            'Spatie\Health\Events\AllChecksEndedEvent' => 'handleAllChecksEnded',
        ];
    }
}
