<?php

namespace AMQP10\Tests\Client;

use AMQP10\Client\Client;
use AMQP10\Client\Config;
use AMQP10\Connection\Sasl;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function test_client_is_not_connected_before_connect(): void
    {
        $client = new Client('amqp://guest:guest@localhost:5672/');
        $this->assertFalse($client->isConnected());
    }

    public function test_with_auto_reconnect_returns_new_client_instance(): void
    {
        $client  = new Client('amqp://guest:guest@localhost:5672/');
        $client2 = $client->withAutoReconnect(maxRetries: 3, backoffMs: 500);
        // Fluent methods must NOT mutate the original
        $this->assertNotSame($client, $client2);
    }

    public function test_with_sasl_does_not_lose_auto_reconnect_config(): void
    {
        $client = new Client('amqp://guest:guest@localhost:5672/');
        $client = $client->withAutoReconnect(maxRetries: 3, backoffMs: 500);
        $client = $client->withSasl(Sasl::plain('u', 'p'));

        // withSasl must not overwrite autoReconnect config
        $config = $client->config();
        $this->assertTrue($config->autoReconnect);
        $this->assertSame(3, $config->maxRetries);
    }
}
