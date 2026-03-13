<?php

namespace StackWatch\Laravel\Tests;

use StackWatch\Laravel\StackWatch;
use StackWatch\Laravel\Transport\HttpTransport;
use Mockery;

class StackWatchTest extends TestCase
{
    protected StackWatch $stackWatch;
    protected $mockTransport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = Mockery::mock(HttpTransport::class);
        $this->mockTransport->shouldReceive('send')->andReturn(true)->byDefault();

        $config = [
            'api_key' => 'test-api-key',
            'endpoint' => 'https://api.stackwatch.dev/v1',
            'environment' => 'testing',
            'release' => '1.0.0',
            'breadcrumbs' => [
                'max' => 50,
            ],
        ];

        $this->stackWatch = new StackWatch($this->mockTransport, $config);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_can_capture_exception(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return $event['type'] === 'error'
                    && $event['exception']['message'] === 'Test exception';
            })
            ->andReturn(true);

        $exception = new \Exception('Test exception');
        $result = $this->stackWatch->captureException($exception);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_can_capture_message(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return $event['type'] === 'message'
                    && $event['message'] === 'Test message'
                    && $event['level'] === 'info';
            })
            ->andReturn(true);

        $result = $this->stackWatch->captureMessage('Test message', 'info');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_can_add_breadcrumbs(): void
    {
        $this->stackWatch->addBreadcrumb('test', 'Test breadcrumb', ['key' => 'value']);

        $breadcrumbs = $this->stackWatch->getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('test', $breadcrumbs[0]['category']);
        $this->assertEquals('Test breadcrumb', $breadcrumbs[0]['message']);
        $this->assertEquals(['key' => 'value'], $breadcrumbs[0]['data']);
    }

    public function test_breadcrumbs_limit_is_respected(): void
    {
        // Create StackWatch with max 5 breadcrumbs
        $config = [
            'api_key' => 'test-api-key',
            'breadcrumbs' => ['max' => 5],
        ];
        $stackWatch = new StackWatch($this->mockTransport, $config);

        // Add 10 breadcrumbs
        for ($i = 1; $i <= 10; $i++) {
            $stackWatch->addBreadcrumb('test', "Breadcrumb $i");
        }

        $breadcrumbs = $stackWatch->getBreadcrumbs();

        $this->assertCount(5, $breadcrumbs);
        // Should keep the last 5 breadcrumbs
        $this->assertEquals('Breadcrumb 6', $breadcrumbs[0]['message']);
        $this->assertEquals('Breadcrumb 10', $breadcrumbs[4]['message']);
    }

    public function test_can_clear_breadcrumbs(): void
    {
        $this->stackWatch->addBreadcrumb('test', 'Test breadcrumb');
        $this->assertCount(1, $this->stackWatch->getBreadcrumbs());

        $this->stackWatch->clearBreadcrumbs();

        $this->assertCount(0, $this->stackWatch->getBreadcrumbs());
    }

    public function test_can_set_user(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return $event['user']['id'] === '123'
                    && $event['user']['email'] === 'test@example.com'
                    && $event['user']['username'] === 'testuser';
            })
            ->andReturn(true);

        $this->stackWatch->setUser('123', 'test@example.com', 'testuser');
        $this->stackWatch->captureMessage('Test');
    }

    public function test_can_set_tags(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return $event['tags']['version'] === '2.0'
                    && $event['tags']['feature'] === 'checkout';
            })
            ->andReturn(true);

        $this->stackWatch->setTag('version', '2.0');
        $this->stackWatch->setTag('feature', 'checkout');
        $this->stackWatch->captureMessage('Test');
    }

    public function test_can_set_extra(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return $event['extra']['order_id'] === '456'
                    && $event['extra']['amount'] === 99.99;
            })
            ->andReturn(true);

        $this->stackWatch->setExtra('order_id', '456');
        $this->stackWatch->setExtra('amount', 99.99);
        $this->stackWatch->captureMessage('Test');
    }

    public function test_can_capture_performance(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return $event['type'] === 'performance'
                    && $event['transaction'] === 'api.test'
                    && $event['duration'] === 150.5;
            })
            ->andReturn(true);

        $result = $this->stackWatch->capturePerformance('api.test', 150.5, [
            'method' => 'GET',
        ]);

        $this->assertIsString($result);
    }

    public function test_exception_includes_stack_trace(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return isset($event['exception']['stacktrace'])
                    && is_array($event['exception']['stacktrace'])
                    && count($event['exception']['stacktrace']) > 0;
            })
            ->andReturn(true);

        $exception = new \Exception('Test exception');
        $this->stackWatch->captureException($exception);
    }

    public function test_event_includes_context(): void
    {
        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return isset($event['environment'])
                    && $event['environment'] === 'testing'
                    && isset($event['release'])
                    && isset($event['timestamp'])
                    && isset($event['event_id']);
            })
            ->andReturn(true);

        $this->stackWatch->captureMessage('Test');
    }

    public function test_breadcrumbs_included_in_event(): void
    {
        $this->stackWatch->addBreadcrumb('http', 'GET /api/users');
        $this->stackWatch->addBreadcrumb('user', 'Clicked button');

        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return isset($event['breadcrumbs'])
                    && count($event['breadcrumbs']) === 2
                    && $event['breadcrumbs'][0]['category'] === 'http'
                    && $event['breadcrumbs'][1]['category'] === 'user';
            })
            ->andReturn(true);

        $this->stackWatch->captureMessage('Test');
    }

    public function test_clear_tags(): void
    {
        $this->stackWatch->setTag('version', '1.0');
        $this->stackWatch->clearTags();

        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return empty($event['tags']);
            })
            ->andReturn(true);

        $this->stackWatch->captureMessage('Test');
    }

    public function test_clear_user(): void
    {
        $this->stackWatch->setUser('123', 'test@example.com');
        $this->stackWatch->clearUser();

        $this->mockTransport
            ->shouldReceive('send')
            ->once()
            ->withArgs(function ($event) {
                return !isset($event['user']);
            })
            ->andReturn(true);

        $this->stackWatch->captureMessage('Test');
    }
}
