<?php

declare(strict_types=1);

namespace AMQP10\Management;

readonly class QueueSpecification
{
    public function __construct(
        public readonly string $name,
        public readonly QueueType $type = QueueType::QUORUM,
        public readonly bool $durable = true,
    ) {}
}
