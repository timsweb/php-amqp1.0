<?php
declare(strict_types=1);

namespace AMQP10\Client;

use AMQP10\Connection\Connection;
use AMQP10\Connection\Sasl;
use AMQP10\Connection\Session;
use AMQP10\Management\Management;
use AMQP10\Messaging\ConsumerBuilder;
use AMQP10\Messaging\PublisherBuilder;
use AMQP10\Transport\RevoltTransport;
use AMQP10\Transport\TransportInterface;

class Client
{
    private ?Connection         $connection      = null;
    private ?Session            $session         = null;
    /** @phpstan-ignore-next-line */
    private ?TransportInterface $activeTransport = null;

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
        $transport  = $this->transport ?? new RevoltTransport(
            readTimeout: $this->config->timeout,
            tlsOptions:  $this->config->tlsOptions,
        );
        $this->activeTransport = $transport;

        $connection = new Connection($transport, $this->uri, $this->config->sasl, $this->config->timeout);
        $connection->open();

        $this->connection = $connection;
        $this->session    = new Session($transport, channel: 0, timeout: $this->config->timeout);
        $this->session->begin();

        return $this;
    }

    public function reconnect(): static
    {
        $this->close();
        return $this->connect();
    }

    public function close(): void
    {
        $this->session?->end();
        $this->connection?->close();
        $this->connection    = null;
        $this->session       = null;
        $this->activeTransport = null;
    }

    public function isConnected(): bool
    {
        return $this->connection?->isOpen() ?? false;
    }

    // --- Fluent config — do NOT mutate; return new instance ---

    public function withSasl(Sasl $sasl): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(sasl: $sasl);
        return $clone;
    }

    public function withTimeout(float $timeout): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(timeout: $timeout);
        return $clone;
    }

    /** @param array<string, mixed> $options */
    public function withTlsOptions(array $options): static
    {
        $clone         = clone $this;
        $clone->config = $this->config->with(tlsOptions: $options);
        return $clone;
    }

    public function config(): Config
    {
        return $this->config;
    }

    // --- API ---

    public function publish(string $address): PublisherBuilder
    {
        return new PublisherBuilder(
            $this,
            $address,
            $this->config->timeout,
            $this->connection?->negotiatedMaxFrameSize() ?? 65536,
        );
    }

    public function consume(string $address): ConsumerBuilder
    {
        return new ConsumerBuilder($this, $address, $this->config->timeout);
    }

    public function management(): Management
    {
        return new Management($this->session(), $this->config->timeout);
    }

    public function session(): Session
    {
        if ($this->session === null) {
            throw new \RuntimeException('Call connect() before using the client');
        }
        return $this->session;
    }
}
