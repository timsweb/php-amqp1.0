<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

readonly class Delivery
{
    public function __construct(
        private Message         $message,
        private DeliveryContext $context,
    ) {}

    public function message(): Message
    {
        return $this->message;
    }

    public function context(): DeliveryContext
    {
        return $this->context;
    }
}
