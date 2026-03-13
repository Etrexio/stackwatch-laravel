<?php

namespace StackWatch\Laravel\Tests;

use StackWatch\Laravel\Transport\HttpTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

class HttpTransportTest extends TestCase
{
    protected function createTransportWithMockResponses(array $responses): HttpTransport
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $config = [
            'api_key' => 'test-api-key',
            'endpoint' => 'https://api.stackwatch.dev/v1',
            'http' => [
                'timeout' => 5,
                'connect_timeout' => 2,
            ],
            'queue' => [
                'enabled' => false,
            ],
        ];

        return new HttpTransport($config, $client);
    }

    public function test_sends_event_successfully(): void
    {
        $transport = $this->createTransportWithMockResponses([
            new Response(200, [], json_encode(['id' => 'event-123'])),
        ]);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
        ];

        $result = $transport->send($event);

        $this->assertTrue($result);
    }

    public function test_returns_false_on_client_error(): void
    {
        $transport = $this->createTransportWithMockResponses([
            new Response(400, [], json_encode(['error' => 'Bad request'])),
        ]);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
        ];

        $result = $transport->send($event);

        $this->assertFalse($result);
    }

    public function test_returns_false_on_server_error(): void
    {
        $transport = $this->createTransportWithMockResponses([
            new Response(500, [], json_encode(['error' => 'Internal server error'])),
        ]);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
        ];

        $result = $transport->send($event);

        $this->assertFalse($result);
    }

    public function test_returns_false_on_network_error(): void
    {
        $transport = $this->createTransportWithMockResponses([
            new RequestException(
                'Network error',
                new Request('POST', 'https://api.stackwatch.dev/v1/events')
            ),
        ]);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
        ];

        $result = $transport->send($event);

        $this->assertFalse($result);
    }

    public function test_retries_on_failure(): void
    {
        // First request fails, second succeeds
        $transport = $this->createTransportWithMockResponses([
            new Response(503, [], 'Service unavailable'),
            new Response(200, [], json_encode(['id' => 'event-123'])),
        ]);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
        ];

        $result = $transport->send($event);

        $this->assertTrue($result);
    }

    public function test_accepts_201_response(): void
    {
        $transport = $this->createTransportWithMockResponses([
            new Response(201, [], json_encode(['id' => 'event-123'])),
        ]);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
        ];

        $result = $transport->send($event);

        $this->assertTrue($result);
    }

    public function test_accepts_202_response(): void
    {
        $transport = $this->createTransportWithMockResponses([
            new Response(202, [], json_encode(['id' => 'event-123'])),
        ]);

        $event = [
            'type' => 'error',
            'message' => 'Test error',
        ];

        $result = $transport->send($event);

        $this->assertTrue($result);
    }
}
