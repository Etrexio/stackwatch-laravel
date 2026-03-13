<?php

namespace StackWatch\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use StackWatch\Laravel\Transport\HttpTransport;

class SendEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        protected array $event
    ) {}

    public function handle(HttpTransport $transport): void
    {
        $transport->sendNow($this->event);
    }

    public function failed(\Throwable $exception): void
    {
        // Log failure but don't report to StackWatch to avoid infinite loop
        logger()->warning('StackWatch: Failed to send event', [
            'error' => $exception->getMessage(),
            'event_type' => $this->event['type'] ?? 'unknown',
        ]);
    }
}
