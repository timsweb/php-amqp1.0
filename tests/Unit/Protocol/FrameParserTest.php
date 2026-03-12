<?php
declare(strict_types=1);
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\FrameParser;
use PHPUnit\Framework\TestCase;

class FrameParserTest extends TestCase
{
    public function test_parse_single_complete_frame(): void
    {
        $frame  = FrameBuilder::heartbeat();
        $parser = new FrameParser();
        $parser->feed($frame);
        $frames = $parser->readyFrames();
        $this->assertCount(1, $frames);
        $this->assertSame($frame, $frames[0]);
    }

    public function test_parse_frame_with_body(): void
    {
        $body   = "\x00\x53\x10\x45";
        $frame  = FrameBuilder::amqp(channel: 0, body: $body);
        $parser = new FrameParser();
        $parser->feed($frame);
        $frames = $parser->readyFrames();
        $this->assertCount(1, $frames);
        $this->assertSame($frame, $frames[0]);
    }

    public function test_partial_header_produces_no_frames(): void
    {
        $frame  = FrameBuilder::heartbeat();
        $parser = new FrameParser();
        $parser->feed(substr($frame, 0, 4));
        $this->assertEmpty($parser->readyFrames());
    }

    public function test_partial_body_produces_no_frames(): void
    {
        $body   = str_repeat("\x40", 20);
        $frame  = FrameBuilder::amqp(channel: 0, body: $body);
        $parser = new FrameParser();
        $parser->feed(substr($frame, 0, 15));
        $this->assertEmpty($parser->readyFrames());
    }

    public function test_frame_split_across_two_feeds(): void
    {
        $frame  = FrameBuilder::amqp(channel: 0, body: "\x00\x53\x10\x45");
        $parser = new FrameParser();
        $parser->feed(substr($frame, 0, 6));
        $this->assertEmpty($parser->readyFrames());
        $parser->feed(substr($frame, 6));
        $frames = $parser->readyFrames();
        $this->assertCount(1, $frames);
        $this->assertSame($frame, $frames[0]);
    }

    public function test_two_frames_in_one_feed(): void
    {
        $frame1 = FrameBuilder::heartbeat();
        $frame2 = FrameBuilder::amqp(channel: 0, body: "\x40");
        $parser = new FrameParser();
        $parser->feed($frame1 . $frame2);
        $frames = $parser->readyFrames();
        $this->assertCount(2, $frames);
        $this->assertSame($frame1, $frames[0]);
        $this->assertSame($frame2, $frames[1]);
    }

    public function test_ready_frames_clears_queue(): void
    {
        $parser = new FrameParser();
        $parser->feed(FrameBuilder::heartbeat());
        $parser->readyFrames();
        $this->assertEmpty($parser->readyFrames());
    }

    public function test_extract_body_from_frame(): void
    {
        $body   = "\x00\x53\x10\x45";
        $frame  = FrameBuilder::amqp(channel: 0, body: $body);
        $parser = new FrameParser();
        $parser->feed($frame);
        $frames = $parser->readyFrames();
        $this->assertSame($body, FrameParser::extractBody($frames[0]));
    }

    public function test_extract_channel_from_frame(): void
    {
        $frame  = FrameBuilder::amqp(channel: 7, body: '');
        $parser = new FrameParser();
        $parser->feed($frame);
        $frames = $parser->readyFrames();
        $this->assertSame(7, FrameParser::extractChannel($frames[0]));
    }

    public function test_extract_type_from_frame(): void
    {
        $amqpFrame = FrameBuilder::amqp(channel: 0, body: '');
        $saslFrame = FrameBuilder::sasl(body: "\x40");
        $parser = new FrameParser();
        $parser->feed($amqpFrame);
        $parser->feed($saslFrame);
        $frames = $parser->readyFrames();
        $this->assertSame(0x00, FrameParser::extractType($frames[0]));
        $this->assertSame(0x01, FrameParser::extractType($frames[1]));
    }
}
