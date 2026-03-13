<?php
declare(strict_types=1);
namespace AMQP10\Messaging;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\MessageTimeoutException;
use AMQP10\Exception\PublishException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

class Publisher
{
    private readonly SenderLink $link;

    public function __construct(
        private readonly Session $session,
        string                  $address,
        private readonly float   $timeout = 30.0,
    ) {
        $linkName   = 'sender-' . bin2hex(random_bytes(4));
        $this->link = new SenderLink($session, name: $linkName, target: $address);
        $this->link->attach();
    }

    public function send(Message $message): Outcome
    {
        $payload    = MessageEncoder::encode($message);
        $deliveryId = $this->link->transfer($payload);
        if ($this->link->isPreSettled()) {
            return Outcome::accepted();
        }
        return $this->awaitOutcome($deliveryId);
    }

    public function close(): void
    {
        $this->link->detach();
    }

    private function awaitOutcome(int $deliveryId): Outcome
    {
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            if (microtime(true) >= $deadline) {
                throw new MessageTimeoutException(
                    "Timeout after {$this->timeout}s awaiting disposition for delivery $deliveryId"
                );
            }
            $frame = $this->session->nextFrame();
            if ($frame === null) {
                if (!$this->session->transport()->isConnected()) {
                    throw new PublishException('Connection closed while awaiting outcome');
                }
                usleep(1000);
                continue;
            }

            $body         = FrameParser::extractBody($frame);
            $performative = (new TypeDecoder($body))->decode();

            if (!is_array($performative)) {
                continue;
            }
            if (($performative['descriptor'] ?? null) !== Descriptor::DISPOSITION) {
                continue;
            }

            $fields = $performative['value'];
            $first  = $fields[1] ?? null;
            if ($first !== $deliveryId) {
                continue;
            }

            $state = $fields[4] ?? null;
            return $this->decodeOutcome($state);
        }
    }

    private function decodeOutcome(mixed $state): Outcome
    {
        if (!is_array($state)) {
            return Outcome::accepted();
        }
        return match ($state['descriptor'] ?? null) {
            Descriptor::ACCEPTED => Outcome::accepted(),
            Descriptor::REJECTED => Outcome::rejected(),
            Descriptor::RELEASED => Outcome::released(),
            Descriptor::MODIFIED => Outcome::modified(),
            default              => Outcome::released(),
        };
    }
}
