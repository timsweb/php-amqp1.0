<?php

declare(strict_types=1);

namespace AMQP10\Tests\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Messaging\InboundMessage;
use AMQP10\Messaging\Message;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;
use PHPUnit\Framework\TestCase;

class InboundMessageTest extends TestCase
{
    private function makeInbound(string $body = 'hello'): InboundMessage
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = new Message(
            body: $body,
            subject: 'test-subject',
            ttl: 5000,
            priority: 3,
            durable: false,
        );

        return new InboundMessage($message, new DeliveryContext(0, $link));
    }

    public function test_body_delegates_to_message(): void
    {
        $this->assertSame('hello world', $this->makeInbound('hello world')->body());
    }

    public function test_subject_delegates_to_message(): void
    {
        $this->assertSame('test-subject', $this->makeInbound()->subject());
    }

    public function test_durable_delegates_to_message(): void
    {
        $this->assertFalse($this->makeInbound()->durable());
    }

    public function test_ttl_delegates_to_message(): void
    {
        $this->assertSame(5000, $this->makeInbound()->ttl());
    }

    public function test_priority_delegates_to_message(): void
    {
        $this->assertSame(3, $this->makeInbound()->priority());
    }

    public function test_property_delegates_to_message(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withCorrelationId('abc-123');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame('abc-123', $msg->property('correlation-id'));
    }

    public function test_properties_returns_array(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withCorrelationId('abc-123');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame(['correlation-id' => 'abc-123'], $msg->properties());
    }

    public function test_application_property_delegates(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withApplicationProperty('x-type', 'order');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame('order', $msg->applicationProperty('x-type'));
    }

    public function test_application_properties_returns_array(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withApplicationProperty('x-type', 'order');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame(['x-type' => 'order'], $msg->applicationProperties());
    }

    public function test_annotation_delegates(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withAnnotation('x-stream-filter-value', 'eu');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame('eu', $msg->annotation('x-stream-filter-value'));
    }

    public function test_annotations_returns_array(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = (new Message('body'))->withAnnotation('x-stream-filter-value', 'eu');
        $msg = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame(['x-stream-filter-value' => 'eu'], $msg->annotations());
    }

    public function test_message_escape_hatch_returns_original(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $message = new Message('body');
        $inbound = new InboundMessage($message, new DeliveryContext(0, $link));

        $this->assertSame($message, $inbound->message());
    }

    public function test_accept_settles_delivery(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())->method('settle')->with(42, $this->anything());

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(42, $link));
        $msg->accept();
    }

    public function test_reject_settles_delivery(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())->method('settle')->with(1, $this->anything());

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(1, $link));
        $msg->reject();
    }

    public function test_release_settles_delivery(): void
    {
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())->method('settle')->with(7, $this->anything());

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(7, $link));
        $msg->release();
    }

    public function test_modify_sends_modified_outcome(): void
    {
        $sentState = null;
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())
            ->method('settle')
            ->with(42, $this->callback(function (string $state) use (&$sentState) {
                $sentState = $state;

                return true;
            }));

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(42, $link));
        $msg->modify(deliveryFailed: true, undeliverableHere: true);

        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertTrue($decoded['value'][0]);
        $this->assertTrue($decoded['value'][1]);
    }

    public function test_modify_defaults(): void
    {
        $sentState = null;
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())
            ->method('settle')
            ->with(1, $this->callback(function (string $state) use (&$sentState) {
                $sentState = $state;

                return true;
            }));

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(1, $link));
        $msg->modify();

        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertTrue($decoded['value'][0]);
        $this->assertTrue($decoded['value'][1]);
    }

    public function test_modify_with_false_flags(): void
    {
        $sentState = null;
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())
            ->method('settle')
            ->with(5, $this->callback(function (string $state) use (&$sentState) {
                $sentState = $state;

                return true;
            }));

        $msg = new InboundMessage(new Message('body'), new DeliveryContext(5, $link));
        $msg->modify(deliveryFailed: false, undeliverableHere: false);

        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertFalse($decoded['value'][0]);
        $this->assertFalse($decoded['value'][1]);
    }
}
