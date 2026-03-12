<?php
declare(strict_types=1);

namespace AMQP10\Tests\Exception;

use AMQP10\Exception\AmqpException;
use PHPUnit\Framework\TestCase;

class AmqpExceptionTest extends TestCase
{
    public function test_exception_can_be_created_with_message(): void
    {
        $exception = new class('test message') extends AmqpException {};
        $this->assertSame('test message', $exception->getMessage());
    }
}
