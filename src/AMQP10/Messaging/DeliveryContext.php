<?php
declare(strict_types=1);

namespace AMQP10\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Protocol\PerformativeEncoder;

class DeliveryContext
{
    public function __construct(
        private readonly int          $deliveryId,
        private readonly ReceiverLink $link,
    ) {}

    public function accept(): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::accepted());
    }

    public function reject(): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::rejected());
    }

    public function release(): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::released());
    }

    public function modify(bool $deliveryFailed = true, bool $undeliverableHere = true): void
    {
        $this->link->settle($this->deliveryId, PerformativeEncoder::modified($deliveryFailed, $undeliverableHere));
    }
}
