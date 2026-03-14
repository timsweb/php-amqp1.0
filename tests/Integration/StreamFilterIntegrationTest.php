<?php

declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Address\AddressHelper;
use AMQP10\Exception\ManagementException;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;
use Throwable;

class StreamFilterIntegrationTest extends RabbitMqTestCase
{
    private string $queueName = 'integ-stream-filter';

    protected function setUp(): void
    {
        $this->runInEventLoop(function (): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $mgmt->declareQueue(new QueueSpecification($this->queueName, QueueType::CLASSIC));
            $mgmt->close();
            $client->close();
        });
    }

    protected function tearDown(): void
    {
        $this->runInEventLoop(function (): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            try {
                $mgmt->deleteQueue($this->queueName);
            } catch (Throwable) {
            }
            $mgmt->close();
            $client->close();
        });
    }

    public function test_consume_from_classic_queue(): void
    {
        $received = [];
        $this->runInEventLoop(function () use (&$received): void {
            $client = $this->newClient()->connect();
            $address = AddressHelper::queueAddress($this->queueName);

            $client->publish($address)->send(new Message('msg-1'));
            $client->publish($address)->send(new Message('msg-2'));

            $consumer = $client->consume($address)->credit(10)->consumer();

            for ($i = 0; $i < 2; $i++) {
                $delivery = $consumer->receive();
                if ($delivery === null) {
                    break;
                }
                $received[] = $delivery->message()->body();
                $delivery->context()->accept();
            }
            $consumer->close();
            $client->close();
        });

        $this->assertCount(2, $received);
        $this->assertContains('msg-1', $received);
        $this->assertContains('msg-2', $received);
    }

    public function test_consume_from_stream_with_offset(): void
    {
        $skipped = false;
        $received = [];

        $this->runInEventLoop(function () use (&$skipped, &$received): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $queueName = $this->queueName . '-offset';

            try {
                try {
                    $mgmt->deleteQueue($queueName);
                } catch (Throwable) {
                }
                $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
            } catch (ManagementException $e) {
                $mgmt->close();
                $client->close();
                $skipped = true;

                return;
            }
            $mgmt->close();

            $address = AddressHelper::queueAddress($queueName);

            for ($i = 1; $i <= 10; $i++) {
                $client->publish($address)->send(new Message("msg-{$i}"));
            }

            $consumer = $client->consume($address)
                ->credit(10)
                ->offset(Offset::offset(5))
                ->consumer();

            for ($i = 0; $i < 5; $i++) {
                $delivery = $consumer->receive();
                if ($delivery === null) {
                    break;
                }
                $received[] = $delivery->message()->body();
                $delivery->context()->accept();
            }
            $consumer->close();

            $mgmt2 = $client->management();
            $mgmt2->deleteQueue($queueName);
            $mgmt2->close();
            $client->close();
        });

        if ($skipped) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }

        $this->assertCount(5, $received);
        $this->assertEquals(['msg-6', 'msg-7', 'msg-8', 'msg-9', 'msg-10'], $received);
    }

    public function test_consume_from_stream_with_filter_amqp_sql(): void
    {
        $skipped = false;
        $received = [];

        $this->runInEventLoop(function () use (&$skipped, &$received): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $queueName = $this->queueName . '-filter-amqp';

            try {
                try {
                    $mgmt->deleteQueue($queueName);
                } catch (Throwable) {
                }
                $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
            } catch (ManagementException $e) {
                $mgmt->close();
                $client->close();
                $skipped = true;

                return;
            }
            $mgmt->close();

            $address = AddressHelper::queueAddress($queueName);

            // Publish messages with different standard properties (for AMQP SQL filtering on properties.subject)
            for ($i = 1; $i <= 10; $i++) {
                $subject = ($i <= 3) ? 'priority-high' : 'priority-low';
                $msg = new Message("msg-{$i}", properties: ['subject' => $subject]);
                $client->publish($address)->send($msg);
            }

            // Filter for high priority messages only using RabbitMQ AMQP SQL
            $consumer = $client->consume($address)
                ->credit(10)
                ->offset(Offset::first())
                ->filterAmqpSql("properties.subject = 'priority-high'")
                ->consumer();

            for ($i = 0; $i < 3; $i++) {
                $delivery = $consumer->receive();
                if ($delivery === null) {
                    break;
                }
                $received[] = $delivery->message()->body();
                $delivery->context()->accept();
            }
            $consumer->close();

            $mgmt2 = $client->management();
            $mgmt2->deleteQueue($queueName);
            $mgmt2->close();
            $client->close();
        });

        if ($skipped) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }

        // Should receive only 3 high-priority messages
        $this->assertCount(3, $received);
        $this->assertContains('msg-1', $received);
        $this->assertContains('msg-2', $received);
        $this->assertContains('msg-3', $received);
        $this->assertNotContains('msg-4', $received);
        $this->assertNotContains('msg-5', $received);
    }

    public function test_consume_from_stream_with_filter_bloom(): void
    {
        $skipped = false;
        $received = [];

        $this->runInEventLoop(function () use (&$skipped, &$received): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $queueName = $this->queueName . '-filter-bloom';

            try {
                try {
                    $mgmt->deleteQueue($queueName);
                } catch (Throwable) {
                }
                $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
            } catch (ManagementException $e) {
                $mgmt->close();
                $client->close();
                $skipped = true;

                return;
            }
            $mgmt->close();

            $address = AddressHelper::queueAddress($queueName);

            // Publish messages with x-stream-filter-value annotation (for Bloom filtering)
            $messages = ['invoices-1', 'orders-1', 'invoices-2', 'other-1', 'invoices-3'];
            foreach ($messages as $msg) {
                $filterValue = str_starts_with($msg, 'invoices') ? 'invoices' : (str_starts_with($msg, 'orders') ? 'orders' : 'other');
                $message = new Message($msg, annotations: ['x-stream-filter-value' => $filterValue]);
                $client->publish($address)->send($message);
            }

            // Filter for 'invoices' only using RabbitMQ Bloom filter
            $consumer = $client->consume($address)
                ->credit(10)
                ->offset(Offset::first())
                ->filterBloom(['invoices'])
                ->consumer();

            for ($i = 0; $i < 3; $i++) {
                $delivery = $consumer->receive();
                if ($delivery === null) {
                    break;
                }
                $received[] = $delivery->message()->body();
                $delivery->context()->accept();
            }
            $consumer->close();

            $mgmt2 = $client->management();
            $mgmt2->deleteQueue($queueName);
            $mgmt2->close();
            $client->close();
        });

        if ($skipped) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }

        // Should receive only 3 invoice messages (Bloom filter is probabilistic, but likely matches)
        $this->assertCount(3, $received);
        $this->assertContains('invoices-1', $received);
        $this->assertContains('invoices-2', $received);
        $this->assertContains('invoices-3', $received);
        $this->assertNotContains('orders-1', $received);
        $this->assertNotContains('other-1', $received);
    }

    public function test_consume_from_stream_with_combined_filters(): void
    {
        $skipped = false;
        $received = [];

        $this->runInEventLoop(function () use (&$skipped, &$received): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $queueName = $this->queueName . '-combined';

            try {
                try {
                    $mgmt->deleteQueue($queueName);
                } catch (Throwable) {
                }
                $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
            } catch (ManagementException $e) {
                $mgmt->close();
                $client->close();
                $skipped = true;

                return;
            }
            $mgmt->close();

            $address = AddressHelper::queueAddress($queueName);

            // Publish messages with different filters and priorities
            for ($i = 1; $i <= 20; $i++) {
                $filterValue = 'other';
                $subject = 'normal';

                if ($i % 5 === 1) {
                    $filterValue = 'high-priority';
                    $subject = 'urgent';
                } elseif ($i % 5 === 2) {
                    $filterValue = 'high-priority';
                    $subject = 'normal';
                } elseif ($i % 5 === 3) {
                    $filterValue = 'other';
                    $subject = 'urgent';
                }

                $msg = new Message(
                    "msg-{$i}",
                    annotations: ['x-stream-filter-value' => $filterValue],
                    properties: ['subject' => $subject]
                );
                $client->publish($address)->send($msg);
            }

            // Combine Bloom filter + AMQP SQL + offset
            $consumer = $client->consume($address)
                ->credit(10)
                ->offset(Offset::offset(5))
                ->filterBloom(['high-priority'])
                ->filterAmqpSql("properties.subject = 'urgent'")
                ->consumer();

            $delivery = $consumer->receive();
            if ($delivery !== null) {
                $received[] = $delivery->message()->body();
                $delivery->context()->accept();
            }
            $consumer->close();

            $mgmt2 = $client->management();
            $mgmt2->deleteQueue($queueName);
            $mgmt2->close();
            $client->close();
        });

        if ($skipped) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }

        // Should receive at least one message (msg-6 or msg-11 or msg-16, after offset 5)
        // that matches both Bloom filter (high-priority) and AMQP SQL (urgent)
        $this->assertGreaterThanOrEqual(1, count($received));
        if (count($received) > 0) {
            $msgNum = (int) str_replace('msg-', '', $received[0]);
            $this->assertGreaterThanOrEqual(6, $msgNum);
        }
    }

    public function test_consume_from_stream_edge_cases(): void
    {
        $skipped = false;
        $received = [];

        $this->runInEventLoop(function () use (&$skipped, &$received): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $queueName = $this->queueName . '-edge';

            try {
                try {
                    $mgmt->deleteQueue($queueName);
                } catch (Throwable) {
                }
                $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
            } catch (ManagementException $e) {
                $mgmt->close();
                $client->close();
                $skipped = true;

                return;
            }
            $mgmt->close();

            $address = AddressHelper::queueAddress($queueName);

            for ($i = 1; $i <= 5; $i++) {
                $client->publish($address)->send(new Message("msg-{$i}"));
            }

            $consumer = $client->consume($address)
                ->credit(10)
                ->offset(Offset::offset(1))
                ->consumer();

            for ($i = 0; $i < 4; $i++) {
                $delivery = $consumer->receive();
                if ($delivery === null) {
                    break;
                }
                $received[] = $delivery->message()->body();
                $delivery->context()->accept();
            }
            $consumer->close();

            $mgmt2 = $client->management();
            $mgmt2->deleteQueue($queueName);
            $mgmt2->close();
            $client->close();
        });

        if ($skipped) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }

        $this->assertCount(4, $received);
        $this->assertEquals('msg-2', $received[0]);
    }
}
