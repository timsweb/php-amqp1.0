<?php
declare(strict_types=1);
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Connection;
use AMQP10\Connection\Sasl;
use AMQP10\Protocol\FrameBuilder;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Tests\Mocks\TransportMock;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    private function makeMockWithSaslHandshake(Sasl $sasl): TransportMock
    {
        $mock = new TransportMock();
        $mock->queueIncoming(FrameBuilder::saslProtocolHeader());
        $mock->queueIncoming(PerformativeEncoder::saslMechanisms([$sasl->mechanism()]));
        $mock->queueIncoming(PerformativeEncoder::saslOutcome(0));
        $mock->queueIncoming(FrameBuilder::amqpProtocolHeader());
        $mock->queueIncoming(PerformativeEncoder::open(containerId: 'server', hostname: 'localhost'));
        return $mock;
    }

    public function test_is_not_open_initially(): void
    {
        $mock       = new TransportMock();
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/');
        $this->assertFalse($connection->isOpen());
    }

    public function test_open_sends_sasl_protocol_header_first(): void
    {
        $sasl       = Sasl::plain('guest', 'guest');
        $mock       = $this->makeMockWithSaslHandshake($sasl);
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/', $sasl);
        $connection->open();
        $sent = $mock->sent();
        $this->assertSame(FrameBuilder::saslProtocolHeader(), substr($sent, 0, 8));
    }

    public function test_open_sends_amqp_protocol_header_after_sasl(): void
    {
        $sasl       = Sasl::plain('guest', 'guest');
        $mock       = $this->makeMockWithSaslHandshake($sasl);
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/', $sasl);
        $connection->open();
        $this->assertStringContainsString(FrameBuilder::amqpProtocolHeader(), $mock->sent());
    }

    public function test_connection_is_open_after_successful_open(): void
    {
        $sasl       = Sasl::plain('guest', 'guest');
        $mock       = $this->makeMockWithSaslHandshake($sasl);
        $connection = new Connection($mock, 'amqp://guest:guest@localhost:5672/', $sasl);
        $connection->open();
        $this->assertTrue($connection->isOpen());
    }

    public function test_sasl_failure_throws_authentication_exception(): void
    {
        $mock = new TransportMock();
        $mock->queueIncoming(FrameBuilder::saslProtocolHeader());
        $mock->queueIncoming(PerformativeEncoder::saslMechanisms(['PLAIN']));
        $mock->queueIncoming(PerformativeEncoder::saslOutcome(1));
        $sasl       = Sasl::plain('wrong', 'creds');
        $connection = new Connection($mock, 'amqp://wrong:creds@localhost:5672/', $sasl);
        $this->expectException(\AMQP10\Exception\AuthenticationException::class);
        $connection->open();
    }
}
