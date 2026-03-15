<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Client\Client;
use AMQP10\Messaging\ConsumerBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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
}
