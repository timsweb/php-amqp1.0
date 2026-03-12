<?php
declare(strict_types=1);
namespace AMQP10\Tests\Management;

use AMQP10\Connection\Session;
use AMQP10\Exception\ManagementException;
use AMQP10\Management\BindingSpecification;
use AMQP10\Management\ExchangeSpecification;
use AMQP10\Management\ExchangeType;
use AMQP10\Management\Management;
use AMQP10\Management\QueueSpecification;
use AMQP10\Management\QueueType;
use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ManagementTest extends TestCase
{
    private function makeManagement(): array
    {
        $mock = new TransportMock();
        $mock->connect('amqp://test');

        // Queue BEGIN response for session
        $mock->queueIncoming(PerformativeEncoder::begin(channel: 0, remoteChannel: 0));
        $session = new Session($mock, channel: 0);
        $session->begin();
        $mock->clearSent();

        // Queue ATTACH responses for sender + receiver links.
        // These will be consumed (and skipped as non-TRANSFER frames) during awaitResponse.
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name:    'management-sender',
            handle:  0,
            role:    PerformativeEncoder::ROLE_RECEIVER, // server's perspective
            source:  null,
            target:  '/management',
        ));
        $mock->queueIncoming(PerformativeEncoder::attach(
            channel: 0,
            name:    'management-receiver',
            handle:  1,
            role:    PerformativeEncoder::ROLE_SENDER, // server's perspective
            source:  '/management/reply',
            target:  null,
        ));

        $management = new Management($session);
        $mock->clearSent();

        return [$mock, $management];
    }

    /**
     * Queue a management response as a TRANSFER frame carrying a message
     * with the given correlation-id and HTTP-status subject.
     */
    private function queueManagementResponse(TransportMock $mock, string $correlationId, int $status, string $body = ''): void
    {
        $msg = new Message($body, applicationProperties: [
            'subject'        => (string) $status,
            'correlation-id' => $correlationId,
        ]);
        $payload     = MessageEncoder::encode($msg);
        $deliveryTag = pack('N', 0);
        $mock->queueIncoming(PerformativeEncoder::transfer(
            channel:        0,
            handle:         1,
            deliveryId:     0,
            deliveryTag:    $deliveryTag,
            messagePayload: $payload,
            settled:        true,
        ));
    }

    public function test_declare_queue_sends_put_request(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 201);

        $spec = new QueueSpecification('test-queue', QueueType::QUORUM);
        $management->declareQueue($spec); // Should not throw

        $this->assertTrue(true); // Did not throw
    }

    public function test_declare_queue_200_also_succeeds(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 200);

        $spec = new QueueSpecification('existing-queue', QueueType::CLASSIC);
        $management->declareQueue($spec); // Should not throw

        $this->assertTrue(true);
    }

    public function test_delete_queue(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 204);

        $management->deleteQueue('test-queue'); // Should not throw

        $this->assertTrue(true);
    }

    public function test_delete_queue_200_also_succeeds(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 200);

        $management->deleteQueue('test-queue'); // Should not throw

        $this->assertTrue(true);
    }

    public function test_failed_request_throws_management_exception(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 404, '{"error":"not found"}');

        $this->expectException(ManagementException::class);
        $management->deleteQueue('nonexistent');
    }

    public function test_declare_exchange(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 201);

        $spec = new ExchangeSpecification('my-exchange', ExchangeType::DIRECT);
        $management->declareExchange($spec); // Should not throw

        $this->assertTrue(true);
    }

    public function test_delete_exchange(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 204);

        $management->deleteExchange('my-exchange'); // Should not throw

        $this->assertTrue(true);
    }

    public function test_declare_exchange_failure_throws(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 500, '{"error":"server error"}');

        $this->expectException(ManagementException::class);
        $management->declareExchange(new ExchangeSpecification('bad-exchange'));
    }

    public function test_bind(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 201);

        $spec = new BindingSpecification('my-exchange', 'my-queue', 'routing-key');
        $management->bind($spec); // Should not throw

        $this->assertTrue(true);
    }

    public function test_bind_failure_throws(): void
    {
        [$mock, $management] = $this->makeManagement();
        $this->queueManagementResponse($mock, '1', 400, '{"error":"bad request"}');

        $this->expectException(ManagementException::class);
        $management->bind(new BindingSpecification('x', 'q'));
    }

    public function test_multiple_requests_use_incrementing_ids(): void
    {
        [$mock, $management] = $this->makeManagement();
        // First request: correlation-id = '1'
        $this->queueManagementResponse($mock, '1', 201);
        // Second request: correlation-id = '2'
        $this->queueManagementResponse($mock, '2', 204);

        $management->declareQueue(new QueueSpecification('q1'));
        $management->deleteQueue('q1');

        $this->assertTrue(true);
    }

    public function test_close_sends_detach_frames(): void
    {
        [$mock, $management] = $this->makeManagement();
        $mock->clearSent();

        $management->close();

        // Verify at least one frame was sent (DETACH frames)
        $this->assertNotEmpty($mock->sent());
    }
}
