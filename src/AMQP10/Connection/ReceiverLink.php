<?php
declare(strict_types=1);
namespace AMQP10\Connection;

use AMQP10\Protocol\PerformativeEncoder;

/**
 * An AMQP 1.0 receiver link (role=receiver, used for consuming).
 * Lifecycle: attach() → grantCredit() → [receive messages] → detach()
 */
class ReceiverLink
{
    private bool $attached = false;
    private int  $handle;

    public function __construct(
        private readonly Session $session,
        private readonly string  $name,
        private readonly string  $source,
        private readonly int     $initialCredit = 10,
    ) {
        $this->handle = $session->allocateHandle();
    }

    public function attach(): void
    {
        $this->session->transport()->send(PerformativeEncoder::attach(
            channel: $this->session->channel(),
            name:    $this->name,
            handle:  $this->handle,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  $this->source,
        ));
        $this->attached = true;
        $this->grantCredit($this->initialCredit);
    }

    public function grantCredit(int $credit, int $deliveryCount = 0): void
    {
        $this->session->transport()->send(PerformativeEncoder::flow(
            channel:        $this->session->channel(),
            nextIncomingId: 0,
            incomingWindow: 2048,
            nextOutgoingId: 0,
            outgoingWindow: 2048,
            handle:         $this->handle,
            deliveryCount:  $deliveryCount,
            linkCredit:     $credit,
        ));
    }

    public function settle(int $deliveryId, string $outcome): void
    {
        $this->session->transport()->send(PerformativeEncoder::disposition(
            channel: $this->session->channel(),
            role:    true,
            first:   $deliveryId,
            settled: true,
            state:   $outcome,
        ));
    }

    public function detach(): void
    {
        if ($this->attached) {
            $this->session->transport()->send(PerformativeEncoder::detach(
                channel: $this->session->channel(),
                handle:  $this->handle,
            ));
            $this->attached = false;
        }
    }

    public function handle(): int { return $this->handle; }
    public function isAttached(): bool { return $this->attached; }
}
