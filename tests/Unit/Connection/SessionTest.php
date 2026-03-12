<?php
declare(strict_types=1);
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    private function makeOpenSession(): array
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        return [$mock, $session];
    }

    public function test_begin_sends_begin_frame(): void
    {
        [$mock, $session] = $this->makeOpenSession();
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertNotEmpty($frames);
        $performative = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::BEGIN, $performative['descriptor']);
    }

    public function test_begin_sends_on_correct_channel(): void
    {
        [$mock, $session] = $this->makeOpenSession();
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertSame(0, FrameParser::extractChannel($frames[0]));
    }

    public function test_is_open_after_begin(): void
    {
        [, $session] = $this->makeOpenSession();
        $this->assertTrue($session->isOpen());
    }

    public function test_end_sends_end_frame(): void
    {
        [$mock, $session] = $this->makeOpenSession();
        $mock->clearSent();
        $session->end();
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertNotEmpty($frames);
        $performative = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::END, $performative['descriptor']);
    }

    public function test_begin_awaits_server_begin_response(): void
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);

        $session->begin();

        $this->assertTrue($session->isOpen());
    }

    public function test_begin_throws_when_transport_closes_before_response(): void
    {
        $mock    = new TransportMock();
        $session = new Session($mock, channel: 0);

        $this->expectException(\RuntimeException::class);
        $session->begin();
    }

    public function test_readFrameOfType_buffers_non_matching_frames(): void
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::open('container-1'));
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);

        $session->begin();
        $this->assertTrue($session->isOpen());

        $frame = $session->nextFrame();
        $this->assertNotNull($frame);
        $body        = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();
        $this->assertSame(Descriptor::OPEN, $performative['descriptor']);
    }

    public function test_nextFrame_returns_null_when_no_more_data(): void
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();

        $result = $session->nextFrame();
        $this->assertNull($result);
    }

    public function test_readFrameOfType_uses_cached_descriptor(): void
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();

        // Queue multiple frames of different types
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'sender', handle: 0,
            role: PerformativeEncoder::ROLE_RECEIVER, source: null, target: '/q/test',
        ));
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 1));
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'receiver', handle: 1,
            role: PerformativeEncoder::ROLE_SENDER, source: '/q/test2', target: null,
        ));

        // Read a specific frame type - should use cached descriptor
        $frame = $session->readFrameOfType(Descriptor::BEGIN);

        // Verify we got the right frame
        $this->assertNotNull($frame);
        $body = FrameParser::extractBody($frame);
        $performative = (new TypeDecoder($body))->decode();
        $this->assertSame(Descriptor::BEGIN, $performative['descriptor']);

        // Read an ATTACH frame - should find it via cached descriptor
        $attachFrame = $session->readFrameOfType(Descriptor::ATTACH);
        $this->assertNotNull($attachFrame);
        $body = FrameParser::extractBody($attachFrame);
        $performative = (new TypeDecoder($body))->decode();
        $this->assertSame(Descriptor::ATTACH, $performative['descriptor']);
    }

    public function test_pendingFrames_handles_null_descriptor(): void
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();

        // Manually queue a frame that will have null descriptor (malformed)
        $malformedFrame = FrameBuilder::amqp(channel: 0, body: "\x00\x00\x00\x00");
        $mock->queueIncoming($malformedFrame);

        // Read via nextFrame - should handle null descriptor gracefully
        $frame = $session->nextFrame();
        $this->assertNotNull($frame);
    }
}
