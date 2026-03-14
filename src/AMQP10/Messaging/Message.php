<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

class Message
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $applicationProperties
     * @param array<string, mixed> $annotations
     */
    public function __construct(
        private readonly string  $body,
        private readonly array   $properties            = [],
        private readonly array   $applicationProperties = [],
        private readonly array   $annotations           = [],
        private readonly int     $ttl                   = 0,
        private readonly int     $priority              = 4,
        private readonly bool    $durable               = true,
        private readonly ?string $subject               = null,
    ) {}

    public static function create(string $body): self
    {
        return new self($body);
    }

    public function body(): string { return $this->body; }
    public function durable(): bool { return $this->durable; }
    public function subject(): ?string { return $this->subject; }
    public function ttl(): int { return $this->ttl; }
    public function priority(): int { return $this->priority; }

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

    /** @return array<string, mixed> */
    public function properties(): array { return $this->properties; }

    /** @return array<string, mixed> */
    public function applicationProperties(): array { return $this->applicationProperties; }

    /** @return array<string, mixed> */
    public function annotations(): array { return $this->annotations; }

    public function withDurable(bool $durable): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $this->ttl, $this->priority, $durable, $this->subject);
    }

    public function withSubject(string $subject): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $this->ttl, $this->priority, $this->durable, $subject);
    }

    public function withMessageId(string $id): self
    {
        return $this->withProperty('message-id', $id);
    }

    public function withCorrelationId(string $id): self
    {
        return $this->withProperty('correlation-id', $id);
    }

    public function withReplyTo(string $address): self
    {
        return $this->withProperty('reply-to', $address);
    }

    public function withContentType(string $contentType): self
    {
        return $this->withProperty('content-type', $contentType);
    }

    public function withTtl(int $ttl): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $ttl, $this->priority, $this->durable, $this->subject);
    }

    public function withPriority(int $priority): self
    {
        return new self($this->body, $this->properties, $this->applicationProperties,
            $this->annotations, $this->ttl, $priority, $this->durable, $this->subject);
    }

    public function withApplicationProperty(string $key, mixed $value): self
    {
        $props = $this->applicationProperties;
        $props[$key] = $value;
        return new self($this->body, $this->properties, $props,
            $this->annotations, $this->ttl, $this->priority, $this->durable, $this->subject);
    }

    public function withAnnotation(string $key, mixed $value): self
    {
        $anns = $this->annotations;
        $anns[$key] = $value;
        return new self($this->body, $this->properties, $this->applicationProperties,
            $anns, $this->ttl, $this->priority, $this->durable, $this->subject);
    }

    private function withProperty(string $key, mixed $value): self
    {
        $props = $this->properties;
        $props[$key] = $value;
        return new self($this->body, $props, $this->applicationProperties,
            $this->annotations, $this->ttl, $this->priority, $this->durable, $this->subject);
    }
}
