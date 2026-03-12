<?php
declare(strict_types=1);

namespace AMQP10\Tests\Client;

use AMQP10\Client\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function test_config_defaults(): void
    {
        $config = new Config();
        $this->assertFalse($config->autoReconnect);
        $this->assertSame(5, $config->maxRetries);
        $this->assertSame(1000, $config->backoffMs);
    }
}
