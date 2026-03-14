<?php

declare(strict_types=1);

namespace AMQP10\Tests\Integration;

class ConnectionIntegrationTest extends RabbitMqTestCase
{
    public function test_can_connect_and_disconnect(): void
    {
        $connectedBefore = false;
        $connectedAfter = true;
        $this->runInEventLoop(function () use (&$connectedBefore, &$connectedAfter): void {
            $client = $this->newClient()->connect();
            $connectedBefore = $client->isConnected();
            $client->close();
            $connectedAfter = $client->isConnected();
        });
        $this->assertTrue($connectedBefore);
        $this->assertFalse($connectedAfter);
    }
}
