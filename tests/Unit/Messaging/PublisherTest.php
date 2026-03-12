<?php
declare(strict_types=1);
namespace AMQP10\Tests\Messaging;

use AMQP10\Connection\Session;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Publisher;
use AMQP10\Messaging\PublisherBuilder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class PublisherTest extends TestCase
{
    private function makeSession(): array
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test'); // required so read() returns queued data
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();
        return [$mock, $session];
    }

    public function test_send_transmits_transfer_frame_and_returns_accepted(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name:    'sender-link',
            handle:  0,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  null,
            target:  '/queues/test',
        ));
        $mock->queueIncoming(PerformativeEncoder::disposition(
            channel:  0,
            role:     PerformativeEncoder::ROLE_RECEIVER,
            first:    0,
            settled:  true,
            state:    PerformativeEncoder::accepted(),
        ));

        $publisher = new Publisher($session, '/queues/test');
        $outcome   = $publisher->send(new Message('hello'));

        // Verify a TRANSFER frame was sent (among ATTACH + TRANSFER frames)
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $transferFrame = null;
        foreach ($frames as $frame) {
            $perf = (new TypeDecoder(FrameParser::extractBody($frame)))->decode();
            if (is_array($perf) && ($perf['descriptor'] ?? null) === Descriptor::TRANSFER) {
                $transferFrame = $frame;
            }
        }

        $this->assertNotNull($transferFrame, 'A TRANSFER frame must have been sent');
        $this->assertTrue($outcome->isAccepted());
    }

    public function test_send_returns_rejected_outcome(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name:    'sender-link',
            handle:  0,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  null,
            target:  '/queues/test',
        ));
        $mock->queueIncoming(PerformativeEncoder::disposition(
            channel:  0,
            role:     PerformativeEncoder::ROLE_RECEIVER,
            first:    0,
            settled:  true,
            state:    PerformativeEncoder::rejected(),
        ));

        $publisher = new Publisher($session, '/queues/test');
        $outcome   = $publisher->send(new Message('hello'));

        $this->assertTrue($outcome->isRejected());
    }

    public function test_close_sends_detach_frame(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name:    'sender-link',
            handle:  0,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  null,
            target:  '/queues/test',
        ));
        $mock->queueIncoming(PerformativeEncoder::disposition(
            channel:  0,
            role:     PerformativeEncoder::ROLE_RECEIVER,
            first:    0,
            settled:  true,
            state:    PerformativeEncoder::accepted(),
        ));

        $publisher = new Publisher($session, '/queues/test');
        $publisher->send(new Message('hello'));
        $mock->clearSent();
        $publisher->close();

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $this->assertNotEmpty($frames, 'A DETACH frame must have been sent on close()');
        $perf = (new TypeDecoder(FrameParser::extractBody($frames[0])))->decode();
        $this->assertSame(Descriptor::DETACH, $perf['descriptor']);
    }

    public function test_publisher_builder_send_and_close(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name:    'sender-link',
            handle:  0,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  null,
            target:  '/queues/test',
        ));
        $mock->queueIncoming(PerformativeEncoder::disposition(
            channel:  0,
            role:     PerformativeEncoder::ROLE_RECEIVER,
            first:    0,
            settled:  true,
            state:    PerformativeEncoder::accepted(),
        ));

        $builder = new PublisherBuilder($session, '/queues/test');
        $outcome = $builder->send(new Message('hello'));

        $this->assertTrue($outcome->isAccepted());

        // Verify DETACH was sent (PublisherBuilder calls close() after send)
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $detachFound = false;
        foreach ($frames as $frame) {
            $perf = (new TypeDecoder(FrameParser::extractBody($frame)))->decode();
            if (is_array($perf) && ($perf['descriptor'] ?? null) === Descriptor::DETACH) {
                $detachFound = true;
            }
        }

        $this->assertTrue($detachFound, 'PublisherBuilder must send DETACH after publishing');
    }
}
