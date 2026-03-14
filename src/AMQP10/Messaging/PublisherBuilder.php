<?php

declare(strict_types=1);

namespace AMQP10\Messaging;

use AMQP10\Client\Client;
use AMQP10\Exception\ConnectionFailedException;
use Revolt\EventLoop;
use Fiber;
use RuntimeException;

class PublisherBuilder
{
    private bool $preSettled = false;

    private ?Publisher $cachedPublisher = null;

    private int $reconnectRetries = 0;

    private int $reconnectBackoffMs = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly string $address,
        private readonly float $timeout = 30.0,
        private readonly int $maxFrameSize = 65536,
    ) {}

    public function fireAndForget(): self
    {
        if (! $this->preSettled) {
            $this->preSettled = true;
            $this->cachedPublisher = null;
        }

        return $this;
    }

    public function withReconnect(int $maxRetries = 10, int $backoffMs = 1000): self
    {
        $this->reconnectRetries = $maxRetries;
        $this->reconnectBackoffMs = $backoffMs;

        return $this;
    }

    public function send(Message $message): Outcome
    {
        $attempts = 0;
        while (true) {
            try {
                return $this->publisher()->send($message);
            } catch (ConnectionFailedException|RuntimeException $e) {
                if ($this->reconnectRetries === 0 || $attempts >= $this->reconnectRetries) {
                    throw $e;
                }
                $attempts++;
                $backoffUs = $this->reconnectBackoffMs * $attempts * 1000;
                if (Fiber::getCurrent() !== null) {
                    $suspension = EventLoop::getSuspension();
                    EventLoop::delay($backoffUs / 1_000_000, static fn() => $suspension->resume());
                    $suspension->suspend();
                } else {
                    usleep($backoffUs);
                }
                $this->cachedPublisher?->close();
                $this->cachedPublisher = null;
                $this->client->reconnect();
            }
        }
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
