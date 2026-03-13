<?php
declare(strict_types=1);
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Protocol\TypeEncoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ReceiverLinkTest extends TestCase
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

    public function test_attach_sends_attach_with_receiver_role(): void
    {
        [$mock, $session] = $this->makeSession();
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));
        $link = new ReceiverLink($session, name: 'recv', source: '/queues/test');
        $link->attach();

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $this->assertNotEmpty($frames);
        $perf = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::ATTACH, $perf['descriptor']);
        $this->assertTrue($perf['value'][2]);
    }

    public function test_attach_with_filter_map_includes_filter_in_sent_frame(): void
    {
        [$mock, $session] = $this->makeSession();
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/streams/mystream', target: null,
        ));

        $filterMap = TypeEncoder::encodeMap([
            TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec') =>
                TypeEncoder::encodeDescribed(
                    TypeEncoder::encodeSymbol('rabbitmq:stream-offset-spec'),
                    TypeEncoder::encodeSymbol('first'),
                ),
        ]);

        $link = new ReceiverLink(
            $session,
            name:      'recv',
            source:    '/streams/mystream',
            filterMap: $filterMap,
        );
        $link->attach();

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();
        $this->assertNotEmpty($frames);

        $perf = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::ATTACH, $perf['descriptor']);

        $sourceEncoded = $perf['value'][5] ?? null;
        $this->assertNotNull($sourceEncoded, 'Source terminus must be present');
        $this->assertSame(0x28, $sourceEncoded['descriptor'] ?? null);
        $filterField = $sourceEncoded['value'][7] ?? null;
        $this->assertNotNull($filterField, 'Filter map must be present in source terminus field 7');
    }

    public function test_attach_throws_when_server_does_not_respond(): void
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0, timeout: 0.05);
        $session->begin();
        $link = new ReceiverLink($session, name: 'recv', source: '/queues/test');

        $this->expectException(\RuntimeException::class);
        $link->attach();
    }
}
