<?php

declare(strict_types=1);

namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageDecoder;
use AMQP10\Messaging\MessageEncoder;
use PHPUnit\Framework\TestCase;

class MessageEncoderDecoderTest extends TestCase
{
    public function test_roundtrip_all_eight_properties(): void
    {
        $props = [
            'message-id' => 'msg-001',
            'user-id' => 'guest',
            'to' => '/queues/target',
            'subject' => 'test-subject',
            'reply-to' => '/queues/reply',
            'correlation-id' => 'corr-001',
            'content-type' => 'application/json',
            'content-encoding' => 'gzip',
        ];

        $original = new Message('body', properties: $props);
        $encoded = MessageEncoder::encode($original);
        $decoded = MessageDecoder::decode($encoded);

        foreach ($props as $key => $value) {
            $this->assertSame($value, $decoded->property($key), "Property '$key' roundtrip failed");
        }
        $this->assertSame('body', $decoded->body());
    }

    public function test_roundtrip_partial_properties(): void
    {
        $props = [
            'message-id' => 'partial-001',
            'subject' => 'only-two',
        ];

        $original = new Message('data', properties: $props);
        $encoded = MessageEncoder::encode($original);
        $decoded = MessageDecoder::decode($encoded);

        $this->assertSame('partial-001', $decoded->property('message-id'));
        $this->assertSame('only-two', $decoded->property('subject'));
        $this->assertNull($decoded->property('user-id'));
        $this->assertNull($decoded->property('to'));
        $this->assertNull($decoded->property('content-type'));
    }

    public function test_content_encoding_roundtrip_as_symbol(): void
    {
        $original = new Message('payload', properties: ['content-encoding' => 'utf-8']);
        $encoded = MessageEncoder::encode($original);
        $decoded = MessageDecoder::decode($encoded);

        $this->assertSame('utf-8', $decoded->property('content-encoding'));
    }

    public function test_roundtrip_with_no_properties(): void
    {
        $original = new Message('bare');
        $encoded = MessageEncoder::encode($original);
        $decoded = MessageDecoder::decode($encoded);

        $this->assertSame('bare', $decoded->body());
        $this->assertNull($decoded->property('message-id'));
    }
}
