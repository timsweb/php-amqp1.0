<?php

declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Address\AddressHelper;
use AMQP10\Client\Client;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\OutcomeState;
use Throwable;

class PublishConsumeTest extends RabbitMqTestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = $this->newClient()->connect();
    }

    protected function tearDown(): void
    {
        if ($this->client->isConnected()) {
            $this->client->close();
        }
    }

    public function test_publish_message_is_accepted(): void
    {
        $queueName = 'tc-publish-accepted';
        $address = AddressHelper::queueAddress($queueName);

        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::CLASSIC));
        $mgmt->close();

        $publisher = $this->client->publish($address);
        $outcome = $publisher->send(Message::create('hello-world'));
        $publisher->close();

        $this->assertTrue($outcome->isAccepted());

        // Cleanup
        $mgmt = $this->client->management();
        try {
            $mgmt->deleteQueue($queueName);
        } catch (Throwable) {
        }
        $mgmt->close();
    }

    public function test_publish_and_consume_basic_message(): void
    {
        $queueName = 'tc-publish-consume';
        $address = AddressHelper::queueAddress($queueName);

        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::CLASSIC));
        $mgmt->close();

        // Publish
        $publisher = $this->client->publish($address);
        $publisher->send(Message::create('hello-world'));
        $publisher->close();

        // Consume using receive() directly so we control the loop
        $consumer = $this->client->consume($address)
            ->credit(1)
            ->consumer();

        $delivery = $consumer->receive();
        $consumer->close();

        $this->assertNotNull($delivery);
        $this->assertSame('hello-world', $delivery->message()->body());
        $delivery->context()->accept();

        // Cleanup
        $mgmt = $this->client->management();
        try {
            $mgmt->deleteQueue($queueName);
        } catch (Throwable) {
        }
        $mgmt->close();
    }

    public function test_durable_message_with_subject(): void
    {
        $queueName = 'tc-durable';
        $address = AddressHelper::queueAddress($queueName);

        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::CLASSIC));
        $mgmt->close();

        $publisher = $this->client->publish($address);
        $msg = Message::create('{"orderId":1}')
            ->withSubject('order.placed')
            ->withDurable(true)
            ->withContentType('application/json');

        $outcome = $publisher->send($msg);
        $this->assertSame(OutcomeState::ACCEPTED, $outcome->state);
        $publisher->close();

        // Cleanup
        $mgmt = $this->client->management();
        try {
            $mgmt->deleteQueue($queueName);
        } catch (Throwable) {
        }
        $mgmt->close();
    }

    public function test_fire_and_forget_returns_accepted_immediately(): void
    {
        $queueName = 'tc-fire-forget';
        $address = AddressHelper::queueAddress($queueName);

        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification($queueName, QueueType::CLASSIC));
        $mgmt->close();

        $publisher = $this->client->publish($address)
            ->fireAndForget();

        $start = microtime(true);
        for ($i = 0; $i < 5; $i++) {
            // Fire-and-forget sends with pre-settled mode — Outcome::accepted() returned immediately
            $outcome = $publisher->send(Message::create("msg-$i"));
            $this->assertTrue($outcome->isAccepted());
        }
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(5.0, $elapsed, 'Five fire-and-forget sends should complete quickly');
        $publisher->close();

        // Cleanup
        $mgmt = $this->client->management();
        try {
            $mgmt->deleteQueue($queueName);
        } catch (Throwable) {
        }
        $mgmt->close();
    }
}
