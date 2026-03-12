<?php
namespace AMQP10\Messaging;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Exception\PublishException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;

class Publisher
{
    private readonly SenderLink $link;
    private readonly FrameParser $parser;

    public function __construct(
        private readonly Session $session,
        private readonly string  $address,
    ) {
        $linkName     = 'sender-' . bin2hex(random_bytes(4));
        $this->link   = new SenderLink($session, name: $linkName, target: $address);
        $this->parser = new FrameParser();
        $this->link->attach();
    }

    public function send(Message $message): Outcome
    {
        $payload    = MessageEncoder::encode($message);
        $deliveryId = $this->link->transfer($payload);
        if ($this->link->isPreSettled()) {
            return Outcome::accepted(); // fire-and-forget: broker sends no DISPOSITION
        }
        return $this->awaitOutcome($deliveryId);
    }

    public function close(): void
    {
        $this->link->detach();
    }

    private function awaitOutcome(int $deliveryId): Outcome
    {
        $transport = $this->session->transport();

        while (true) {
            $data = $transport->read(4096);
            if ($data === null) {
                throw new PublishException('Connection closed while awaiting outcome');
            }
            if ($data !== '') {
                $this->parser->feed($data);
            }

            foreach ($this->parser->readyFrames() as $frame) {
                $body        = FrameParser::extractBody($frame);
                $performative = (new TypeDecoder($body))->decode();

                if (!is_array($performative)) {
                    continue;
                }
                if (($performative['descriptor'] ?? null) !== Descriptor::DISPOSITION) {
                    continue;
                }

                $fields = $performative['value'];
                // Disposition list fields: [0]=role, [1]=first, [2]=last, [3]=settled, [4]=state
                $first = $fields[1] ?? null;
                if ($first !== $deliveryId) {
                    continue;
                }

                $state = $fields[4] ?? null;
                return $this->decodeOutcome($state);
            }
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
