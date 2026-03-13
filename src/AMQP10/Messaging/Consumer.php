<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\FrameException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;

class Consumer
{
    private readonly ReceiverLink $link;
    private bool $attached = false;

    public function __construct(
        private readonly Session  $session,
        string                   $address,
        int                      $credit       = 10,
        private readonly ?Offset  $offset       = null,
        private readonly ?string  $filterSql    = null,
        private readonly float    $idleTimeout  = 30.0,
    ) {
        $linkName   = 'receiver-' . bin2hex(random_bytes(4));
        $this->link = new ReceiverLink(
            $session,
            name:          $linkName,
            source:        $address,
            initialCredit: $credit,
            filterMap:     $this->buildFilterMap(),
        );
    }

    private function buildFilterMap(): ?string
    {
        if ($this->offset === null && $this->filterSql === null) {
            return null;
        }

        $pairs = [];

        if ($this->offset !== null) {
            $offsetValue = match ($this->offset->type) {
                'first', 'last', 'next' => TypeEncoder::encodeSymbol($this->offset->type),
                'offset'                => TypeEncoder::encodeUlong((int) $this->offset->value),
                'timestamp'             => TypeEncoder::encodeTimestamp((int) $this->offset->value),
                default                 => TypeEncoder::encodeSymbol('first'),
            };
            // Filter-set values must be described types per AMQP 1.0 spec §3.5.
            // Descriptor is the same symbol as the map key (rabbitmq:stream-offset-spec).
            $descriptor = TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec');
            $pairs[$descriptor] = TypeEncoder::encodeDescribed($descriptor, $offsetValue);
        }

        if ($this->filterSql !== null) {
            $pairs[TypeEncoder::encodeSymbol('apache.org:selector-filter:string')] =
                TypeEncoder::encodeString($this->filterSql);
        }

        return TypeEncoder::encodeMap($pairs);
    }

    private function ensureAttached(): void
    {
        if (!$this->attached) {
            $this->link->attach();
            $this->attached = true;
        }
    }

    public function receive(): ?Delivery
    {
        $this->ensureAttached();

        $deadline = microtime(true) + $this->idleTimeout;
        while (true) {
            $frame = $this->session->nextFrame();
            if ($frame === null) {
                if (!$this->session->transport()->isConnected()) {
                    return null;
                }
                if (microtime(true) >= $deadline) {
                    return null;
                }
                usleep(1000);
                continue;
            }
            if ($this->getFrameDescriptor($frame) === Descriptor::TRANSFER) {
                return $this->extractDelivery($frame);
            }
        }
    }

    public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
    {
        while ($delivery = $this->receive()) {
            if ($handler !== null) {
                try {
                    $handler($delivery->message(), $delivery->context());
                } catch (\Throwable $e) {
                    if ($errorHandler !== null) {
                        $errorHandler($e);
                    }
                }
            }
        }

        $this->close();
    }

    public function close(): void
    {
        try {
            $this->link->detach();
        } catch (\Throwable) {
        }
    }

    private function getFrameDescriptor(string $frame): ?int
    {
        if (strlen($frame) < 8) {
            return null;
        }
        $body = FrameParser::extractBody($frame);
        try {
            $performative = (new TypeDecoder($body))->decode();
            return is_array($performative) ? ($performative['descriptor'] ?? null) : null;
        } catch (FrameException $e) {
            return null;
        }
    }

    private function extractDelivery(string $frame): Delivery
    {
        $body         = FrameParser::extractBody($frame);
        $decoder      = new TypeDecoder($body);
        $performative = $decoder->decode();

        $deliveryId     = $performative['value'][1] ?? 0;
        $messagePayload = substr($body, $decoder->offset());
        $message        = MessageDecoder::decode($messagePayload);
        $ctx            = new DeliveryContext($deliveryId, $this->link);

        return new Delivery($message, $ctx);
    }
}
