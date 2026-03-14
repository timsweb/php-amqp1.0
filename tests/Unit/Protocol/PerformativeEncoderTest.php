<?php

declare(strict_types=1);

namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use PHPUnit\Framework\TestCase;

class PerformativeEncoderTest extends TestCase
{
    private function decodePerformative(string $frame): array
    {
        $body = FrameParser::extractBody($frame);
        $decoder = new TypeDecoder($body);

        return $decoder->decode();
    }

    private function assertAmqpFrame(string $frame, int $expectedDescriptor, int $channel = 0): void
    {
        $this->assertSame(0x00, FrameParser::extractType($frame), 'Must be AMQP frame (TYPE=0x00)');
        $this->assertSame($channel, FrameParser::extractChannel($frame));
        $size = unpack('N', substr($frame, 0, 4))[1];
        $this->assertSame(strlen($frame), $size, 'SIZE must equal total frame length');
        $performative = $this->decodePerformative($frame);
        $this->assertSame($expectedDescriptor, $performative['descriptor']);
    }

    private function assertSaslFrame(string $frame, int $expectedDescriptor): void
    {
        $this->assertSame(0x01, FrameParser::extractType($frame), 'Must be SASL frame (TYPE=0x01)');
        $size = unpack('N', substr($frame, 0, 4))[1];
        $this->assertSame(strlen($frame), $size);
        $performative = $this->decodePerformative($frame);
        $this->assertSame($expectedDescriptor, $performative['descriptor']);
    }

    public function test_encode_open_is_amqp_frame_with_open_descriptor(): void
    {
        $frame = PerformativeEncoder::open(containerId: 'client-1', hostname: 'localhost');
        $this->assertAmqpFrame($frame, Descriptor::OPEN);
    }

    public function test_open_contains_container_id(): void
    {
        $frame = PerformativeEncoder::open(containerId: 'my-client');
        $performative = $this->decodePerformative($frame);
        $this->assertSame('my-client', $performative['value'][0]);
    }

    public function test_open_contains_hostname(): void
    {
        $frame = PerformativeEncoder::open(containerId: 'c', hostname: 'vhost:tenant-1');
        $performative = $this->decodePerformative($frame);
        $this->assertSame('vhost:tenant-1', $performative['value'][1]);
    }

    public function test_encode_begin_is_amqp_frame_with_begin_descriptor(): void
    {
        $frame = PerformativeEncoder::begin(channel: 0);
        $this->assertAmqpFrame($frame, Descriptor::BEGIN, channel: 0);
    }

    public function test_begin_fields_are_present(): void
    {
        $frame = PerformativeEncoder::begin(channel: 0, nextOutgoingId: 0, incomingWindow: 256, outgoingWindow: 256);
        $performative = $this->decodePerformative($frame);
        $fields = $performative['value'];
        $this->assertNull($fields[0]);
        $this->assertSame(0, $fields[1]);
        $this->assertSame(256, $fields[2]);
        $this->assertSame(256, $fields[3]);
    }

    public function test_encode_attach_is_amqp_frame_with_attach_descriptor(): void
    {
        $frame = PerformativeEncoder::attach(
            channel: 0,
            name: 'my-link',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            target: '/exchanges/my-exchange/key',
        );
        $this->assertAmqpFrame($frame, Descriptor::ATTACH);
    }

    public function test_attach_role_sender_is_false(): void
    {
        $frame = PerformativeEncoder::attach(channel: 0, name: 'l', handle: 0, role: PerformativeEncoder::ROLE_SENDER, target: '/q/test');
        $performative = $this->decodePerformative($frame);
        $this->assertFalse($performative['value'][2]);
    }

    public function test_attach_role_receiver_is_true(): void
    {
        $frame = PerformativeEncoder::attach(channel: 0, name: 'l', handle: 0, role: PerformativeEncoder::ROLE_RECEIVER, source: '/q/test');
        $performative = $this->decodePerformative($frame);
        $this->assertTrue($performative['value'][2]);
    }

    public function test_encode_close_is_amqp_frame_with_close_descriptor(): void
    {
        $frame = PerformativeEncoder::close(channel: 0);
        $this->assertAmqpFrame($frame, Descriptor::CLOSE);
    }

    public function test_encode_sasl_mechanisms_is_sasl_frame(): void
    {
        $frame = PerformativeEncoder::saslMechanisms(['PLAIN']);
        $this->assertSaslFrame($frame, Descriptor::SASL_MECHANISMS);
    }

    public function test_sasl_mechanisms_contains_mechanism_list(): void
    {
        $frame = PerformativeEncoder::saslMechanisms(['PLAIN', 'EXTERNAL']);
        $performative = $this->decodePerformative($frame);
        $mechanisms = $performative['value'][0];
        $this->assertContains('PLAIN', $mechanisms);
        $this->assertContains('EXTERNAL', $mechanisms);
    }

    public function test_encode_sasl_init_is_sasl_frame(): void
    {
        $frame = PerformativeEncoder::saslInit(mechanism: 'PLAIN', initialResponse: "\x00guest\x00guest");
        $this->assertSaslFrame($frame, Descriptor::SASL_INIT);
    }

    public function test_sasl_init_fields(): void
    {
        $response = "\x00user\x00pass";
        $frame = PerformativeEncoder::saslInit('PLAIN', $response);
        $performative = $this->decodePerformative($frame);
        $this->assertSame('PLAIN', $performative['value'][0]);
        $this->assertSame($response, $performative['value'][1]);
    }
}
