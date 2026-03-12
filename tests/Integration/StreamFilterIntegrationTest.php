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

    public function test_consume_from_stream_with_filterSql(): void
    {
        $mgmt = $this->client->management();
        $queueName = $this->queueName . '-filter';

        // RabbitMQ streams do not support the apache.org:selector-filter:string SQL selector.
        // That filter is specific to ActiveMQ/Artemis JMS brokers. Skip until RabbitMQ adds support.
        $this->markTestSkipped('RabbitMQ streams do not support apache.org:selector-filter:string');

        try {
            try { $mgmt->deleteQueue($queueName); } catch (\Throwable) {}
            $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::STREAM));
        } catch (\AMQP10\Exception\ManagementException $e) {
            $this->markTestSkipped('RabbitMQ stream plugin not available');
        }
        $mgmt->close();

        $address = AddressHelper::queueAddress($queueName);

        $messages = ['red-1', 'blue-1', 'red-2', 'green-1', 'red-3'];
        foreach ($messages as $msg) {
            $this->client->publish($address)->send(new Message($msg));
        }

        $received = [];
        $count    = 0;
        $client   = $this->client;

        set_time_limit(15);

        $this->client->consume($address)
            ->credit(10)
            ->filterSql("body LIKE 'red%'")
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

        $this->assertCount(3, $received);
        $this->assertContains('red-1', $received);
        $this->assertContains('red-2', $received);
        $this->assertContains('red-3', $received);
        $this->assertNotContains('blue-1', $received);
        $this->assertNotContains('green-1', $received);

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
