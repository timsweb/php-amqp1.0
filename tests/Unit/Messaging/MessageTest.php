<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Messaging\Message;
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

    public function test_durable_defaults_to_true(): void
    {
        $m = Message::create('body');
        $this->assertTrue($m->durable());
    }

    public function test_with_durable_false(): void
    {
        $m = Message::create('body')->withDurable(false);
        $this->assertFalse($m->durable());
        $orig = Message::create('body');
        $this->assertTrue($orig->durable());
    }

    public function test_with_subject(): void
    {
        $m = Message::create('body')->withSubject('order.placed');
        $this->assertSame('order.placed', $m->subject());
    }

    public function test_subject_is_null_by_default(): void
    {
        $this->assertNull(Message::create('body')->subject());
    }

    public function test_with_message_id(): void
    {
        $m = Message::create('body')->withMessageId('abc-123');
        $this->assertSame('abc-123', $m->property('message-id'));
    }

    public function test_with_content_type(): void
    {
        $m = Message::create('body')->withContentType('application/json');
        $this->assertSame('application/json', $m->property('content-type'));
    }

    public function test_with_correlation_id(): void
    {
        $m = Message::create('body')->withCorrelationId('corr-1');
        $this->assertSame('corr-1', $m->property('correlation-id'));
    }

    public function test_with_application_property(): void
    {
        $m = Message::create('body')->withApplicationProperty('source', 'checkout');
        $this->assertSame('checkout', $m->applicationProperty('source'));
    }

    public function test_with_ttl(): void
    {
        $m = Message::create('body')->withTtl(5000);
        $this->assertSame(5000, $m->ttl());
    }

    public function test_with_priority(): void
    {
        $m = Message::create('body')->withPriority(9);
        $this->assertSame(9, $m->priority());
    }

    public function test_wither_immutability(): void
    {
        $original = Message::create('body');
        $modified = $original->withSubject('test');
        $this->assertNotSame($original, $modified);
        $this->assertNull($original->subject());
    }
}
