<?php
declare(strict_types=1);
namespace AMQP10\Management;

readonly class ExchangeSpecification
{
    public function __construct(
        public readonly string       $name,
        public readonly ExchangeType $type       = ExchangeType::DIRECT,
        public readonly bool         $durable    = true,
        public readonly bool         $autoDelete = false,
    ) {}
}
