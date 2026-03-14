<?php

declare(strict_types=1);

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

    public function test_with_sasl_returns_new_instance(): void
    {
        $client = new Client('amqp://guest:guest@localhost:5672/');
        $client2 = $client->withSasl(Sasl::plain('u', 'p'));
        $this->assertNotSame($client, $client2);
        $this->assertNull($client->config()->sasl);
        $this->assertNotNull($client2->config()->sasl);
    }

    public function test_config_timeout_default(): void
    {
        $config = new Config();
        $this->assertSame(30.0, $config->timeout);
    }

    public function test_config_with_timeout(): void
    {
        $config = new Config();
        $updated = $config->with(timeout: 5.0);
        $this->assertSame(5.0, $updated->timeout);
        $this->assertSame(30.0, $config->timeout); // original unchanged
    }

    public function test_with_timeout_fluent_method(): void
    {
        $client = new Client('amqp://localhost/');
        $client2 = $client->withTimeout(10.0);

        $this->assertNotSame($client, $client2);
        $this->assertSame(10.0, $client2->config()->timeout);
        $this->assertSame(30.0, $client->config()->timeout);
    }

    public function test_with_tls_options_returns_new_instance(): void
    {
        $client = new Client('amqps://localhost/');
        $client2 = $client->withTlsOptions(['verify_peer' => false]);

        $this->assertNotSame($client, $client2);
        $this->assertSame(['verify_peer' => false], $client2->config()->tlsOptions);
        $this->assertSame([], $client->config()->tlsOptions);
    }
}
