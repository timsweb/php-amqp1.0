<?php

declare(strict_types=1);

namespace AMQP10\Connection;

use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeEncoder;

/**
 * An AMQP 1.0 sender link (role=sender, used for publishing).
 * Lifecycle: attach() → transfer() ... → detach()
 */
class SenderLink
{
    private bool $attached = false;

    private int $handle;

    public function __construct(
        private readonly Session $session,
        private readonly string $name,
        private readonly string $target,
        private readonly ?string $source = null,
        private readonly int $sndSettleMode = PerformativeEncoder::SND_UNSETTLED,
        private readonly bool $managementLink = false,
        private readonly int $maxFrameSize = 65536,
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
            channel: $this->session->channel(),
            name: $this->name,
            handle: $this->handle,
            role: PerformativeEncoder::ROLE_SENDER,
            source: $this->source,
            target: $this->target,
            sndSettleMode: $this->sndSettleMode,
            properties: $properties,
            initialDeliveryCount: $initialDeliveryCount,
        ));
        $this->session->readFrameOfType(Descriptor::ATTACH, $this->handle);
        $this->attached = true;
    }

    public function detach(): void
    {
        if ($this->attached) {
            $this->session->transport()->send(PerformativeEncoder::detach(
                channel: $this->session->channel(),
                handle: $this->handle,
            ));
            $this->attached = false;
        }
    }

    public function transfer(string $messagePayload): int
    {
        $deliveryId = $this->session->nextDeliveryId();
        $deliveryTag = pack('N', $deliveryId);
        $settled = $this->sndSettleMode === PerformativeEncoder::SND_SETTLED;

        $overhead = 50; // frame header + TRANSFER performative
        $chunkSize = max(1, $this->maxFrameSize - $overhead);

        if (strlen($messagePayload) <= $chunkSize) {
            $this->session->transport()->send(PerformativeEncoder::transfer(
                channel: $this->session->channel(),
                handle: $this->handle,
                deliveryId: $deliveryId,
                deliveryTag: $deliveryTag,
                messagePayload: $messagePayload,
                settled: $settled,
                more: false,
            ));

            return $deliveryId;
        }

        $chunks = str_split($messagePayload, $chunkSize);
        $lastIndex = count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $isFirst = $i === 0;
            $isLast = $i === $lastIndex;
            $this->session->transport()->send(PerformativeEncoder::transfer(
                channel: $this->session->channel(),
                handle: $this->handle,
                deliveryId: $isFirst ? $deliveryId : null,
                deliveryTag: $isFirst ? $deliveryTag : null,
                messagePayload: $chunk,
                settled: $settled,
                more: ! $isLast,
            ));
        }

        return $deliveryId;
    }

    public function handle(): int
    {
        return $this->handle;
    }

    public function isAttached(): bool
    {
        return $this->attached;
    }

    public function isPreSettled(): bool
    {
        return $this->sndSettleMode === PerformativeEncoder::SND_SETTLED;
    }
}
