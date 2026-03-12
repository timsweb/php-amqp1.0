<?php
declare(strict_types=1);
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\FrameBuilder;
use PHPUnit\Framework\TestCase;

class FrameBuilderTest extends TestCase
{
    private function assertValidFrameHeader(string $frame): void
    {
        $this->assertGreaterThanOrEqual(8, strlen($frame), 'Frame must be at least 8 bytes');
        $size    = unpack('N', substr($frame, 0, 4))[1];
        $doff    = ord($frame[4]);
        $type    = ord($frame[5]);
        $channel = unpack('n', substr($frame, 6, 2))[1];
        $this->assertSame(strlen($frame), $size, 'SIZE field must equal total frame length');
        $this->assertGreaterThanOrEqual(2, $doff, 'DOFF must be >= 2 (8-byte minimum header)');
        $this->assertContains($type, [0x00, 0x01], 'TYPE must be 0x00 (AMQP) or 0x01 (SASL)');
    }

    public function test_heartbeat_is_eight_bytes(): void
    {
        $frame = FrameBuilder::heartbeat();
        $this->assertSame(8, strlen($frame));
    }

    public function test_heartbeat_has_correct_header_bytes(): void
    {
        $frame = FrameBuilder::heartbeat();
        $this->assertSame("\x00\x00\x00\x08\x02\x00\x00\x00", $frame);
    }

    public function test_heartbeat_passes_invariants(): void
    {
        $this->assertValidFrameHeader(FrameBuilder::heartbeat());
    }

    public function test_amqp_frame_with_body(): void
    {
        $body  = "\x00\x53\x10\x45";
        $frame = FrameBuilder::amqp(channel: 0, body: $body);
        $this->assertValidFrameHeader($frame);
        $this->assertSame(0x00, ord($frame[5]), 'TYPE must be 0x00 for AMQP frame');
        $this->assertSame(8 + strlen($body), strlen($frame));
        $this->assertSame($body, substr($frame, 8));
    }

    public function test_amqp_frame_channel_is_encoded(): void
    {
        $frame   = FrameBuilder::amqp(channel: 3, body: '');
        $channel = unpack('n', substr($frame, 6, 2))[1];
        $this->assertSame(3, $channel);
    }

    public function test_amqp_frame_size_reflects_body_length(): void
    {
        $body  = str_repeat("\x40", 100);
        $frame = FrameBuilder::amqp(channel: 0, body: $body);
        $size  = unpack('N', substr($frame, 0, 4))[1];
        $this->assertSame(108, $size); // 8 header + 100 body
    }

    public function test_sasl_frame_type_is_0x01(): void
    {
        $body  = "\x00\x53\x40\x45";
        $frame = FrameBuilder::sasl(body: $body);
        $this->assertValidFrameHeader($frame);
        $this->assertSame(0x01, ord($frame[5]), 'TYPE must be 0x01 for SASL frame');
        $this->assertSame(0, unpack('n', substr($frame, 6, 2))[1], 'SASL channel bytes are zero');
    }

    public function test_sasl_protocol_header(): void
    {
        $this->assertSame("AMQP\x03\x01\x00\x00", FrameBuilder::saslProtocolHeader());
    }

    public function test_amqp_protocol_header(): void
    {
        $this->assertSame("AMQP\x00\x01\x00\x00", FrameBuilder::amqpProtocolHeader());
    }

    public function test_protocol_headers_are_8_bytes(): void
    {
        $this->assertSame(8, strlen(FrameBuilder::saslProtocolHeader()));
        $this->assertSame(8, strlen(FrameBuilder::amqpProtocolHeader()));
    }
}
