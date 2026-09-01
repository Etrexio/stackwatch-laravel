<?php

namespace StackWatch\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use StackWatch\Laravel\StackWatch;
use StackWatch\Laravel\StackWatchServiceProvider;
use StackWatch\Laravel\Transport\HttpTransport;

/**
 * Every log / exception occurrence must reach the API exactly once, even
 * when the "stackwatch" log channel is registered next to the listener.
 */
class LogDeduplicationTest extends TestCase
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

        // Real logging pipeline: a "stack" channel with the stackwatch channel
        // auto-registered into it (the setup that used to double every log).
        // No other channel in the stack: Monolog's NullHandler would stop
        // bubbling and the stackwatch handler would never run.
        $app['config']->set('logging.default', 'stack');
        $app['config']->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => [],
            'ignore_exceptions' => false,
        ]);
        $app['config']->set('stackwatch.logging.auto_register', true);

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

    protected function listenerDisabled($app): void
    {
        $app['config']->set('stackwatch.logging.capture_as_events', false);
        $app['config']->set('stackwatch.breadcrumbs.capture_logs', false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_channel_is_registered_in_the_stack(): void
    {
        $this->assertContains('stackwatch', config('logging.channels.stack.channels'));
        $this->assertTrue($this->app->bound(StackWatchServiceProvider::LOG_LISTENER_BINDING));
    }

    public function test_log_is_sent_exactly_once_with_auto_registered_channel(): void
    {
        Log::info('hello once', ['k' => 'v']);

        $this->assertCount(1, $this->sent);
        $this->assertSame('log', $this->sent[0]['type']);
        $this->assertSame('hello once', $this->sent[0]['message']);
        $this->assertSame('v', $this->sent[0]['context']['k']);
    }

    public function test_reported_exception_is_sent_exactly_once(): void
    {
        $exception = new \RuntimeException('boom');

        // Exception handler: SDK captures it, then Laravel logs it with
        // ['exception' => $e] -> that log line must not become a 2nd/3rd event
        $this->app->make(ExceptionHandler::class)->report($exception);

        $this->assertCount(1, $this->sent);
        $this->assertSame('error', $this->sent[0]['type']);
        $this->assertSame('boom', $this->sent[0]['exception']['message']);
        $this->assertTrue($this->app->make(StackWatch::class)->hasCapturedException($exception));
    }

    public function test_manual_error_log_with_exception_becomes_one_exception_event(): void
    {
        Log::error('payment failed', ['exception' => new \RuntimeException('gateway down'), 'order' => 5]);

        $this->assertCount(1, $this->sent);
        $this->assertSame('error', $this->sent[0]['type']);
        $this->assertSame('gateway down', $this->sent[0]['exception']['message']);
        $this->assertSame('payment failed', $this->sent[0]['context']['log_message']);
        $this->assertSame(5, $this->sent[0]['context']['order']);
        $this->assertArrayNotHasKey('exception', $this->sent[0]['context']);
    }

    public function test_ignored_exception_log_falls_back_to_a_log_event(): void
    {
        config(['stackwatch.ignored_exceptions' => [\RuntimeException::class]]);

        Log::error('ignored one', ['exception' => new \RuntimeException('skip me')]);

        $this->assertCount(1, $this->sent);
        $this->assertSame('log', $this->sent[0]['type']);
        $this->assertSame('ignored one', $this->sent[0]['message']);
    }

    public function test_warning_with_exception_context_stays_a_log_event(): void
    {
        Log::warning('soft failure', ['exception' => new \RuntimeException('meh')]);

        $this->assertCount(1, $this->sent);
        $this->assertSame('log', $this->sent[0]['type']);
    }

    #[DefineEnvironment('listenerDisabled')]
    public function test_channel_handler_still_runs_when_log_capture_is_off(): void
    {
        $this->assertFalse($this->app->bound(StackWatchServiceProvider::LOG_LISTENER_BINDING));

        // Listener off + capture_as_events off: the channel handler records
        // the log as a breadcrumb (no event is sent)
        Log::info('breadcrumb only');

        $this->assertCount(0, $this->sent);

        $breadcrumbs = $this->app->make(StackWatch::class)->getBreadcrumbs();
        $this->assertCount(1, $breadcrumbs);
        $this->assertSame('breadcrumb only', $breadcrumbs[0]['message']);
    }
}
