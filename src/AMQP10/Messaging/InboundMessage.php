<?php

declare(strict_types=1);

namespace AMQP10\Messaging;

readonly class InboundMessage
{
    public function __construct(
        private Message $message,
        private DeliveryContext $context,
    ) {}

    public function body(): string
    {
        return $this->message->body();
    }

    public function subject(): ?string
    {
        return $this->message->subject();
    }

    public function durable(): bool
    {
        return $this->message->durable();
    }

    public function ttl(): int
    {
        return $this->message->ttl();
    }

    public function priority(): int
    {
        return $this->message->priority();
    }

    public function property(string $key): mixed
    {
        return $this->message->property($key);
    }

    public function applicationProperty(string $key): mixed
    {
        return $this->message->applicationProperty($key);
    }

    public function annotation(string $key): mixed
    {
        return $this->message->annotation($key);
    }

    /** @return array<string, mixed> */
    public function properties(): array
    {
        return $this->message->properties();
    }

    /** @return array<string, mixed> */
    public function applicationProperties(): array
    {
        return $this->message->applicationProperties();
    }

    /** @return array<string, mixed> */
    public function annotations(): array
    {
        return $this->message->annotations();
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function accept(): void
    {
        $this->context->accept();
    }

    public function reject(): void
    {
        $this->context->reject();
    }

    public function release(): void
    {
        $this->context->release();
    }

    public function modify(bool $deliveryFailed = true, bool $undeliverableHere = true): void
    {
        $this->context->modify($deliveryFailed, $undeliverableHere);
    }
}
