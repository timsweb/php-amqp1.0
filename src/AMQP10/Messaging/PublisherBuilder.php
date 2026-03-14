<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Client\Client;

class PublisherBuilder
{
    private bool       $preSettled         = false;
    private ?Publisher $cachedPublisher    = null;
    /** @phpstan-ignore-next-line */
    private int        $reconnectRetries   = 0;
    /** @phpstan-ignore-next-line */
    private int        $reconnectBackoffMs = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly string $address,
        private readonly float  $timeout      = 30.0,
        private readonly int    $maxFrameSize = 65536,
    ) {}

    public function fireAndForget(): self
    {
        $this->preSettled = true;
        return $this;
    }

    public function withReconnect(int $maxRetries = 10, int $backoffMs = 1000): self
    {
        $this->reconnectRetries   = $maxRetries;
        $this->reconnectBackoffMs = $backoffMs;
        return $this;
    }

    public function send(Message $message): Outcome
    {
        return $this->publisher()->send($message);
    }

    public function publisher(): Publisher
    {
        if ($this->cachedPublisher === null) {
            $this->cachedPublisher = new Publisher(
                $this->client->session(),
                $this->address,
                $this->timeout,
                $this->preSettled,
                $this->maxFrameSize,
            );
        }
        return $this->cachedPublisher;
    }

    public function close(): void
    {
        $this->cachedPublisher?->close();
        $this->cachedPublisher = null;
    }
}
