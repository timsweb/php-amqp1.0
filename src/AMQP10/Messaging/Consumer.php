<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

class Consumer
{
    private readonly ReceiverLink $link;
    private readonly FrameParser  $parser;

    public function __construct(
        private readonly Session  $session,
        private readonly string   $address,
        private readonly int      $credit       = 10,
        private readonly ?Offset  $offset       = null,
        private readonly ?string  $filterSql    = null,
        private readonly array    $filterValues = [],
    ) {
        $linkName     = 'receiver-' . bin2hex(random_bytes(4));
        $this->link   = new ReceiverLink($session, name: $linkName, source: $address, initialCredit: $credit);
        $this->parser = new FrameParser();
    }

    public function run(?\Closure $handler, ?\Closure $errorHandler = null): void
    {
        $this->link->attach();
        $transport = $this->session->transport();

        while ($transport->isConnected()) {
            $data = $transport->read(4096);
            if ($data === null) {
                break;
            }
            if ($data === '') {
                continue;
            }

            $this->parser->feed($data);

            foreach ($this->parser->readyFrames() as $frame) {
                if ($this->isTransferFrame($frame)) {
                    $this->handleTransfer($frame, $handler, $errorHandler);
                }
            }
        }

        // Attempt to send DETACH. If the transport is already closed (e.g. the caller
        // disconnected inside a handler to break the loop), ignore the error gracefully.
        try {
            $this->link->detach();
        } catch (\Throwable) {
            // Transport already closed — DETACH cannot be sent, which is acceptable.
        }
    }

    private function isTransferFrame(string $frame): bool
    {
        $body        = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();
        return is_array($performative) && ($performative['descriptor'] ?? null) === Descriptor::TRANSFER;
    }

    private function handleTransfer(string $frame, ?\Closure $handler, ?\Closure $errorHandler): void
    {
        $body        = FrameParser::extractBody($frame);
        $decoder     = new TypeDecoder($body);
        $performative = $decoder->decode();

        $deliveryId     = $performative['value'][1] ?? 0;

        // Remaining bytes after the TRANSFER performative are the message payload
        $messagePayload = substr($body, $decoder->offset());
        $message        = MessageDecoder::decode($messagePayload);

        $ctx = new DeliveryContext($deliveryId, $this->link);

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
