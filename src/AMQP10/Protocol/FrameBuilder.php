<?php
declare(strict_types=1);
namespace AMQP10\Protocol;

/**
 * Builds AMQP 1.0 frames.
 *
 * Frame header layout (spec §2.3):
 *   Bytes 0-3: SIZE  (uint32)  — total size including header
 *   Byte  4:   DOFF  (uint8)   — header size in 4-byte words (min 2 = 8 bytes)
 *   Byte  5:   TYPE  (uint8)   — 0x00=AMQP, 0x01=SASL
 *   Bytes 6-7: CHANNEL (uint16) — 0 for SASL/connection frames
 *   Bytes 8+:  Frame body (starts at DOFF*4)
 */
class FrameBuilder
{
    private const DOFF = 2;
    private const TYPE_AMQP = 0x00;
    private const TYPE_SASL = 0x01;

    /** An empty AMQP frame with no body — used as a heartbeat. */
    public static function heartbeat(): string
    {
        return self::buildHeader(size: 8, type: self::TYPE_AMQP, channel: 0);
    }

    /**
     * Build an AMQP performative frame (TYPE=0x00).
     * @param int    $channel Session channel number (0 for connection-level)
     * @param string $body    Pre-encoded performative body
     */
    public static function amqp(int $channel, string $body): string
    {
        $size = 8 + strlen($body);
        return self::buildHeader(size: $size, type: self::TYPE_AMQP, channel: $channel) . $body;
    }

    /**
     * Build a SASL frame (TYPE=0x01). Channel bytes are present but ignored.
     * @param string $body Pre-encoded SASL performative body
     */
    public static function sasl(string $body): string
    {
        $size = 8 + strlen($body);
        return self::buildHeader(size: $size, type: self::TYPE_SASL, channel: 0) . $body;
    }

    /** 8-byte SASL protocol header: "AMQP" + protocol-id=3 + 1.0.0 */
    public static function saslProtocolHeader(): string
    {
        return "AMQP\x03\x01\x00\x00";
    }

    /** 8-byte AMQP protocol header: "AMQP" + protocol-id=0 + 1.0.0 */
    public static function amqpProtocolHeader(): string
    {
        return "AMQP\x00\x01\x00\x00";
    }

    private static function buildHeader(int $size, int $type, int $channel): string
    {
        return pack('N', $size)
             . pack('C', self::DOFF)
             . pack('C', $type)
             . pack('n', $channel);
    }
}
