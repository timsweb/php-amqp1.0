<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

class ConnectionIntegrationTest extends IntegrationTestCase
{
    public function test_can_connect_and_disconnect(): void
    {
        $client = $this->newClient()->connect();
        $this->assertTrue($client->isConnected());
        $client->close();
        $this->assertFalse($client->isConnected());
    }
}
