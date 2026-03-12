<?php
declare(strict_types=1);
namespace AMQP10\Tests\Transport;

use AMQP10\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

class TransportInterfaceTest extends TestCase
{
    public function test_interface_exists(): void
    {
        $this->assertTrue(interface_exists(TransportInterface::class));
    }
}
