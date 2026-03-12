<?php

namespace AMQP10\Tests\Messaging;

use AMQP10\Messaging\Outcome;
use AMQP10\Messaging\OutcomeState;
use PHPUnit\Framework\TestCase;

class OutcomeTest extends TestCase
{
    public function test_accepted(): void
    {
        $outcome = Outcome::accepted();
        $this->assertTrue($outcome->isAccepted());
        $this->assertFalse($outcome->isRejected());
        $this->assertFalse($outcome->isReleased());
    }

    public function test_rejected(): void
    {
        $outcome = Outcome::rejected();
        $this->assertFalse($outcome->isAccepted());
        $this->assertTrue($outcome->isRejected());
    }

    public function test_released(): void
    {
        $outcome = Outcome::released();
        $this->assertTrue($outcome->isReleased());
    }

    public function test_modified(): void
    {
        $outcome = Outcome::modified();
        $this->assertTrue($outcome->isModified());
    }
}
