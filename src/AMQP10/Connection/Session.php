<?php
namespace AMQP10\Connection;

use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Transport\TransportInterface;

/**
 * Manages an AMQP 1.0 session (BEGIN/END).
 */
class Session
{
    private bool $open           = false;
    private int  $nextOutgoingId = 0;
    private int  $nextLinkHandle = 0;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $channel,
        private readonly int $incomingWindow = 2048,
        private readonly int $outgoingWindow = 2048,
    ) {}

    public function begin(): void
    {
        $this->transport->send(PerformativeEncoder::begin(
            channel:        $this->channel,
            nextOutgoingId: $this->nextOutgoingId,
            incomingWindow: $this->incomingWindow,
            outgoingWindow: $this->outgoingWindow,
        ));
        $this->open = true;
    }

    public function end(): void
    {
        if ($this->open) {
            $this->transport->send(PerformativeEncoder::end($this->channel));
            $this->open = false;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function channel(): int
    {
        return $this->channel;
    }

    public function allocateHandle(): int
    {
        return $this->nextLinkHandle++;
    }

    public function nextDeliveryId(): int
    {
        return $this->nextOutgoingId++;
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }
}
