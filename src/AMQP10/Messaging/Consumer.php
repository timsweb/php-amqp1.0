<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;

class Consumer
{
    private readonly ReceiverLink $link;

    public function __construct(
        private readonly Session  $session,
        private readonly string   $address,
        private readonly int      $credit       = 10,
        private readonly ?Offset  $offset       = null,
        private readonly ?string  $filterSql    = null,
        private readonly array    $filterValues = [],
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
            $pairs[TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec')] =
                TypeEncoder::encodeDescribed(
                    TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec'),
                    $offsetValue,
                );
        }

        if ($this->filterSql !== null) {
            $pairs[TypeEncoder::encodeSymbol('apache.org:selector-filter:string')] =
                TypeEncoder::encodeDescribed(
                    TypeEncoder::encodeSymbol('apache.org:selector-filter:string'),
                    TypeEncoder::encodeString($this->filterSql),
                );
        }

        return TypeEncoder::encodeMap($pairs);
    }

    public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
    {
        $this->link->attach();

        while (true) {
            $frame = $this->session->nextFrame();
            if ($frame === null) {
                break;
            }
            if ($this->getFrameDescriptor($frame) === Descriptor::TRANSFER) {
                $this->handleTransfer($frame, $handler, $errorHandler);
            }
        }

        try {
            $this->link->detach();
        } catch (\Throwable) {
        }
    }

    private function getFrameDescriptor(string $frame): ?int
    {
        $body        = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();
        return is_array($performative) ? ($performative['descriptor'] ?? null) : null;
    }

    private function handleTransfer(string $frame, ?\Closure $handler, ?\Closure $errorHandler): void
    {
        $body         = FrameParser::extractBody($frame);
        $decoder      = new TypeDecoder($body);
        $performative = $decoder->decode();

        $deliveryId     = $performative['value'][1] ?? 0;
        $messagePayload = substr($body, $decoder->offset());
        $message        = MessageDecoder::decode($messagePayload);
        $ctx            = new DeliveryContext($deliveryId, $this->link);

        if ($handler !== null) {
            try {
                $handler($message, $ctx);
            } catch (\Throwable $e) {
                if ($errorHandler !== null) {
                    $errorHandler($e);
                }
            }
        }
    }
}
