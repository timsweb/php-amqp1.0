<?php

declare(strict_types=1);

namespace AMQP10\Tests\Mocks;

use AMQP10\Client\Client;
use AMQP10\Client\Config;
use AMQP10\Connection\Session;

/**
 * A Client subclass for unit tests that injects a pre-built Session
 * (backed by TransportMock) instead of opening a real connection.
 */
class ClientMock extends Client
{
    private Session $injectedSession;

    public function __construct(Session $session)
    {
        parent::__construct('amqp://test', new Config());
        $this->injectedSession = $session;
    }

    public function session(): Session
    {
        return $this->injectedSession;
    }

    public function reconnect(): static
    {
        // No-op in tests — session is pre-set
        return $this;
    }
}
