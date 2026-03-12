<?php
declare(strict_types=1);
namespace AMQP10\Connection;

use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeEncoder;

/**
 * An AMQP 1.0 sender link (role=sender, used for publishing).
 * Lifecycle: attach() → transfer() ... → detach()
 */
class SenderLink
{
    private bool $attached = false;
    private int  $handle;

    public function __construct(
        private readonly Session $session,
        private readonly string  $name,
        private readonly string  $target,
        private readonly ?string $source         = null,
        private readonly int     $sndSettleMode  = PerformativeEncoder::SND_UNSETTLED,
        private readonly bool    $managementLink = false,
    ) {
        $this->handle = $session->allocateHandle();
    }

    public function attach(): void
    {
        $properties = $this->managementLink
            ? [TypeEncoder::encodeSymbol('paired') => TypeEncoder::encodeBool(true)]
            : null;

        // initial_delivery_count is required for all sender links (AMQP spec §2.7.3)
        $initialDeliveryCount = 0;

        $this->session->transport()->send(PerformativeEncoder::attach(
            channel:              $this->session->channel(),
            name:                 $this->name,
            handle:               $this->handle,
            role:                 PerformativeEncoder::ROLE_SENDER,
            source:               $this->source,
            target:               $this->target,
            sndSettleMode:        $this->sndSettleMode,
            properties:           $properties,
            initialDeliveryCount: $initialDeliveryCount,
        ));
        $this->attached = true;
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

    public function transfer(string $messagePayload): int
    {
        $deliveryId  = $this->session->nextDeliveryId();
        $deliveryTag = pack('N', $deliveryId);
        $this->session->transport()->send(PerformativeEncoder::transfer(
            channel:        $this->session->channel(),
            handle:         $this->handle,
            deliveryId:     $deliveryId,
            deliveryTag:    $deliveryTag,
            messagePayload: $messagePayload,
            settled:        $this->sndSettleMode === PerformativeEncoder::SND_SETTLED,
        ));
        return $deliveryId;
    }

    public function handle(): int { return $this->handle; }
    public function isAttached(): bool { return $this->attached; }
    public function isPreSettled(): bool { return $this->sndSettleMode === PerformativeEncoder::SND_SETTLED; }
}
