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
        $this->assertNull($config->sasl);
        $this->assertSame(30.0, $config->timeout);
        $this->assertSame([], $config->tlsOptions);
    }

    public function test_config_with_timeout(): void
    {
        $config  = new Config();
        $updated = $config->with(timeout: 5.0);
        $this->assertSame(5.0, $updated->timeout);
        $this->assertSame(30.0, $config->timeout); // original unchanged
    }

    public function test_config_with_tls_options(): void
    {
        $config  = new Config();
        $updated = $config->with(tlsOptions: ['verify_peer' => false]);
        $this->assertSame(['verify_peer' => false], $updated->tlsOptions);
        $this->assertSame([], $config->tlsOptions); // original unchanged
    }
}
