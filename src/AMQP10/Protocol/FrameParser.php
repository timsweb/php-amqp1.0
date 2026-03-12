<?php
declare(strict_types=1);
namespace AMQP10\Protocol;

/**
 * Parses AMQP 1.0 frames from a raw byte stream.
 *
 * Usage:
 *   $parser = new FrameParser();
 *   $parser->feed($bytesFromSocket);
 *   foreach ($parser->readyFrames() as $frame) {
 *       $type    = FrameParser::extractType($frame);
 *       $channel = FrameParser::extractChannel($frame);
 *       $body    = FrameParser::extractBody($frame);
 *   }
 */
class FrameParser
{
    private string $buffer = '';
    /** @var string[] */
    private array $ready = [];

    /** Feed raw bytes from the transport into the parser. */
    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
        $this->parse();
    }

    /**
     * Return all complete frames available since last call.
     * Clears the internal queue.
     * @return string[]
     */
    public function readyFrames(): array
    {
        $frames      = $this->ready;
        $this->ready = [];
        return $frames;
    }

    /** Extract the TYPE byte (0x00 AMQP, 0x01 SASL) */
    public static function extractType(string $frame): int
    {
        return ord($frame[5]);
    }

    /** Extract the CHANNEL number (uint16) */
    public static function extractChannel(string $frame): int
    {
        return unpack('n', substr($frame, 6, 2))[1];
    }

    /**
     * Extract the frame body (everything after the fixed header).
     * Body starts at byte DOFF*4 (DOFF is at byte 4).
     */
    public static function extractBody(string $frame): string
    {
        $doff = ord($frame[4]);
        return substr($frame, $doff * 4);
    }

    private function parse(): void
    {
        while (strlen($this->buffer) >= 4) {
            $size = unpack('N', substr($this->buffer, 0, 4))[1];
            if (strlen($this->buffer) < $size) {
                break; // incomplete frame — wait for more data
            }
            $this->ready[] = substr($this->buffer, 0, $size);
            $this->buffer  = substr($this->buffer, $size);
        }
    }
}
