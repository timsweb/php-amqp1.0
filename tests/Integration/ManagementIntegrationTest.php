<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Management\BindingSpecification;

class ManagementIntegrationTest extends IntegrationTestCase
{
    private \AMQP10\Client\Client $client;

    protected function setUp(): void
    {
        $this->client = $this->newClient()->connect();
    }

    protected function tearDown(): void
    {
        $this->client->close();
    }

    public function test_declare_and_delete_queue(): void
    {
        $mgmt = $this->client->management();
        $spec = new QueueSpecification('integration-test-queue', QueueType::CLASSIC);
        $mgmt->declareQueue($spec);
        $mgmt->deleteQueue('integration-test-queue');
        $this->assertTrue(true); // no exception = success
        $mgmt->close();
    }

    public function test_declare_and_delete_exchange(): void
    {
        $mgmt = $this->client->management();
        $spec = new ExchangeSpecification('integration-test-exchange', ExchangeType::DIRECT);
        $mgmt->declareExchange($spec);
        $mgmt->deleteExchange('integration-test-exchange');
        $this->assertTrue(true);
        $mgmt->close();
    }

    public function test_declare_queue_bind_and_cleanup(): void
    {
        $mgmt = $this->client->management();
        $mgmt->declareQueue(new QueueSpecification('integ-queue', QueueType::CLASSIC));
        $mgmt->declareExchange(new ExchangeSpecification('integ-exchange', ExchangeType::DIRECT));
        $mgmt->bind(new BindingSpecification('integ-exchange', 'integ-queue', 'test-key'));
        // cleanup
        $mgmt->deleteQueue('integ-queue');
        $mgmt->deleteExchange('integ-exchange');
        $this->assertTrue(true);
        $mgmt->close();
    }
}
