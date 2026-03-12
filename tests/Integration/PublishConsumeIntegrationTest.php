<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Address\AddressHelper;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\Outcome;

class PublishConsumeIntegrationTest extends IntegrationTestCase
{
    private \AMQP10\Client\Client $client;
    private string $queueName = 'integ-publish-consume';

    protected function setUp(): void
    {
        $this->client = $this->newClient()->connect();
        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification($this->queueName, QueueType::CLASSIC));
        $mgmt->close();
    }

    protected function tearDown(): void
    {
        // The consume test closes the client inside the handler; reconnect if needed.
        if (!$this->client->isConnected()) {
            $this->client = $this->newClient()->connect();
        }
        $mgmt = $this->client->management();
        try { $mgmt->deleteQueue($this->queueName); } catch (\Throwable) {}
        $mgmt->close();
        $this->client->close();
    }

    public function test_publish_message_returns_accepted(): void
    {
        $address = AddressHelper::queueAddress($this->queueName);
        $outcome = $this->client->publish($address)->send(new Message('hello world'));
        $this->assertTrue($outcome->isAccepted());
    }

    public function test_publish_and_consume_message(): void
    {
        $address = AddressHelper::queueAddress($this->queueName);

        // publish
        $this->client->publish($address)->send(new Message('hello integration'));

        // consume one message then stop
        $received = null;
        $client   = $this->client;

        $this->client->consume($address)
            ->credit(1)
            ->handle(function(\AMQP10\Messaging\Message $msg, \AMQP10\Messaging\DeliveryContext $ctx) use (&$received, $client) {
                $received = $msg->body();
                $ctx->accept();
                $client->close(); // disconnect to break the consumer loop
            })
            ->run();

        $this->assertSame('hello integration', $received);
    }

    public function test_publish_with_application_properties(): void
    {
        $address = AddressHelper::queueAddress($this->queueName);
        $msg     = new Message('body', applicationProperties: ['correlation-id' => 'test-123', 'priority' => '1']);
        $outcome = $this->client->publish($address)->send($msg);
        $this->assertTrue($outcome->isAccepted());
    }
}
