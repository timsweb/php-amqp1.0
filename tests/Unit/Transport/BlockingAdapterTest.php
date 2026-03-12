<?php
declare(strict_types=1);
namespace AMQP10\Tests\Transport;

use AMQP10\Transport\BlockingAdapter;
use AMQP10\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class BlockingAdapterTest extends TestCase
{
    public function test_implements_transport_interface(): void
    {
        $this->assertInstanceOf(TransportInterface::class, new BlockingAdapter());
    }

    public function test_is_not_connected_initially(): void
    {
        $adapter = new BlockingAdapter();
        $this->assertFalse($adapter->isConnected());
    }

    public function test_connect_to_invalid_host_throws(): void
    {
        $this->expectException(\AMQP10\Exception\ConnectionFailedException::class);
        $adapter = new BlockingAdapter();
        $adapter->connect('amqp://localhost:1');
    }
}
