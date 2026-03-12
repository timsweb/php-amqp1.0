<?php
declare(strict_types=1);
namespace AMQP10\Connection;

use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Transport\TransportInterface;

class Session
{
    private bool $open           = false;
    private int  $nextOutgoingId = 0;
    private int  $nextLinkHandle = 0;

    private FrameParser $frameParser;
    private array $pendingFrames = [];

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $channel,
        private readonly int $incomingWindow = 2048,
        private readonly int $outgoingWindow = 2048,
    ) {
        $this->frameParser = new FrameParser();
    }

    public function begin(): void
    {
        $this->transport->send(PerformativeEncoder::begin(
            channel:        $this->channel,
            nextOutgoingId: $this->nextOutgoingId,
            incomingWindow: $this->incomingWindow,
            outgoingWindow: $this->outgoingWindow,
        ));
        $this->readFrameOfType(Descriptor::BEGIN);
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

    public function readFrameOfType(int $descriptor): string
    {
        while (true) {
            foreach ($this->pendingFrames as $i => $frame) {
                $body         = FrameParser::extractBody($frame);
                $performative = (new TypeDecoder($body))->decode();
                if (is_array($performative) && ($performative['descriptor'] ?? null) === $descriptor) {
                    unset($this->pendingFrames[$i]);
                    $this->pendingFrames = array_values($this->pendingFrames);
                    return $frame;
                }
            }

            $data = $this->transport->read(4096);
            if ($data === null || (!$this->transport->isConnected() && $data === '')) {
                throw new \RuntimeException(
                    'Transport closed while awaiting frame with descriptor 0x' . dechex($descriptor)
                );
            }
            if ($data === '') {
                continue;
            }
            $this->frameParser->feed($data);
            foreach ($this->frameParser->readyFrames() as $frame) {
                $this->pendingFrames[] = $frame;
            }
        }
    }

    public function nextFrame(): ?string
    {
        if (!empty($this->pendingFrames)) {
            return array_shift($this->pendingFrames);
        }

        if (!$this->transport->isConnected()) {
            return null;
        }

        $data = $this->transport->read(4096);
        if ($data === null || $data === '') {
            return null;
        }

        $this->frameParser->feed($data);
        $frames = $this->frameParser->readyFrames();
        if (empty($frames)) {
            return null;
        }

        $first               = array_shift($frames);
        $this->pendingFrames = array_merge($this->pendingFrames, $frames);
        return $first;
    }
}
