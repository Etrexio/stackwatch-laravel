<?php

namespace StackWatch\Laravel\Tests;

use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use StackWatch\Laravel\Logging\StackWatchLogChannel;
use StackWatch\Laravel\StackWatchServiceProvider;
use StackWatch\Laravel\Transport\HttpTransport;

/**
 * End-to-end: Laravel Log facade -> SDK listener/handler -> transport.
 */
class LogCaptureTest extends TestCase
{
    protected $transport;
    protected array $sent = [];

    protected function getPackageProviders($app): array
    {
        return [StackWatchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('stackwatch.api_key', 'test-api-key');
        $app['config']->set('stackwatch.enabled', true);
        $app['config']->set('stackwatch.flood_protection.enabled', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');

        $this->sent = [];
        $this->transport = Mockery::mock(HttpTransport::class);
        $this->transport->shouldReceive('send')
            ->andReturnUsing(function (array $event) {
                $this->sent[] = $event;

                return 'evt-' . count($this->sent);
            })
            ->byDefault();

        $app->instance(HttpTransport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_oversized_laravel_log_is_delivered_in_parts(): void
    {
        Log::info(str_repeat('L', 30000), ['source' => 'test']);

        $this->assertCount(2, $this->sent);
        $this->assertStringStartsWith('[part 1/2] ', $this->sent[0]['message']);
        $this->assertStringStartsWith('[part 2/2] ', $this->sent[1]['message']);
        $this->assertSame('test', $this->sent[0]['context']['source']);
        $this->assertSame($this->sent[0]['context']['message_part']['group'], $this->sent[1]['context']['message_part']['group']);
    }

    public function test_internal_sdk_logs_are_not_captured_by_listener(): void
    {
        Log::warning('StackWatch: Event rejected by API, dropping event', ['stackwatch_internal' => true]);

        $this->assertCount(0, $this->sent);
    }

    public function test_log_channel_handler_skips_internal_sdk_logs(): void
    {
        // The custom "stackwatch" log channel (Monolog logger + handler)
        $logger = (new StackWatchLogChannel())(['level' => 'debug']);

        $logger->debug('StackWatch: Retry attempt 1 after error: ...', ['stackwatch_internal' => true]);

        $this->assertCount(0, $this->sent, 'internal diagnostics must never be shipped to the API');

        $logger->info('regular application log');

        $this->assertCount(1, $this->sent);
        $this->assertSame('regular application log', $this->sent[0]['message']);
    }
}
