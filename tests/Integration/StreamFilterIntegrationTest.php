<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Address\AddressHelper;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Offset;

class StreamFilterIntegrationTest extends IntegrationTestCase
{
    private \AMQP10\Client\Client $client;
    private string $queueName = 'integ-stream-filter';

    protected function setUp(): void
    {
        $this->client = $this->newClient()->connect();
        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification($this->queueName, QueueType::CLASSIC));
        $mgmt->close();
    }

    protected function tearDown(): void
    {
        if (!$this->client->isConnected()) {
            $this->client = $this->newClient()->connect();
        }
        $mgmt = $this->client->management();
        try { $mgmt->deleteQueue($this->queueName); } catch (\Throwable) {}
        $mgmt->close();
        $this->client->close();
    }

    public function test_consume_from_classic_queue(): void
    {
        $address = AddressHelper::queueAddress($this->queueName);

        $this->client->publish($address)->send(new Message('msg-1'));
        $this->client->publish($address)->send(new Message('msg-2'));

        $received = [];
        $count    = 0;
        $client   = $this->client;

        set_time_limit(15);

        $this->client->consume($address)
            ->credit(10)
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
                use (&$received, &$count, $client) {
                $received[] = $msg->body();
                $ctx->accept();
                $count++;
                if ($count >= 2) {
                    $client->close();
                }
            })
            ->run();

        $this->assertCount(2, $received);
        $this->assertContains('msg-1', $received);
        $this->assertContains('msg-2', $received);
    }

    public function test_consume_from_stream_with_offset(): void
    {
        $mgmt = $this->client->management();
        $queueName = $this->queueName . '-offset';

        try {
            try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
            $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
        } catch (\AMQP10\Exception\ManagementException $e) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }
        $mgmt->close();

        $address = AddressHelper::queueAddress($queueName);

        for ($i = 1; $i <= 10; $i++) {
            $this->client->publish($address)->send(new Message("msg-{$i}"));
        }

        $received = [];
        $count    = 0;
        $client   = $this->client;

        set_time_limit(15);

        $this->client->consume($address)
            ->credit(10)
            ->offset(Offset::offset(5))
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
                use (&$received, &$count, $client) {
                $received[] = $msg->body();
                $ctx->accept();
                $count++;
                if ($count >= 5) {
                    $client->close();
                }
            })
            ->run();

        $this->assertCount(5, $received);
        $this->assertEquals(['msg-6', 'msg-7', 'msg-8', 'msg-9', 'msg-10'], $received);

        $cleanup = $this->newClient()->connect();
        $cleanup->management()->deleteQueue($queueName);
        $cleanup->management()->close();
        $cleanup->close();
    }

    public function test_consume_from_stream_with_filterAmqpSql(): void
    {
        $mgmt = $this->client->management();
        $queueName = $this->queueName . '-filter-amqp';

        try {
            try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
            $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
        } catch (\AMQP10\Exception\ManagementException $e) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }
        $mgmt->close();

        $address = AddressHelper::queueAddress($queueName);

        // Publish messages with different standard properties (for AMQP SQL filtering on properties.subject)
        for ($i = 1; $i <= 10; $i++) {
            $subject = ($i <= 3) ? 'priority-high' : 'priority-low';
            $msg = new Message("msg-{$i}", properties: ['subject' => $subject]);
            $this->client->publish($address)->send($msg);
        }

        $received = [];
        $count = 0;
        $client = $this->client;

        set_time_limit(15);

        // Filter for high priority messages only using RabbitMQ AMQP SQL
        $this->client->consume($address)
            ->credit(10)
            ->offset(Offset::first())
            ->filterAmqpSql("properties.subject = 'priority-high'")
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
                use (&$received, &$count, $client) {
                $received[] = $msg->body();
                $ctx->accept();
                $count++;
                if ($count >= 3) {
                    $client->close();
                }
            })
            ->run();

        // Should receive only 3 high-priority messages
        $this->assertCount(3, $received);
        $this->assertContains('msg-1', $received);
        $this->assertContains('msg-2', $received);
        $this->assertContains('msg-3', $received);
        $this->assertNotContains('msg-4', $received);
        $this->assertNotContains('msg-5', $received);

        $cleanup = $this->newClient()->connect();
        $cleanup->management()->deleteQueue($queueName);
        $cleanup->management()->close();
        $cleanup->close();
    }

    public function test_consume_from_stream_with_filterBloom(): void
    {
        $mgmt = $this->client->management();
        $queueName = $this->queueName . '-filter-bloom';

        try {
            try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
            $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
        } catch (\AMQP10\Exception\ManagementException $e) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }
        $mgmt->close();

        $address = AddressHelper::queueAddress($queueName);

        // Publish messages with x-stream-filter-value annotation (for Bloom filtering)
        $messages = ['invoices-1', 'orders-1', 'invoices-2', 'other-1', 'invoices-3'];
        foreach ($messages as $msg) {
            $filterValue = str_starts_with($msg, 'invoices') ? 'invoices' : (str_starts_with($msg, 'orders') ? 'orders' : 'other');
            $message = new Message($msg, annotations: ['x-stream-filter-value' => $filterValue]);
            $this->client->publish($address)->send($message);
        }

        $received = [];
        $count = 0;
        $client = $this->client;

        set_time_limit(15);

        // Filter for 'invoices' only using RabbitMQ Bloom filter
        $this->client->consume($address)
            ->credit(10)
            ->offset(Offset::first())
            ->filterBloom(['invoices'])
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
                use (&$received, &$count, $client) {
                $received[] = $msg->body();
                $ctx->accept();
                $count++;
                if ($count >= 3) {
                    $client->close();
                }
            })
            ->run();

        // Should receive only 3 invoice messages (Bloom filter is probabilistic, but likely matches)
        $this->assertCount(3, $received);
        $this->assertContains('invoices-1', $received);
        $this->assertContains('invoices-2', $received);
        $this->assertContains('invoices-3', $received);
        $this->assertNotContains('orders-1', $received);
        $this->assertNotContains('other-1', $received);

        $cleanup = $this->newClient()->connect();
        $cleanup->management()->deleteQueue($queueName);
        $cleanup->management()->close();
        $cleanup->close();
    }

    public function test_consume_from_stream_with_combined_filters(): void
    {
        $mgmt = $this->client->management();
        $queueName = $this->queueName . '-combined';

        try {
            try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
            $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
        } catch (\AMQP10\Exception\ManagementException $e) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
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
            $this->client->publish($address)->send($msg);
        }

        $received = [];
        $count = 0;
        $client = $this->client;

        set_time_limit(15);

        // Combine Bloom filter + AMQP SQL + offset
        $this->client->consume($address)
            ->credit(10)
            ->offset(Offset::offset(5))
            ->filterBloom(['high-priority'])
            ->filterAmqpSql("properties.subject = 'urgent'")
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
                use (&$received, &$count, $client) {
                $received[] = $msg->body();
                $ctx->accept();
                $count++;
                if ($count >= 1) {
                    $client->close();
                }
            })
            ->run();

        // Should receive at least one message (msg-6 or msg-11 or msg-16, after offset 5)
        // that matches both Bloom filter (high-priority) and AMQP SQL (urgent)
        $this->assertGreaterThanOrEqual(1, count($received));
        if (count($received) > 0) {
            $msgNum = (int) str_replace('msg-', '', $received[0]);
            $this->assertGreaterThanOrEqual(6, $msgNum);
        }

        $cleanup = $this->newClient()->connect();
        $cleanup->management()->deleteQueue($queueName);
        $cleanup->management()->close();
        $cleanup->close();
    }

    public function test_consume_from_stream_edge_cases(): void
    {
        $mgmt = $this->client->management();
        $queueName = $this->queueName . '-edge';

        try {
            try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
            $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
        } catch (\AMQP10\Exception\ManagementException $e) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }
        $mgmt->close();

        $address = AddressHelper::queueAddress($queueName);

        for ($i = 1; $i <= 5; $i++) {
            $this->client->publish($address)->send(new Message("msg-{$i}"));
        }

        $received = [];
        $count    = 0;
        $client   = $this->client;

        set_time_limit(15);

        $this->client->consume($address)
            ->credit(10)
            ->offset(Offset::offset(1))
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx)
                use (&$received, &$count, $client) {
                $received[] = $msg->body();
                $ctx->accept();
                $count++;
                if ($count >= 4) {
                    $client->close();
                }
            })
            ->run();

        $this->assertCount(4, $received);
        $this->assertEquals('msg-2', $received[0]);

        $cleanup = $this->newClient()->connect();
        $cleanup->management()->deleteQueue($queueName);
        $cleanup->management()->close();
        $cleanup->close();
    }
}
