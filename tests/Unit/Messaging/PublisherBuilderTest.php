<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Client\Client;
use AMQP10\Messaging\PublisherBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class PublisherBuilderTest extends TestCase
{
    public function test_publisher_is_cached_across_send_calls(): void
    {
        $client = $this->createMock(Client::class);
        $builder = new PublisherBuilder($client, '/queues/test');
        $ref = new ReflectionProperty(PublisherBuilder::class, 'cachedPublisher');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($builder));
    }

    public function test_fire_and_forget_sets_pre_settled_flag(): void
    {
        $client = $this->createMock(Client::class);
        $builder = new PublisherBuilder($client, '/queues/test');
        $builder->fireAndForget();
        $ref = new ReflectionProperty(PublisherBuilder::class, 'preSettled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($builder));
    }
}
