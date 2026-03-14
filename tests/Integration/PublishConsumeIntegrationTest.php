<?php

declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Address\AddressHelper;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use Throwable;

class PublishConsumeIntegrationTest extends RabbitMqTestCase
{
    private string $queueName = 'integ-publish-consume';

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

    public function test_publish_message_returns_accepted(): void
    {
        $accepted = false;
        $this->runInEventLoop(function () use (&$accepted): void {
            $client = $this->newClient()->connect();
            $address = AddressHelper::queueAddress($this->queueName);
            $outcome = $client->publish($address)->send(new Message('hello world'));
            $accepted = $outcome->isAccepted();
            $client->close();
        });
        $this->assertTrue($accepted);
    }

    public function test_publish_and_consume_message(): void
    {
        $received = null;
        $this->runInEventLoop(function () use (&$received): void {
            $client = $this->newClient()->connect();
            $address = AddressHelper::queueAddress($this->queueName);

            // publish
            $client->publish($address)->send(new Message('hello integration'));

            // consume one message using receive() to avoid close-inside-handler anti-pattern
            $consumer = $client->consume($address)->credit(1)->consumer();
            $delivery = $consumer->receive();
            if ($delivery !== null) {
                $received = $delivery->message()->body();
                $delivery->context()->accept();
            }
            $consumer->close();
            $client->close();
        });

        $this->assertSame('hello integration', $received);
    }

    public function test_publish_with_application_properties(): void
    {
        $accepted = false;
        $this->runInEventLoop(function () use (&$accepted): void {
            $client = $this->newClient()->connect();
            $address = AddressHelper::queueAddress($this->queueName);
            $msg = new Message('body', applicationProperties: ['correlation-id' => 'test-123', 'priority' => '1']);
            $outcome = $client->publish($address)->send($msg);
            $accepted = $outcome->isAccepted();
            $client->close();
        });
        $this->assertTrue($accepted);
    }
}
