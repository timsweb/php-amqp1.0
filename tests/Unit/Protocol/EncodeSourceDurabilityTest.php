<?php
declare(strict_types=1);
namespace AMQP10\Tests\Protocol;

use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\FrameParser;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Protocol\TypeDecoder;
use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;
use PHPUnit\Framework\TestCase;

class EncodeSourceDurabilityTest extends TestCase
{
    private function decodeAttach(string $frame): array
    {
        $body = FrameParser::extractBody($frame);
        return (new TypeDecoder($body))->decode();
    }

    private function decodeSource(string $frame): array
    {
        $attach = $this->decodeAttach($frame);
        // ATTACH field[5] = source (described type)
        return $attach['value'][5];
    }

    public function test_no_durability_emits_short_source(): void
    {
        $frame = PerformativeEncoder::attach(
            channel: 0,
            name:    'my-link',
            handle:  0,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  'my-queue',
        );

        $source = $this->decodeSource($frame);

        $this->assertSame(Descriptor::SOURCE, $source['descriptor']);
        // Short path: only 1 field — the address
        $this->assertSame('my-queue', $source['value'][0]);
        $this->assertCount(1, $source['value']);
    }

    public function test_durability_unsettled_state_encoded(): void
    {
        $frame = PerformativeEncoder::attach(
            channel:      0,
            name:         'durable-link',
            handle:       0,
            role:         PerformativeEncoder::ROLE_RECEIVER,
            source:       'my-queue',
            durable:      TerminusDurability::UnsettledState,
            expiryPolicy: ExpiryPolicy::Never,
        );

        $source = $this->decodeSource($frame);

        $this->assertSame(Descriptor::SOURCE, $source['descriptor']);
        $this->assertSame('my-queue', $source['value'][0]);   // field 0: address
        $this->assertSame(2,          $source['value'][1]);   // field 1: durable = UnsettledState = 2
        $this->assertSame('never',    $source['value'][2]);   // field 2: expiry-policy = 'never'
    }

    public function test_durability_without_filter_uses_full_field_list(): void
    {
        $frame = PerformativeEncoder::attach(
            channel: 0,
            name:    'config-link',
            handle:  0,
            role:    PerformativeEncoder::ROLE_RECEIVER,
            source:  'my-queue',
            durable: TerminusDurability::Configuration,
        );

        $source = $this->decodeSource($frame);

        $this->assertSame(Descriptor::SOURCE, $source['descriptor']);
        $this->assertSame('my-queue', $source['value'][0]);   // field 0: address
        $this->assertSame(1,          $source['value'][1]);   // field 1: durable = Configuration = 1
        // Full 11-field list
        $this->assertCount(11, $source['value']);
    }
}
