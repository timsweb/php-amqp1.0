<?php

declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Messaging\Message;

/**
 * Basic publish/consume integration tests against IBM MQ.
 *
 * IBM MQ's developer Docker image pre-creates DEV.QUEUE.1/2/3 so no
 * queue declaration is needed. Addresses are bare queue names — IBM MQ
 * does not use RabbitMQ's /queues/{name} address scheme.
 */
class IbmMqPublishConsumeTest extends IbmMqTestCase
{
    /** Pre-created by IBM MQ developer image defaults. */
    private string $queue = 'DEV.QUEUE.1';

    public function test_connect_to_ibm_mq(): void
    {
        $this->requireIbmMq();
        $this->runInEventLoop(function (): void {
            $client = $this->newClient()->connect();
            $this->assertTrue($client->isConnected());
            $client->close();
        });
    }

    public function test_publish_message_returns_accepted(): void
    {
        $this->requireIbmMq();
        $accepted = false;
        $this->runInEventLoop(function () use (&$accepted): void {
            $client = $this->newClient()->connect();
            $outcome = $client->publish($this->queue)->withTargetCapabilities(['queue'])->withMessageToAddress()->send(new Message('hello ibm mq'));
            $accepted = $outcome->isAccepted();
            $client->close();
        });
        $this->assertTrue($accepted);
    }

    public function test_publish_and_consume_message(): void
    {
        $this->requireIbmMq();
        $this->purgeQueue($this->queue);
        $received = null;
        $this->runInEventLoop(function () use (&$received): void {
            $client = $this->newClient()->connect();

            $client->publish($this->queue)->withTargetCapabilities(['queue'])->withMessageToAddress()->send(new Message('hello ibm mq integration'));

            $consumer = $client->consume($this->queue)->credit(1)->withSourceCapabilities(['queue'])->consumer();
            $delivery = $consumer->receive();
            if ($delivery !== null) {
                $received = $delivery->body();
                $delivery->accept();
            }
            $consumer->close();
            $client->close();
        });

        $this->assertSame('hello ibm mq integration', $received);
    }
}
