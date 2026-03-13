<?php

namespace StackWatch\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use StackWatch\Laravel\Transport\HttpTransport;

class FlushEventBufferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Execute the job.
     */
    public function handle(HttpTransport $transport): void
    {
        $bufferSize = $transport->getBufferSize();

        if ($bufferSize === 0) {
            return;
        }

        Log::debug("StackWatch: Flushing {$bufferSize} buffered events", ['stackwatch_internal' => true]);

        $results = $transport->flushBuffer();

        Log::debug('StackWatch: Flushed ' . count($results) . ' events successfully', ['stackwatch_internal' => true]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('StackWatch: Failed to flush event buffer', [
            'stackwatch_internal' => true,
            'error' => $exception->getMessage(),
        ]);
    }
}
