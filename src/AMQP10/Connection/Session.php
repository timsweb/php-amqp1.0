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
    /** @var array{raw: string, descriptor: ?int}[] */
    private array $pendingFrames = [];

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $channel,
        private readonly int $incomingWindow = 2048,
        private readonly int $outgoingWindow = 2048,
        private readonly float $timeout = 30.0,
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

    /**
     * Note: Does NOT await server END response, unlike begin() which awaits BEGIN.
     * This asymmetry exists because closing a session is best-effort in common patterns.
     */
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

    /**
     * @param int      $descriptor           The performative descriptor to wait for.
     * @param int|null $rejectOnDetachHandle  If set, throw immediately if a DETACH arrives for this link handle.
     *                                        Used by SenderLink/ReceiverLink to detect link rejection without
     *                                        confusing it with stale DETACH responses from previously closed links.
     */
    public function readFrameOfType(int $descriptor, ?int $rejectOnDetachHandle = null): string
    {
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            foreach ($this->pendingFrames as $i => $frame) {
                if ($frame['descriptor'] === $descriptor) {
                    unset($this->pendingFrames[$i]);
                    $this->pendingFrames = array_values($this->pendingFrames);
                    return $frame['raw'];
                }
                if ($rejectOnDetachHandle !== null
                    && $frame['descriptor'] === Descriptor::DETACH
                    && $this->extractDetachHandle($frame['raw']) === $rejectOnDetachHandle
                ) {
                    unset($this->pendingFrames[$i]);
                    $this->pendingFrames = array_values($this->pendingFrames);
                    throw new \RuntimeException(
                        'AMQP link rejected by server (handle ' . $rejectOnDetachHandle . ')'
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new \RuntimeException(
                    'Timeout awaiting frame with descriptor 0x' . dechex($descriptor)
                );
            }

            $data = $this->transport->read(4096);
            if ($data === null || (!$this->transport->isConnected() && $data === '')) {
                throw new \RuntimeException(
                    'Transport closed while awaiting frame with descriptor 0x' . dechex($descriptor)
                );
            }
            if ($data === '') {
                usleep(1000);
                continue;
            }
            $this->frameParser->feed($data);
            foreach ($this->frameParser->readyFrames() as $frame) {
                $body = FrameParser::extractBody($frame);
                try {
                    $performative = (new TypeDecoder($body))->decode();
                    $frameDescriptor = is_array($performative) ? ($performative['descriptor'] ?? null) : null;
                } catch (\AMQP10\Exception\FrameException $e) {
                    $frameDescriptor = null;
                }
                $this->pendingFrames[] = ['raw' => $frame, 'descriptor' => $frameDescriptor];
            }
        }
    }

    /**
     * Extract the link handle from a DETACH frame body.
     * Returns null if the frame cannot be parsed.
     */
    private function extractDetachHandle(string $frame): ?int
    {
        try {
            $body = FrameParser::extractBody($frame);
            $performative = (new TypeDecoder($body))->decode();
            $handle = $performative['value'][0] ?? null;
            return is_int($handle) ? $handle : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns the next available frame, or null when no frame is immediately available
     * OR when the transport is closed. Callers must check transport()->isConnected() to
     * distinguish between "momentarily quiet" and "genuinely closed".
     */
    public function nextFrame(): ?string
    {
        if (!empty($this->pendingFrames)) {
            // Note: array_shift() is O(n) but this is acceptable tradeoff
            // to eliminate O(n²) decoding behavior overall
            $frame = array_shift($this->pendingFrames);
            return $frame['raw'];
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

        // Note: Original code used array_merge() at line 125, but we use foreach
        // to decode and cache descriptors for each frame. This is necessary
        // because we need to access each frame to decode its descriptor.
        // Performance: O(n) decoding, but done once instead of O(n²)
        foreach ($frames as $frame) {
            $body = FrameParser::extractBody($frame);
            try {
                $performative = (new TypeDecoder($body))->decode();
                $frameDescriptor = is_array($performative) ? ($performative['descriptor'] ?? null) : null;
            } catch (\AMQP10\Exception\FrameException $e) {
                $frameDescriptor = null;
            }
            $this->pendingFrames[] = ['raw' => $frame, 'descriptor' => $frameDescriptor];
        }

        $first = array_shift($this->pendingFrames);
        return $first['raw'];
    }
}
