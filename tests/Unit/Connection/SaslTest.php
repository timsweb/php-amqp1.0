<?php
namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Sasl;
use PHPUnit\Framework\TestCase;

class SaslTest extends TestCase
{
    public function test_plain_mechanism_name(): void
    {
        $sasl = Sasl::plain('user', 'pass');
        $this->assertSame('PLAIN', $sasl->mechanism());
    }

    public function test_plain_initial_response_format(): void
    {
        $sasl = Sasl::plain('user', 'pass');
        $this->assertSame("\x00user\x00pass", $sasl->initialResponse());
    }

    public function test_plain_from_uri_credentials(): void
    {
        $sasl = Sasl::plain('guest', 'guest');
        $this->assertSame("\x00guest\x00guest", $sasl->initialResponse());
    }

    public function test_external_mechanism(): void
    {
        $sasl = Sasl::external();
        $this->assertSame('EXTERNAL', $sasl->mechanism());
        $this->assertSame('', $sasl->initialResponse());
    }
}
