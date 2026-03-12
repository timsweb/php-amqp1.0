<?php
declare(strict_types=1);

namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Messaging\MessageDecoder;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_body_string(): void
    {
        $msg = new Message('hello world');
        $this->assertSame('hello world', $msg->body());
    }

    public function test_properties(): void
    {
        $msg = new Message('body', properties: ['content-type' => 'text/plain']);
        $this->assertSame('text/plain', $msg->property('content-type'));
    }

    public function test_application_properties(): void
    {
        $msg = new Message('body', applicationProperties: ['correlation-id' => '123']);
        $this->assertSame('123', $msg->applicationProperty('correlation-id'));
    }

    public function test_missing_property_returns_null(): void
    {
        $msg = new Message('body');
        $this->assertNull($msg->property('content-type'));
    }

    public function test_ttl_and_priority(): void
    {
        $msg = new Message('body', ttl: 5000, priority: 8);
        $this->assertSame(5000, $msg->ttl());
        $this->assertSame(8, $msg->priority());
    }

    public function test_annotations_roundtrip(): void
    {
        $msg     = new Message('body', annotations: ['x-stream-offset' => 42]);
        $encoded = MessageEncoder::encode($msg);
        $decoded = MessageDecoder::decode($encoded);
        $this->assertSame(42, $decoded->annotation('x-stream-offset'));
    }
}
