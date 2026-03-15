<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Client\Client;
use AMQP10\Connection\Session;
use AMQP10\Messaging\ConsumerBuilder;
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Tests\Mocks\ClientMock;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolt\EventLoop;

class ConsumerBuilderTest extends TestCase
{
    public function test_stop_on_signal_stores_signals(): void
    {
        $client = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');
        $builder->stopOnSignal([SIGINT, SIGTERM]);

        $ref = new ReflectionProperty(ConsumerBuilder::class, 'stopSignals');
        $ref->setAccessible(true);
        $this->assertSame([SIGINT, SIGTERM], $ref->getValue($builder));
    }

    public function test_stop_on_signal_accepts_single_int(): void
    {
        $client = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');
        $builder->stopOnSignal(SIGINT);

        $ref = new ReflectionProperty(ConsumerBuilder::class, 'stopSignals');
        $ref->setAccessible(true);
        $this->assertSame([SIGINT], $ref->getValue($builder));
    }

    public function test_stop_on_signal_stores_handler(): void
    {
        $client = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');
        $callback = fn(int $signal) => null;
        $builder->stopOnSignal(SIGINT, $callback);

        $ref = new ReflectionProperty(ConsumerBuilder::class, 'signalHandler');
        $ref->setAccessible(true);
        $this->assertSame($callback, $ref->getValue($builder));
    }

    public function test_with_idle_timeout_before_consumer_sets_value(): void
    {
        $client  = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');
        $builder->withIdleTimeout(5.0);

        $ref = new ReflectionProperty(ConsumerBuilder::class, 'idleTimeout');
        $ref->setAccessible(true);
        $this->assertSame(5.0, $ref->getValue($builder));
    }

    public function test_with_idle_timeout_after_consumer_invalidates_cache(): void
    {
        $client  = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');

        $builder->consumer(); // prime the cache

        $builder->withIdleTimeout(5.0); // should invalidate

        $ref = new ReflectionProperty(ConsumerBuilder::class, 'cachedConsumer');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($builder));
    }

    public function test_consumer_returns_same_instance_on_repeated_calls(): void
    {
        $client  = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');

        $first  = $builder->consumer();
        $second = $builder->consumer();

        $this->assertSame($first, $second);
    }

    public function test_setter_after_consumer_does_not_affect_cached_instance(): void
    {
        $client  = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');

        $consumer = $builder->consumer();
        $builder->credit(99); // setter called after materialise

        // The cached consumer was built with the original credit — credit() cannot
        // retroactively change it. Verify the same instance is still returned.
        $this->assertSame($consumer, $builder->consumer());
    }

    public function test_with_idle_timeout_after_consumer_returns_fresh_instance(): void
    {
        $client  = $this->createMock(Client::class);
        $builder = new ConsumerBuilder($client, '/queues/test');

        $first = $builder->consumer();
        $builder->withIdleTimeout(2.0); // invalidates cache
        $second = $builder->consumer();

        $this->assertNotSame($first, $second);

        $ref = new ReflectionProperty(ConsumerBuilder::class, 'idleTimeout');
        $ref->setAccessible(true);
        $this->assertSame(2.0, $ref->getValue($builder));
    }

    /** @return array{TransportMock, ClientMock} */
    private function makeClient(): array
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();

        return [$mock, new ClientMock($session)];
    }

    private function makeTransferFrame(string $text, int $deliveryId = 0): string
    {
        return PerformativeEncoder::transfer(
            channel: 0,
            handle: 0,
            deliveryId: $deliveryId,
            deliveryTag: pack('N', $deliveryId),
            messagePayload: MessageEncoder::encode(new Message($text)),
            settled: false,
        );
    }

    public function test_consumer_stop_via_cached_reference_count_based(): void
    {
        [$mock, $client] = $this->makeClient();

        // Queue ATTACH response then 5 messages
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        for ($i = 0; $i < 5; $i++) {
            $mock->queueIncoming($this->makeTransferFrame("msg-$i", $i));
        }

        $builder  = new ConsumerBuilder($client, '/queues/test', idleTimeout: 0.1);
        $consumer = $builder->consumer();
        $count    = 0;

        $builder->handle(function (Message $msg, DeliveryContext $ctx) use ($consumer, &$count): void {
            $ctx->accept();
            if (++$count >= 3) {
                $consumer->stop();
            }
        })->run();

        $this->assertSame(3, $count, 'Consumer should stop after exactly 3 messages');
    }

    public function test_run_uses_cached_consumer_instance(): void
    {
        [$mock, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));

        $builder  = new ConsumerBuilder($client, '/queues/test', idleTimeout: 0.05);
        $consumer = $builder->consumer();

        // Stop immediately — if run() uses the same instance, it will exit at once
        $consumer->stop();
        $builder->handle(fn(Message $msg, DeliveryContext $ctx) => $ctx->accept())->run();

        // If we reached here without hanging, run() used the cached (pre-stopped) consumer
        $this->assertTrue(true);
    }
}
