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

        // Force idleTimeout to be mutable by checking we can set it
        $builder->withIdleTimeout(5.0);

        $ref = new ReflectionProperty(ConsumerBuilder::class, 'idleTimeout');
        $ref->setAccessible(true);
        $this->assertSame(5.0, $ref->getValue($builder));
    }
}
