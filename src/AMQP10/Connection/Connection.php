<?php
declare(strict_types=1);
namespace AMQP10\Connection;

use AMQP10\Exception\AuthenticationException;
use AMQP10\Exception\ConnectionFailedException;
use AMQP10\Exception\MessageTimeoutException;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Transport\TransportInterface;

class Connection
{
    private bool $open = false;
    private int  $negotiatedMaxFrameSize = 65536;
    private readonly string $containerId;

    /**
     * Unified byte buffer. All bytes from transport land here first.
     * Both protocol headers and frame bytes are extracted from this buffer.
     * We parse frames directly from this buffer to avoid feeding protocol
     * header bytes into FrameParser.
     */
    private string $buffer = '';

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $uri,
        private readonly ?Sasl $sasl = null,
        private readonly float $timeout = 30.0,
    ) {
        $this->containerId = 'php-amqp10-' . bin2hex(random_bytes(4));
    }

    public function open(): void
    {
        $parts = parse_url($this->uri);
        $this->transport->connect($this->uri);

        $sasl = $this->sasl ?? Sasl::plain(
            $parts['user'] ?? 'guest',
            $parts['pass'] ?? 'guest',
        );

        $this->saslHandshake($sasl);
        $this->amqpOpen($parts['host'] ?? 'localhost');
        $this->open = true;
    }

    public function close(): void
    {
        if ($this->open) {
            $this->transport->send(PerformativeEncoder::close(channel: 0));
            $this->transport->disconnect();
            $this->open = false;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function negotiatedMaxFrameSize(): int
    {
        return $this->negotiatedMaxFrameSize;
    }

    private function saslHandshake(Sasl $sasl): void
    {
        $this->transport->send(FrameBuilder::saslProtocolHeader());
        $this->expectProtocolHeader(FrameBuilder::saslProtocolHeader());

        $this->readNextFrame(); // sasl-mechanisms (we trust server's list)

        $this->transport->send(
            PerformativeEncoder::saslInit($sasl->mechanism(), $sasl->initialResponse())
        );

        $outcomeFrame = $this->readNextFrame();
        $body         = FrameParser::extractBody($outcomeFrame);
        $performative = (new TypeDecoder($body))->decode();

        if ($performative['descriptor'] !== Descriptor::SASL_OUTCOME) {
            throw new ConnectionFailedException('Expected sasl-outcome');
        }

        $code = $performative['value'][0];
        if ($code !== 0) {
            throw new AuthenticationException("SASL authentication failed (code $code)");
        }
    }

    private function amqpOpen(string $hostname): void
    {
        $this->transport->send(FrameBuilder::amqpProtocolHeader());
        $this->expectProtocolHeader(FrameBuilder::amqpProtocolHeader());

        $this->transport->send(PerformativeEncoder::open(
            containerId: $this->containerId,
            hostname:    $hostname,
        ));

        $openFrame = $this->readNextFrame();
        $body      = FrameParser::extractBody($openFrame);
        $open      = (new TypeDecoder($body))->decode();

        if (isset($open['value'][2]) && $open['value'][2] > 0) {
            $this->negotiatedMaxFrameSize = min($this->negotiatedMaxFrameSize, $open['value'][2]);
        }
    }

    /**
     * Read exactly 8 bytes and verify they match the expected protocol header.
     */
    private function expectProtocolHeader(string $expected): void
    {
        $this->fillBuffer(8);
        $header       = substr($this->buffer, 0, 8);
        $this->buffer = substr($this->buffer, 8);

        if ($header !== $expected) {
            throw new ConnectionFailedException(
                sprintf('Unexpected protocol header: %s', bin2hex($header))
            );
        }
    }

    /**
     * Read the next complete AMQP/SASL frame from the buffer.
     * Frames are length-prefixed (4-byte big-endian size at start).
     */
    private function readNextFrame(): string
    {
        // Need at least 4 bytes for the size field
        $this->fillBuffer(4);
        $size = unpack('N', substr($this->buffer, 0, 4))[1];

        // Now read the full frame
        $this->fillBuffer($size);
        $frame        = substr($this->buffer, 0, $size);
        $this->buffer = substr($this->buffer, $size);
        return $frame;
    }

    /**
     * Ensure at least $needed bytes are in $this->buffer.
     */
    private function fillBuffer(int $needed): void
    {
        $deadline = microtime(true) + $this->timeout;
        while (strlen($this->buffer) < $needed) {
            if (microtime(true) >= $deadline) {
                throw new MessageTimeoutException(
                    "Timeout after {$this->timeout}s waiting for data from broker"
                );
            }
            $chunk = $this->transport->read(4096);
            if ($chunk === null) {
                throw new ConnectionFailedException('Connection closed by peer');
            }
            if ($chunk !== '') {
                $this->buffer .= $chunk;
            }
        }
    }
}
