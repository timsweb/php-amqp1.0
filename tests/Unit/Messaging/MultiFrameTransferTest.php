<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Connection\SenderLink;
use AMQP10\Connection\Session;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class MultiFrameTransferTest extends TestCase
{
    public function test_large_payload_split_into_multiple_frames(): void
    {
        $frames = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('isConnected')->willReturn(true);
        $transport->expects($this->atLeastOnce())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$frames) {
                $frames[] = $data;
            });

        $session = $this->createMock(Session::class);
        $session->method('channel')->willReturn(0);
        $session->method('nextDeliveryId')->willReturn(1);
        $session->method('transport')->willReturn($transport);
        $session->method('incomingWindow')->willReturn(2048);
        $session->method('outgoingWindow')->willReturn(2048);

        $link = new SenderLink($session, name: 'test', target: '/queues/q', maxFrameSize: 512);

        // Inject as attached via reflection
        $ref = new ReflectionProperty(SenderLink::class, 'attached');
        $ref->setValue($link, true);

        $payload = str_repeat('X', 600); // bigger than 512 byte frame limit
        $link->transfer($payload);

        // Should have produced 2+ frames
        $this->assertGreaterThanOrEqual(2, count($frames));

        // First frame should have more=true
        $body1 = FrameParser::extractBody($frames[0]);
        $perf1 = (new TypeDecoder($body1))->decode();
        $this->assertTrue($perf1['value'][5]); // more = true at index 5

        // Last frame should have more=false
        $bodyLast = FrameParser::extractBody($frames[count($frames) - 1]);
        $perfLast = (new TypeDecoder($bodyLast))->decode();
        $this->assertFalse($perfLast['value'][5]); // more = false
    }
}
