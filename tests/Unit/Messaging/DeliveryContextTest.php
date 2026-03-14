<?php

declare(strict_types=1);

namespace AMQP10\Tests\Messaging;

use AMQP10\Connection\ReceiverLink;
use AMQP10\Messaging\DeliveryContext;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;
use PHPUnit\Framework\TestCase;

class DeliveryContextTest extends TestCase
{
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

        $ctx = new DeliveryContext(42, $link);
        $ctx->modify(deliveryFailed: true, undeliverableHere: true);

        $this->assertNotNull($sentState);
        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertTrue($decoded['value'][0]); // delivery-failed
        $this->assertTrue($decoded['value'][1]); // undeliverable-here
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

        $ctx = new DeliveryContext(1, $link);
        $ctx->modify();

        $this->assertNotNull($sentState);
        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertTrue($decoded['value'][0]); // delivery-failed defaults true
        $this->assertTrue($decoded['value'][1]); // undeliverable-here defaults true
    }

    public function test_modify_delivery_failed_false(): void
    {
        $sentState = null;
        $link = $this->createMock(ReceiverLink::class);
        $link->expects($this->once())
            ->method('settle')
            ->with(5, $this->callback(function (string $state) use (&$sentState) {
                $sentState = $state;

                return true;
            }));

        $ctx = new DeliveryContext(5, $link);
        $ctx->modify(deliveryFailed: false, undeliverableHere: false);

        $this->assertNotNull($sentState);
        $decoded = (new TypeDecoder($sentState))->decode();
        $this->assertSame(Descriptor::MODIFIED, $decoded['descriptor']);
        $this->assertFalse($decoded['value'][0]); // delivery-failed
        $this->assertFalse($decoded['value'][1]); // undeliverable-here
    }
}
