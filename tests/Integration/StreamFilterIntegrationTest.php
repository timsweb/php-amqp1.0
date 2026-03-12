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

    public function test_consume_from_queue(): void
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
}
