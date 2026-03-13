<?php

namespace StackWatch\Laravel\Console\Commands;

use Illuminate\Console\Command;
use StackWatch\Laravel\StackWatch;
use StackWatch\Laravel\Transport\HttpTransport;

class TestCommand extends Command
{
    protected $signature = 'stackwatch:test';
    protected $description = 'Test connection to StackWatch and send a test event';

    public function handle(StackWatch $stackWatch, HttpTransport $transport): int
    {
        $this->info('Testing StackWatch connection...');
        $this->newLine();

        // Check configuration
        $apiKey = config('stackwatch.api_key');
        if (empty($apiKey)) {
            $this->error('❌ STACKWATCH_API_KEY is not set');
            $this->info('Set your API key in .env: STACKWATCH_API_KEY=your-key-here');

            return Command::FAILURE;
        }

        $this->info('✓ API Key configured');
        $this->info('  Endpoint: ' . config('stackwatch.endpoint'));
        $this->info('  Environment: ' . config('stackwatch.environment'));
        $this->newLine();

        // Test connectivity
        $this->info('Testing API connectivity...');

        if ($transport->ping()) {
            $this->info('✓ Successfully connected to StackWatch API');
        } else {
            $this->warn('⚠ Could not connect to StackWatch API (this might be expected if ping endpoint is not available)');
        }

        $this->newLine();

        // Send test event
        $this->info('Sending test event...');

        $eventId = $stackWatch->captureMessage(
            'StackWatch test event from Laravel',
            'info',
            [
                'test' => true,
                'timestamp' => now()->toIso8601String(),
                'command' => 'stackwatch:test',
            ]
        );

        if ($eventId === 'buffered') {
            $this->error('❌ Event was buffered - API request failed');
            $this->info('  Check storage/logs/laravel.log for details');
            $this->info('  Buffered events: ' . $transport->getBufferSize());
            return Command::FAILURE;
        } elseif ($eventId) {
            $this->info('✓ Test event sent successfully');
            $this->info('  Event ID: ' . $eventId);
        } else {
            $this->error('❌ Test event failed to send');
            $this->info('  Check storage/logs/laravel.log for details');
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('StackWatch is configured and ready!');

        return Command::SUCCESS;
    }
}
