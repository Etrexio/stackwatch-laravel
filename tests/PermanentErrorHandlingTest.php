<?php

namespace StackWatch\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use StackWatch\Laravel\StackWatchServiceProvider;
use StackWatch\Laravel\Transport\HttpTransport;

class PermanentErrorHandlingTest extends TestCase
{
    /** @var array<int, array{request: Request, response: mixed}> */
    protected array $history = [];

    protected function getPackageProviders($app): array
    {
        return [StackWatchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('stackwatch.api_key', 'test-api-key');
        $app['config']->set('stackwatch.http.retry_attempts', 3);
        $app['config']->set('stackwatch.http.retry_delay', 0);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = [];
        Cache::flush();
    }

    protected function transportWithResponses(array $responses): HttpTransport
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.stackwatch.dev/v1/']);

        $transport = new HttpTransport();

        $property = new \ReflectionProperty(HttpTransport::class, 'client');
        $property->setAccessible(true);
        $property->setValue($transport, $client);

        return $transport;
    }

    protected function validationFailed(): Response
    {
        return new Response(422, [], json_encode([
            'error' => 'Validation failed',
            'messages' => ['message' => ['The message field must not be greater than 25000 characters.']],
        ]));
    }

    public function test_422_is_dropped_without_retry_or_buffering(): void
    {
        $transport = $this->transportWithResponses([$this->validationFailed()]);

        $result = $transport->send(['type' => 'log', 'message' => 'too long']);

        $this->assertNull($result);
        $this->assertCount(1, $this->history, 'a permanent 4xx must not be retried');
        $this->assertSame(0, $transport->getBufferSize(), 'a rejected event must not be buffered');
    }

    public function test_401_is_dropped_as_well(): void
    {
        $transport = $this->transportWithResponses([new Response(401, [], '{"error":"Unauthorized"}')]);

        $this->assertNull($transport->send(['type' => 'log', 'message' => 'x']));
        $this->assertCount(1, $this->history);
        $this->assertSame(0, $transport->getBufferSize());
    }

    public function test_429_is_still_buffered(): void
    {
        $transport = $this->transportWithResponses([new Response(429, [], '{"error":"Too Many Requests"}')]);

        $result = $transport->send(['type' => 'log', 'message' => 'x']);

        $this->assertSame('buffered', $result);
        $this->assertCount(1, $this->history);
        $this->assertSame(1, $transport->getBufferSize());
    }

    public function test_server_errors_are_still_retried_and_buffered(): void
    {
        $transport = $this->transportWithResponses([
            new Response(503, [], 'Service unavailable'),
            new Response(503, [], 'Service unavailable'),
            new Response(503, [], 'Service unavailable'),
        ]);

        $result = $transport->send(['type' => 'log', 'message' => 'x']);

        $this->assertSame('buffered', $result);
        $this->assertCount(3, $this->history);
        $this->assertSame(1, $transport->getBufferSize());
    }

    public function test_transient_error_then_success(): void
    {
        $transport = $this->transportWithResponses([
            new ConnectException('Connection refused', new Request('POST', 'events')),
            new Response(202, [], json_encode(['event_id' => 'evt-1'])),
        ]);

        $this->assertSame('evt-1', $transport->send(['type' => 'log', 'message' => 'x']));
        $this->assertCount(2, $this->history);
        $this->assertSame(0, $transport->getBufferSize());
    }

    public function test_rejected_event_does_not_poison_the_buffer_flush(): void
    {
        // Buffer one good and one bad event, then flush via batch endpoint:
        // batch is rejected -> events are sent individually -> only the bad
        // one is dropped, nothing is re-buffered.
        $transport = $this->transportWithResponses([
            $this->validationFailed(),                                          // events/batch
            new Response(202, [], json_encode(['event_id' => 'evt-good'])),     // good event
            $this->validationFailed(),                                          // bad event
        ]);

        Cache::put('stackwatch:event_buffer', [
            ['type' => 'log', 'message' => 'good'],
            ['type' => 'log', 'message' => 'bad'],
        ], now()->addHour());

        $results = $transport->flushBuffer();

        $this->assertSame(['evt-good'], $results);
        $this->assertCount(3, $this->history);
        $this->assertSame('/v1/events/batch', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('/v1/events', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame(0, $transport->getBufferSize());
    }

    public function test_rejected_buffered_event_does_not_block_the_rest_on_incremental_flush(): void
    {
        // A successful send() triggers tryFlushBuffer(): the poisoned event
        // is dropped and the following buffered event is still delivered.
        $transport = $this->transportWithResponses([
            new Response(202, [], json_encode(['event_id' => 'evt-new'])),   // the new event
            $this->validationFailed(),                                        // buffered: bad
            new Response(202, [], json_encode(['event_id' => 'evt-good'])),  // buffered: good
        ]);

        Cache::put('stackwatch:event_buffer', [
            ['type' => 'log', 'message' => 'bad'],
            ['type' => 'log', 'message' => 'good'],
        ], now()->addHour());

        $this->assertSame('evt-new', $transport->send(['type' => 'log', 'message' => 'new']));
        $this->assertCount(3, $this->history);
        $this->assertSame(0, $transport->getBufferSize());
    }

    public function test_rate_limit_during_incremental_flush_keeps_remaining_events(): void
    {
        $transport = $this->transportWithResponses([
            new Response(202, [], json_encode(['event_id' => 'evt-new'])),   // the new event
            new Response(429, [], '{"error":"Too Many Requests"}'),          // buffered: first
        ]);

        Cache::put('stackwatch:event_buffer', [
            ['type' => 'log', 'message' => 'first'],
            ['type' => 'log', 'message' => 'second'],
        ], now()->addHour());

        $this->assertSame('evt-new', $transport->send(['type' => 'log', 'message' => 'new']));
        $this->assertCount(2, $this->history);
        $this->assertSame(2, $transport->getBufferSize(), 'both buffered events must survive');
    }

    public function test_batch_server_error_still_rebuffers_everything(): void
    {
        $transport = $this->transportWithResponses([new Response(503, [], 'down')]);

        Cache::put('stackwatch:event_buffer', [
            ['type' => 'log', 'message' => 'a'],
            ['type' => 'log', 'message' => 'b'],
        ], now()->addHour());

        $this->assertSame([], $transport->flushBuffer());
        $this->assertCount(1, $this->history);
        $this->assertSame(2, $transport->getBufferSize());
    }
}
