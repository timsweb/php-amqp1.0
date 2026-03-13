<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Transport;

use AMQP10\Transport\RevoltTransport;
use AMQP10\Exception\ConnectionFailedException;
use PHPUnit\Framework\TestCase;

class RevoltTransportTest extends TestCase
{
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        return $pair;
    }

    public function test_isConnected_returns_false_before_connect(): void
    {
        $t = new RevoltTransport();
        $this->assertFalse($t->isConnected());
    }

    public function test_connect_fails_on_bad_address(): void
    {
        $this->expectException(ConnectionFailedException::class);
        $t = new RevoltTransport();
        $t->connect('amqp://127.0.0.1:19999'); // nothing listening
    }

    public function test_disconnect_is_safe_when_not_connected(): void
    {
        $t = new RevoltTransport();
        $t->disconnect(); // must not throw
        $this->assertFalse($t->isConnected());
    }
}
