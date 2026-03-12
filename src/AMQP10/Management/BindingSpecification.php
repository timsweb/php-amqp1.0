<?php
declare(strict_types=1);
namespace AMQP10\Management;

readonly class BindingSpecification
{
    public function __construct(
        public readonly string  $sourceExchange,
        public readonly string  $destinationQueue,
        public readonly ?string $bindingKey = null,
    ) {}
}
