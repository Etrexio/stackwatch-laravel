<?php

namespace StackWatch\Laravel\Tests;

use Mockery;
use Orchestra\Testbench\TestCase;
use StackWatch\Laravel\StackWatch;
use StackWatch\Laravel\StackWatchServiceProvider;
use StackWatch\Laravel\Transport\HttpTransport;

class MessageChunkingTest extends TestCase
{
    protected $transport;
    protected StackWatch $stackWatch;
    protected array $sent = [];

    protected function getPackageProviders($app): array
    {
        return [StackWatchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('stackwatch.api_key', 'test-api-key');
        $app['config']->set('stackwatch.enabled', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $this->transport = Mockery::mock(HttpTransport::class);
        $this->transport->shouldReceive('send')
            ->andReturnUsing(function (array $event) {
                $this->sent[] = $event;

                return 'evt-' . count($this->sent);
            })
            ->byDefault();

        $this->stackWatch = new StackWatch($this->transport);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_short_message_is_sent_unchanged(): void
    {
        $result = $this->stackWatch->captureLog('Hello world', 'info', ['foo' => 'bar']);

        $this->assertSame('evt-1', $result);
        $this->assertCount(1, $this->sent);
        $this->assertSame('Hello world', $this->sent[0]['message']);
        $this->assertSame('bar', $this->sent[0]['context']['foo']);
        $this->assertArrayNotHasKey('message_part', $this->sent[0]['context']);
    }

    public function test_message_at_exact_limit_is_not_split(): void
    {
        $message = str_repeat('a', StackWatch::DEFAULT_MAX_MESSAGE_LENGTH);

        $this->stackWatch->captureLog($message);

        $this->assertCount(1, $this->sent);
        $this->assertSame($message, $this->sent[0]['message']);
    }

    public function test_long_log_message_is_split_into_parts(): void
    {
        $message = str_repeat('x', 60000);

        $result = $this->stackWatch->captureLog($message, 'warning', ['foo' => 'bar']);

        $this->assertSame('evt-1', $result);
        $this->assertCount(3, $this->sent);

        $reassembled = '';
        $groups = [];

        foreach ($this->sent as $i => $event) {
            $number = $i + 1;

            $this->assertSame('log', $event['type']);
            $this->assertSame('warning', $event['level']);
            $this->assertLessThanOrEqual(StackWatch::DEFAULT_MAX_MESSAGE_LENGTH, mb_strlen($event['message']));
            $this->assertStringStartsWith("[part {$number}/3] ", $event['message']);

            $part = $event['context']['message_part'];
            $this->assertSame($number, $part['index']);
            $this->assertSame(3, $part['total']);
            $this->assertSame(60000, $part['original_length']);
            $this->assertSame('bar', $event['context']['foo']);

            $groups[] = $part['group'];
            $reassembled .= substr($event['message'], strlen("[part {$number}/3] "));
        }

        $this->assertSame($message, $reassembled);
        $this->assertCount(1, array_unique($groups));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $groups[0]);
    }

    public function test_split_is_multibyte_safe(): void
    {
        $message = str_repeat('ğüşİ€', 8000); // 40000 chars, 4 bytes for €

        $this->stackWatch->captureMessage($message, 'info');

        $this->assertCount(2, $this->sent);

        $reassembled = '';
        foreach ($this->sent as $i => $event) {
            $this->assertTrue(mb_check_encoding($event['message'], 'UTF-8'));
            $this->assertLessThanOrEqual(StackWatch::DEFAULT_MAX_MESSAGE_LENGTH, mb_strlen($event['message']));
            $reassembled .= mb_substr($event['message'], mb_strlen('[part ' . ($i + 1) . '/2] '));
        }

        $this->assertSame($message, $reassembled);
    }

    public function test_custom_event_messages_are_split_too(): void
    {
        $this->stackWatch->captureEvent('backup', 'info', str_repeat('b', 30000), ['disk' => 'local']);

        $this->assertCount(2, $this->sent);
        $this->assertSame('backup', $this->sent[0]['type']);
        $this->assertSame('backup', $this->sent[1]['type']);
        $this->assertSame('local', $this->sent[1]['context']['disk']);
        $this->assertSame(2, $this->sent[1]['context']['message_part']['index']);
    }

    public function test_limit_is_configurable(): void
    {
        config(['stackwatch.max_message_length' => 1000]);

        $this->stackWatch->captureLog(str_repeat('c', 2500));

        $this->assertCount(3, $this->sent);
        foreach ($this->sent as $event) {
            $this->assertLessThanOrEqual(1000, mb_strlen($event['message']));
        }
    }

    public function test_first_successful_part_id_is_returned(): void
    {
        $calls = 0;
        $this->transport->shouldReceive('send')
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                return $calls === 1 ? null : 'evt-' . $calls;
            });

        $result = $this->stackWatch->captureLog(str_repeat('d', 30000));

        $this->assertSame(2, $calls);
        $this->assertSame('evt-2', $result);
    }

    public function test_exception_message_is_truncated_not_split(): void
    {
        $exception = new \RuntimeException(str_repeat('e', 30000));

        $this->stackWatch->captureException($exception);

        $this->assertCount(1, $this->sent);
        $event = $this->sent[0];

        $this->assertSame('error', $event['type']);
        $this->assertSame(StackWatch::DEFAULT_MAX_MESSAGE_LENGTH, mb_strlen($event['message']));
        $this->assertStringEndsWith('… [truncated]', $event['message']);
        $this->assertSame($event['message'], $event['exception']['message']);
        $this->assertLessThanOrEqual(StackWatch::MAX_STACK_TRACE_LENGTH, mb_strlen($event['exception']['stack_trace']));
        $this->assertArrayNotHasKey('message_part', $event['context']);
    }

    public function test_short_exception_message_is_untouched(): void
    {
        $this->stackWatch->captureException(new \RuntimeException('boom'));

        $this->assertSame('boom', $this->sent[0]['message']);
        $this->assertSame('boom', $this->sent[0]['exception']['message']);
    }
}
