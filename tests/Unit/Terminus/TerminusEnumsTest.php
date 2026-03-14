<?php

declare(strict_types=1);

namespace AMQP10\Tests\Unit\Terminus;

use AMQP10\Terminus\ExpiryPolicy;
use AMQP10\Terminus\TerminusDurability;
use PHPUnit\Framework\TestCase;

class TerminusEnumsTest extends TestCase
{
    public function test_terminus_durability_values(): void
    {
        $this->assertSame(0, TerminusDurability::None->value);
        $this->assertSame(1, TerminusDurability::Configuration->value);
        $this->assertSame(2, TerminusDurability::UnsettledState->value);
    }

    public function test_expiry_policy_values(): void
    {
        $this->assertSame('link-detach', ExpiryPolicy::LinkDetach->value);
        $this->assertSame('session-end', ExpiryPolicy::SessionEnd->value);
        $this->assertSame('connection-close', ExpiryPolicy::ConnectionClose->value);
        $this->assertSame('never', ExpiryPolicy::Never->value);
    }
}
