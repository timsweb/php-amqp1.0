<?php
// tests/Unit/Address/AddressHelperTest.php
namespace AMQP10\Tests\Address;

use AMQP10\Address\AddressHelper;
use PHPUnit\Framework\TestCase;

class AddressHelperTest extends TestCase
{
    public function test_exchange_address_with_routing_key(): void
    {
        $this->assertSame('/exchanges/my-exchange/my-key', AddressHelper::exchangeAddress('my-exchange', 'my-key'));
    }

    public function test_exchange_address_without_routing_key(): void
    {
        $this->assertSame('/exchanges/my-exchange', AddressHelper::exchangeAddress('my-exchange'));
    }

    public function test_queue_address(): void
    {
        $this->assertSame('/queues/my-queue', AddressHelper::queueAddress('my-queue'));
    }

    public function test_stream_address(): void
    {
        $this->assertSame('/streams/my-stream', AddressHelper::streamAddress('my-stream'));
    }

    public function test_forward_slash_is_percent_encoded(): void
    {
        $this->assertSame('/exchanges/my%2Fexchange/my%2Fkey', AddressHelper::exchangeAddress('my/exchange', 'my/key'));
    }

    public function test_space_is_percent_encoded(): void
    {
        $this->assertSame('/queues/my%20queue', AddressHelper::queueAddress('my queue'));
    }

    public function test_unreserved_chars_not_encoded(): void
    {
        // RFC 3986 unreserved: ALPHA / DIGIT / "-" / "." / "_" / "~"
        $this->assertSame('/queues/my-queue.v2_test~1', AddressHelper::queueAddress('my-queue.v2_test~1'));
    }
}
