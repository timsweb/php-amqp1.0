<?php
declare(strict_types=1);
namespace AMQP10\Tests\Management;

use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use PHPUnit\Framework\TestCase;

class SpecificationTest extends TestCase
{
    public function test_exchange_spec_defaults(): void
    {
        $spec = new ExchangeSpecification('test');
        $this->assertSame('test', $spec->name);
        $this->assertSame(ExchangeType::DIRECT, $spec->type);
        $this->assertTrue($spec->durable);
    }

    public function test_queue_spec(): void
    {
        $spec = new QueueSpecification('my-queue', QueueType::QUORUM);
        $this->assertSame('my-queue', $spec->name);
        $this->assertSame(QueueType::QUORUM, $spec->type);
        $this->assertTrue($spec->durable);
    }
}
