<?php

declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Management\BindingSpecification;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;

class ManagementIntegrationTest extends RabbitMqTestCase
{
    public function test_declare_and_delete_queue(): void
    {
        $noException = false;
        $this->runInEventLoop(function () use (&$noException): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $spec = new QueueSpecification('integration-test-queue', QueueType::CLASSIC);
            $mgmt->declareQueue($spec);
            $mgmt->deleteQueue('integration-test-queue');
            $noException = true;
            $mgmt->close();
            $client->close();
        });
        $this->assertTrue($noException);
    }

    public function test_declare_and_delete_exchange(): void
    {
        $noException = false;
        $this->runInEventLoop(function () use (&$noException): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $spec = new ExchangeSpecification('integration-test-exchange', ExchangeType::DIRECT);
            $mgmt->declareExchange($spec);
            $mgmt->deleteExchange('integration-test-exchange');
            $noException = true;
            $mgmt->close();
            $client->close();
        });
        $this->assertTrue($noException);
    }

    public function test_declare_queue_bind_and_cleanup(): void
    {
        $noException = false;
        $this->runInEventLoop(function () use (&$noException): void {
            $client = $this->newClient()->connect();
            $mgmt = $client->management();
            $mgmt->declareQueue(new QueueSpecification('integ-queue', QueueType::CLASSIC));
            $mgmt->declareExchange(new ExchangeSpecification('integ-exchange', ExchangeType::DIRECT));
            $mgmt->bind(new BindingSpecification('integ-exchange', 'integ-queue', 'test-key'));
            // cleanup
            $mgmt->deleteQueue('integ-queue');
            $mgmt->deleteExchange('integ-exchange');
            $noException = true;
            $mgmt->close();
            $client->close();
        });
        $this->assertTrue($noException);
    }
}
