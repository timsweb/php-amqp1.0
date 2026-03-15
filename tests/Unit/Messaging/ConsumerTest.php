<?php

declare(strict_types=1);

namespace AMQP10\Tests\Messaging;

use AMQP10\Connection\Session;
use AMQP10\Messaging\Consumer;
use AMQP10\Messaging\ConsumerBuilder;
use AMQP10\Messaging\InboundMessage;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Messaging\Offset;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Tests\Mocks\ClientMock;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class ConsumerTest extends TestCase
{
    /** @return array{TransportMock, Session, ClientMock} */
    private function makeClient(): array
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();
        $client = new ClientMock($session);

        return [$mock, $session, $client];
    }

    /**
     * Build a TRANSFER frame carrying a simple message with body $text.
     */
    private function makeTransferFrame(int $channel, string $text, int $deliveryId = 0): string
    {
        $messagePayload = MessageEncoder::encode(new Message($text));

        return PerformativeEncoder::transfer(
            channel: $channel,
            handle: 0,
            deliveryId: $deliveryId,
            deliveryTag: pack('N', $deliveryId),
            messagePayload: $messagePayload,
            settled: false,
        );
    }

    public function test_consumer_calls_handler_with_inbound_message(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'hello', deliveryId: 0));

        $received = [];
        $msgs = [];

        $consumer = new Consumer($client, '/queues/test', credit: 1);
        $consumer->run(function (InboundMessage $msg) use (&$received, &$msgs, $mock) {
            $received[] = $msg->body();
            $msgs[] = $msg;
            $mock->disconnect();
        });

        $this->assertCount(1, $received);
        $this->assertSame('hello', $received[0]);
        $this->assertInstanceOf(InboundMessage::class, $msgs[0]);
    }

    public function test_consumer_sends_attach_and_flow_frames(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));

        $consumer = new Consumer($client, '/queues/test', credit: 5, idleTimeout: 0.05);
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
        $this->assertContains(Descriptor::FLOW, $descriptors, 'Consumer must send FLOW (credit grant)');
        $this->assertContains(Descriptor::DETACH, $descriptors, 'Consumer must send DETACH on exit');
    }

    public function test_consumer_handles_multiple_messages(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'first', deliveryId: 0));
        $mock->queueIncoming($this->makeTransferFrame(0, 'second', deliveryId: 1));

        $received = [];
        $count = 0;

        $consumer = new Consumer($client, '/queues/test', credit: 10);
        $consumer->run(function (InboundMessage $msg) use (&$received, &$count, $mock) {
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
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'boom', deliveryId: 0));

        $errors = [];

        $consumer = new Consumer($client, '/queues/test', credit: 1);
        $consumer->run(
            function (InboundMessage $msg) use ($mock) {
                $mock->disconnect();
                throw new RuntimeException('handler failed');
            },
            function (Throwable $e) use (&$errors) {
                $errors[] = $e->getMessage();
            }
        );

        $this->assertCount(1, $errors);
        $this->assertSame('handler failed', $errors[0]);
    }

    public function test_consumer_stops_when_transport_disconnects(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));

        $called = false;
        $consumer = new Consumer($client, '/queues/test', credit: 1, idleTimeout: 0.05);
        $consumer->run(function (InboundMessage $msg) use (&$called) {
            $called = true;
        });

        $this->assertFalse($called, 'Handler must not be called when transport is disconnected');
    }

    public function test_consumer_builder_fluent_api(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'built', deliveryId: 0));

        $received = [];

        $builder = new ConsumerBuilder($client, '/queues/test');
        $builder
            ->credit(5)
            ->handle(function (InboundMessage $msg) use (&$received, $mock) {
                $received[] = $msg->body();
                $mock->disconnect();
            })
            ->run();

        $this->assertSame(['built'], $received);
    }

    public function test_consumer_builder_prefetch_alias(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));

        $builder = new ConsumerBuilder($client, '/queues/test', idleTimeout: 0.05);
        $builder->prefetch(20)->run();

        $this->assertTrue(true); // no exception = pass
    }

    public function test_get_frame_descriptor_returns_transfer_descriptor(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1);

        $messagePayload = MessageEncoder::encode(new Message('test'));
        $transferFrame = PerformativeEncoder::transfer(
            channel: 0,
            handle: 0,
            deliveryId: 0,
            deliveryTag: pack('N', 0),
            messagePayload: $messagePayload,
            settled: false,
        );

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('getFrameDescriptor');
        $method->setAccessible(true);

        $result = $method->invoke($consumer, $transferFrame);

        $this->assertSame(Descriptor::TRANSFER, $result);
    }

    public function test_get_frame_descriptor_handles_malformed_frame(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1);

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('getFrameDescriptor');
        $method->setAccessible(true);

        $result = $method->invoke($consumer, '');

        $this->assertNull($result);
    }

    public function test_build_filter_map_with_offset(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1, offset: Offset::offset(5));

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(1, $map);

        $key = array_key_first($map);
        $value = $map[$key];

        // Value must be a described type per AMQP 1.0 spec §3.5 (avoids amqp_filter_set_bug compat)
        $this->assertIsArray($value);
        $this->assertSame('rabbitmq:stream-offset-spec', $value['descriptor']);
        $this->assertSame(5, $value['value']);
    }

    public function test_build_filter_map_with_filter_sql(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1, filterAmqpSql: "color = 'red'");

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(1, $map);

        $key = array_key_first($map);
        $value = $map[$key];

        // Described type: map key is 'sql-filter', but internal descriptor is 'amqp:sql-filter'
        $this->assertSame('amqp:sql-filter', $value['descriptor']);
        $this->assertSame("color = 'red'", $value['value']);
    }

    public function test_build_filter_map_with_offset_and_filter_sql(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer(
            $client,
            '/queues/test',
            credit: 1,
            offset: Offset::offset(10),
            filterAmqpSql: 'priority > 5'
        );

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(2, $map);

        $keys = array_keys($map);
        $this->assertContains('rabbitmq:stream-offset-spec', $keys);
        $this->assertContains('sql-filter', $keys);
    }

    public function test_build_filter_map_returns_null_when_no_filters(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1);

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNull($result);
    }

    public function test_receive_returns_inbound_message(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'hello', deliveryId: 0));

        $consumer = new Consumer($client, '/queues/test', credit: 1, idleTimeout: 0.05);
        $msg = $consumer->receive();

        $this->assertNotNull($msg);
        $this->assertInstanceOf(InboundMessage::class, $msg);
        $this->assertSame('hello', $msg->body());

        $consumer->close();
    }

    public function test_receive_returns_null_on_timeout(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));

        $consumer = new Consumer($client, '/queues/test', credit: 1, idleTimeout: 0.05);
        $delivery = $consumer->receive();

        $this->assertNull($delivery);
        $consumer->close();
    }

    public function test_receive_multiple(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'first', deliveryId: 0));
        $mock->queueIncoming($this->makeTransferFrame(0, 'second', deliveryId: 1));

        $consumer = new Consumer($client, '/queues/test', credit: 10, idleTimeout: 0.05);
        $d1 = $consumer->receive();
        $d2 = $consumer->receive();

        $this->assertSame('first', $d1->body());
        $this->assertSame('second', $d2->body());
        $consumer->close();
    }

    public function test_consumer_builder_consumer_method(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        $mock->queueIncoming($this->makeTransferFrame(0, 'via-builder', deliveryId: 0));

        $builder = new ConsumerBuilder($client, '/queues/test', idleTimeout: 0.05);
        $consumer = $builder->consumer();
        $delivery = $consumer->receive();

        $this->assertNotNull($delivery);
        $this->assertSame('via-builder', $delivery->body());
        $consumer->close();
    }

    public function test_run_returns_on_idle_timeout(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        // No messages queued — should idle-timeout

        $called = false;
        $consumer = new Consumer($client, '/queues/test', credit: 1, idleTimeout: 0.05);
        $consumer->run(function (InboundMessage $msg) use (&$called) {
            $called = true;
        });

        $this->assertFalse($called, 'Handler must not be called when no messages arrive');
    }

    public function test_build_filter_map_with_filter_jms(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1, filterJms: "color = 'red'");

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(1, $map);

        $key = array_key_first($map);
        $value = $map[$key];

        // Raw string value — not wrapped in a described type (JMS selector)
        $this->assertSame('apache.org:selector-filter:string', $key);
        $this->assertSame("color = 'red'", $value);
    }

    public function test_build_filter_map_with_filter_amqp_sql(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1, filterAmqpSql: 'priority > 5');

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(1, $map);

        $key = array_key_first($map);
        $value = $map[$key];

        // Map key is 'sql-filter'; internal described type descriptor is 'amqp:sql-filter'
        $this->assertIsArray($value);
        $this->assertSame('amqp:sql-filter', $value['descriptor']);
        $this->assertSame('priority > 5', $value['value']);
    }

    public function test_build_filter_map_with_filter_bloom_single(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1, filterBloomValues: ['invoices']);

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(1, $map);

        $key = array_key_first($map);
        $value = $map[$key];

        // Single value: described type (per AMQP spec §3.5.4 filter-set values must be described types)
        $this->assertSame('rabbitmq:stream-filter', $key);
        $this->assertIsArray($value);
        $this->assertSame('rabbitmq:stream-filter', $value['descriptor']);
        $this->assertSame('invoices', $value['value']);
    }

    public function test_build_filter_map_with_filter_bloom_multiple(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1, filterBloomValues: ['california', 'texas', 'newyork']);

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(1, $map);

        $key = array_key_first($map);
        $value = $map[$key];

        // Multiple values: described type wrapping a symbol array (per AMQP spec §3.5.4)
        $this->assertSame('rabbitmq:stream-filter', $key);
        $this->assertIsArray($value);
        $this->assertSame('rabbitmq:stream-filter', $value['descriptor']);
        $this->assertIsArray($value['value']);
        $this->assertContains('california', $value['value']);
        $this->assertContains('texas', $value['value']);
        $this->assertContains('newyork', $value['value']);
    }

    public function test_build_filter_map_with_match_unfiltered(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1, matchUnfiltered: true);

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(1, $map);

        $this->assertArrayHasKey('rabbitmq:stream-match-unfiltered', $map);
        $unfiltered = $map['rabbitmq:stream-match-unfiltered'];
        $this->assertIsArray($unfiltered);
        $this->assertSame('rabbitmq:stream-match-unfiltered', $unfiltered['descriptor']);
        $this->assertTrue($unfiltered['value']);
    }

    public function test_build_filter_map_with_multiple_filters(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer(
            $client,
            '/queues/test',
            credit: 1,
            offset: Offset::offset(10),
            filterAmqpSql: 'priority > 4',
            filterBloomValues: ['urgent', 'high-priority'],
            matchUnfiltered: true
        );

        $reflection = new ReflectionClass($consumer);
        $method = $reflection->getMethod('buildFilterMap');
        $method->setAccessible(true);

        $result = $method->invoke($consumer);

        $this->assertNotNull($result);

        $decoder = new TypeDecoder($result);
        $map = $decoder->decode();

        $this->assertIsArray($map);
        $this->assertCount(4, $map);

        $this->assertArrayHasKey('rabbitmq:stream-offset-spec', $map);
        $this->assertArrayHasKey('sql-filter', $map);
        $this->assertArrayHasKey('rabbitmq:stream-filter', $map);
        $this->assertArrayHasKey('rabbitmq:stream-match-unfiltered', $map);
    }

    public function test_filter_sql_maps_to_filter_amqp_sql(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $builder = new ConsumerBuilder($client, '/queues/test');
        $builder->filterSql('priority > 5');

        $consumer = $builder->consumer();

        $reflection = new ReflectionClass($consumer);
        $filterAmqpSqlProperty = $reflection->getProperty('filterAmqpSql');
        $filterAmqpSqlProperty->setAccessible(true);
        $filterJmsProperty = $reflection->getProperty('filterJms');
        $filterJmsProperty->setAccessible(true);

        // filterSql() should map to filterAmqpSql, not filterJms
        $this->assertSame('priority > 5', $filterAmqpSqlProperty->getValue($consumer));
        $this->assertNull($filterJmsProperty->getValue($consumer));
    }

    public function test_consumer_credit_replenishment(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));
        // Queue 5 messages; with credit=4, replenish threshold is floor(4/2)=2
        for ($i = 0; $i < 5; $i++) {
            $mock->queueIncoming($this->makeTransferFrame(0, "msg-$i", deliveryId: $i));
        }

        $count = 0;
        $consumer = new Consumer($client, '/queues/test', credit: 4);
        $consumer->run(function (InboundMessage $msg) use (&$count, $mock) {
            $count++;
            if ($count >= 5) {
                $mock->disconnect();
            }
        });

        // Verify FLOW frames were sent (more than just initial credit grant)
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $flowCount = 0;
        foreach ($frames as $frame) {
            $perf = (new TypeDecoder(FrameParser::extractBody($frame)))->decode();
            if (is_array($perf) && ($perf['descriptor'] ?? null) === Descriptor::FLOW) {
                $flowCount++;
            }
        }

        // At least 2 FLOW frames: initial credit grant + at least one replenishment
        $this->assertGreaterThanOrEqual(2, $flowCount, 'Credit replenishment should send additional FLOW frames');
        $this->assertSame(5, $count);
    }

    public function test_consumer_stable_link_name(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'my-stable-link',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));

        $consumer = new Consumer($client, '/queues/test', credit: 1, idleTimeout: 0.05, linkName: 'my-stable-link');
        $consumer->run(null);

        // Verify the ATTACH frame sent contains our stable link name
        $parser = new FrameParser();
        $parser->feed($mock->sent());
        $frames = $parser->readyFrames();

        $attachFrame = null;
        foreach ($frames as $frame) {
            $perf = (new TypeDecoder(FrameParser::extractBody($frame)))->decode();
            if (is_array($perf) && ($perf['descriptor'] ?? null) === Descriptor::ATTACH) {
                $attachFrame = $perf;
                break;
            }
        }

        $this->assertNotNull($attachFrame, 'ATTACH frame must be sent');
        // value[0] is the link name in ATTACH performative
        $this->assertSame('my-stable-link', $attachFrame['value'][0]);
    }

    public function test_consumer_builder_new_methods(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $builder = new ConsumerBuilder($client, '/queues/test');
        $result = $builder
            ->linkName('durable-consumer-1')
            ->durable()
            ->expiryPolicy()
            ->withReconnect(5, 500);

        $this->assertInstanceOf(ConsumerBuilder::class, $result);
    }

    public function test_stop_flag_starts_false_and_is_true_after_stop(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $consumer = new Consumer($client, '/queues/test', credit: 1);

        $ref = new ReflectionProperty(Consumer::class, 'stopRequested');
        $ref->setAccessible(true);

        $this->assertFalse($ref->getValue($consumer));

        $consumer->stop();

        $this->assertTrue($ref->getValue($consumer));
    }

    public function test_stop_causes_receive_to_return_null(): void
    {
        [$mock, $session, $client] = $this->makeClient();

        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name: 'recv',
            handle: 0,
            role: PerformativeEncoder::ROLE_SENDER,
            source: '/queues/test',
            target: null,
        ));

        $consumer = new Consumer($client, '/queues/test', credit: 1, idleTimeout: 5.0);
        // Ensure attached
        $consumer->receive(); // will idle-timeout (no messages) — but first we need attached state

        // Actually test stop: set attached state then call stop() before receive()
        $refAttached = new ReflectionProperty(Consumer::class, 'attached');
        $refAttached->setAccessible(true);
        $refAttached->setValue($consumer, true);

        $consumer->stop();
        $result = $consumer->receive();

        $this->assertNull($result);
    }
}
