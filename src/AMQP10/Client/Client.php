<?php

namespace AMQP10\Client;

use AMQP10\Connection\AutoReconnect;
use AMQP10\Connection\Connection;
use AMQP10\Connection\Sasl;
use AMQP10\Connection\Session;
use AMQP10\Management\Management;
use AMQP10\Messaging\ConsumerBuilder;
use AMQP10\Messaging\PublisherBuilder;
use AMQP10\Transport\BlockingAdapter;
use AMQP10\Transport\TransportInterface;

class Client
{
    private ?Connection $connection = null;
    private ?Session    $session    = null;

    public function __construct(
        private readonly string $uri,
        private Config $config = new Config(),
        private readonly ?TransportInterface $transport = null,
    ) {}

    /**
     * Open the connection. Must be called before publish/consume/management.
     */
    public function connect(): static
    {
        $transport  = $this->transport ?? new BlockingAdapter();
        $connection = new Connection($transport, $this->uri, $this->config->sasl);

        if ($this->config->autoReconnect) {
            $reconnect = new AutoReconnect(
                connect:    fn() => $connection->open(),
                maxRetries: $this->config->maxRetries,
                backoffMs:  $this->config->backoffMs,
            );
            $reconnect->run();
        } else {
            $connection->open();
        }

        $this->connection = $connection;
        $this->session    = new Session($transport, channel: 0);
        $this->session->begin();

        return $this;
    }

    public function close(): void
    {
        $this->session?->end();
        $this->connection?->close();
        $this->connection = null;
        $this->session    = null;
    }

    public function isConnected(): bool
    {
        return $this->connection?->isOpen() ?? false;
    }

    // --- Fluent config — do NOT mutate; return new instance ---

    public function withAutoReconnect(int $maxRetries = 5, int $backoffMs = 1000): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(autoReconnect: true, maxRetries: $maxRetries, backoffMs: $backoffMs);
        return $clone;
    }

    public function withSasl(Sasl $sasl): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(sasl: $sasl);
        return $clone;
    }

    public function config(): Config
    {
        return $this->config;
    }

    // --- API ---

    public function publish(string $address): PublisherBuilder
    {
        return new PublisherBuilder($this->session(), $address);
    }

    public function consume(string $address): ConsumerBuilder
    {
        return new ConsumerBuilder($this->session(), $address);
    }

    public function management(): Management
    {
        return new Management($this->session());
    }

    private function session(): Session
    {
        if ($this->session === null) {
            throw new \RuntimeException('Call connect() before using the client');
        }
        return $this->session;
    }
}
