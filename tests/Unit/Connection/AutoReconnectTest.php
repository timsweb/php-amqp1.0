<?php
declare(strict_types=1);
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\AutoReconnect;
use AMQP10\Exception\ConnectionFailedException;
use PHPUnit\Framework\TestCase;

class AutoReconnectTest extends TestCase
{
    public function test_succeeds_on_first_attempt(): void
    {
        $attempts  = 0;
        $reconnect = new AutoReconnect(
            connect: function () use (&$attempts) { $attempts++; },
            maxRetries: 3,
            backoffMs: 0,
        );
        $reconnect->run();
        $this->assertSame(1, $attempts);
    }

    public function test_retries_on_failure_then_succeeds(): void
    {
        $attempts  = 0;
        $reconnect = new AutoReconnect(
            connect: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new ConnectionFailedException('fail');
                }
            },
            maxRetries: 5,
            backoffMs: 0,
        );
        $reconnect->run();
        $this->assertSame(3, $attempts);
    }

    public function test_throws_after_max_retries_exceeded(): void
    {
        $reconnect = new AutoReconnect(
            connect: fn() => throw new ConnectionFailedException('always fail'),
            maxRetries: 2,
            backoffMs: 0,
        );
        $this->expectException(ConnectionFailedException::class);
        $this->expectExceptionMessage('Max retries exceeded');
        $reconnect->run();
    }
}
