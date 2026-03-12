<?php

namespace AMQP10\Messaging;

class Message
{
    public function __construct(
        private readonly string $body,
        private readonly array  $properties            = [],
        private readonly array  $applicationProperties = [],
        private readonly array  $annotations           = [],
        private readonly int    $ttl                   = 0,
        private readonly int    $priority              = 4,
    ) {}

    public function body(): string
    {
        return $this->body;
    }

    public function property(string $key): mixed
    {
        return $this->properties[$key] ?? null;
    }

    public function applicationProperty(string $key): mixed
    {
        return $this->applicationProperties[$key] ?? null;
    }

    public function annotation(string $key): mixed
    {
        return $this->annotations[$key] ?? null;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function properties(): array
    {
        return $this->properties;
    }

    public function applicationProperties(): array
    {
        return $this->applicationProperties;
    }

    public function annotations(): array
    {
        return $this->annotations;
    }
}
