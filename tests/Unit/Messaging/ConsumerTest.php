<?php
declare(strict_types=1);
namespace AMQP10\Tests\Messaging;

use AMQP10\Connection\Session;
use AMQP10\Messaging\Consumer;
use AMQP10\Messaging\ConsumerBuilder;
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ConsumerTest extends TestCase
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

    /**
     * Build a TRANSFER frame carrying a simple message with body $text.
     */
    private function makeTransferFrame(int $channel, string $text, int $deliveryId = 0): string
    {
        $messagePayload = MessageEncoder::encode(new Message($text));
        return PerformativeEncoder::transfer(
            channel:        $channel,
            handle:         0,
            deliveryId:     $deliveryId,
            deliveryTag:    pack('N', $deliveryId),
            messagePayload: $messagePayload,
            settled:        false,
        );
    }

    public function test_consumer_calls_handler_with_message_and_delivery_context(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'hello', deliveryId: 0));

        $received = [];
        $contexts = [];

        $consumer = new Consumer($session, '/queues/test', credit: 1);
        $consumer->run(function (Message $msg, DeliveryContext $ctx) use (&$received, &$contexts, $mock) {
            $received[] = $msg->body();
            $contexts[] = $ctx;
            $mock->disconnect();
        });

        $this->assertCount(1, $received);
        $this->assertSame('hello', $received[0]);
        $this->assertInstanceOf(DeliveryContext::class, $contexts[0]);
    }

    public function test_consumer_sends_attach_and_flow_frames(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));

        $consumer = new Consumer($session, '/queues/test', credit: 5);
        $consumer->run(null);

        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $descriptors = [];
        foreach ($frames as $frame) {
            $perf = (new TypeDecoder(FrameParser::extractBody($frame)))->decode();
            if (is_array($perf)) {
                $descriptors[] = $perf['descriptor'] ?? null;
            }
        }

        $this->assertContains(Descriptor::ATTACH, $descriptors, 'Consumer must send ATTACH');
        $this->assertContains(Descriptor::FLOW,   $descriptors, 'Consumer must send FLOW (credit grant)');
        $this->assertContains(Descriptor::DETACH, $descriptors, 'Consumer must send DETACH on exit');
    }

    public function test_consumer_handles_multiple_messages(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'first',  deliveryId: 0));
        $mock->queueIncoming($this->makeTransferFrame(0, 'second', deliveryId: 1));

        $received = [];
        $count    = 0;

        $consumer = new Consumer($session, '/queues/test', credit: 10);
        $consumer->run(function (Message $msg, DeliveryContext $ctx) use (&$received, &$count, $mock) {
            $received[] = $msg->body();
            $count++;
            if ($count >= 2) {
                $mock->disconnect();
            }
        });

        $this->assertSame(['first', 'second'], $received);
    }

    public function test_consumer_error_handler_called_on_exception(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'boom', deliveryId: 0));

        $errors = [];

        $consumer = new Consumer($session, '/queues/test', credit: 1);
        $consumer->run(
            function (Message $msg, DeliveryContext $ctx) use ($mock) {
                $mock->disconnect();
                throw new \RuntimeException('handler failed');
            },
            function (\Throwable $e) use (&$errors) {
                $errors[] = $e->getMessage();
            }
        );

        $this->assertCount(1, $errors);
        $this->assertSame('handler failed', $errors[0]);
    }

    public function test_consumer_stops_when_transport_disconnects(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));

        $called   = false;
        $consumer = new Consumer($session, '/queues/test', credit: 1);
        $consumer->run(function (Message $msg) use (&$called) {
            $called = true;
        });

        $this->assertFalse($called, 'Handler must not be called when transport is disconnected');
    }

    public function test_consumer_builder_fluent_api(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'built', deliveryId: 0));

        $received = [];

        $builder = new ConsumerBuilder($session, '/queues/test');
        $builder
            ->credit(5)
            ->handle(function (Message $msg, DeliveryContext $ctx) use (&$received, $mock) {
                $received[] = $msg->body();
                $mock->disconnect();
            })
            ->run();

        $this->assertSame(['built'], $received);
    }

    public function test_consumer_builder_prefetch_alias(): void
    {
        [$mock, $session] = $this->makeSession();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0, name: 'recv', handle: 0,
            role:    PerformativeEncoder::ROLE_SENDER,
            source:  '/queues/test', target: null,
        ));

        $builder = new ConsumerBuilder($session, '/queues/test');
        $builder->prefetch(20)->run();

        $this->assertTrue(true); // no exception = pass
    }
}
