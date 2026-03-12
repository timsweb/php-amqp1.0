<?php
declare(strict_types=1);
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class SenderLinkTest extends TestCase
{
    private function makeSession(): array
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();
        return [$mock, $session];
    }

    public function test_attach_sends_attach_frame_with_sender_role(): void
    {
        [$mock, $session] = $this->makeSession();
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'my-sender', handle: 0,
            role: PerformativeEncoder::ROLE_RECEIVER,
            source: null, target: '/exchanges/test/key',
        ));
        $link = new SenderLink($session, name: 'my-sender', target: '/exchanges/test/key');
        $link->attach();
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertNotEmpty($frames);
        $performative = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::ATTACH, $performative['descriptor']);
        $this->assertFalse($performative['value'][2]); // role=false means sender
    }

    public function test_detach_sends_detach_frame(): void
    {
        [$mock, $session] = $this->makeSession();
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'l', handle: 0,
            role: PerformativeEncoder::ROLE_RECEIVER,
            source: null, target: '/q/test',
        ));
        $link = new SenderLink($session, name: 'l', target: '/q/test');
        $link->attach();
        $mock->clearSent();
        $link->detach();
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertNotEmpty($frames);
        $performative = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::DETACH, $performative['descriptor']);
    }

    public function test_attach_throws_when_server_does_not_respond(): void
    {
        $mock    = new TransportMock();
        $session = new Session($mock, channel: 0);
        $link    = new SenderLink($session, name: 'l', target: '/queues/test');

        $this->expectException(\RuntimeException::class);
        $link->attach();
    }
}
