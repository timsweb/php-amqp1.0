<?php

namespace AMQP10\Messaging;

/**
 * Stream consumer offset — where to start reading from a stream.
 */
class Offset
{
    private function __construct(
        public readonly string $type,
        public readonly mixed  $value = null,
    ) {}

    public static function first(): self
    {
        return new self('first');
    }

    public static function last(): self
    {
        return new self('last');
    }

    public static function next(): self
    {
        return new self('next');
    }

    public static function offset(int $n): self
    {
        return new self('offset', $n);
    }

    public static function timestamp(int $ms): self
    {
        return new self('timestamp', $ms);
    }
}
