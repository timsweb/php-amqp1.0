<?php
namespace AMQP10\Management;

readonly class BindingSpecification
{
    public function __construct(
        public readonly string  $sourceExchange,
        public readonly string  $destinationQueue,
        public readonly ?string $bindingKey = null,
    ) {}
}
