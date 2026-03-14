<?php

declare(strict_types=1);

namespace AMQP10\Tests\Connection;

use AMQP10\Connection\Connection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class VirtualHostTest extends TestCase
{
    private function extractVhost(string $uri): string
    {
        $method = new ReflectionMethod(Connection::class, 'resolveVhost');
        $method->setAccessible(true);

        return $method->invoke(null, $uri);
    }

    public function test_no_path_returns_hostname(): void
    {
        $this->assertSame('broker.internal', $this->extractVhost('amqp://user:pass@broker.internal'));
    }

    public function test_path_slash_only_returns_hostname(): void
    {
        $this->assertSame('broker.internal', $this->extractVhost('amqp://user:pass@broker.internal/'));
    }

    public function test_explicit_vhost_returned(): void
    {
        $this->assertSame('production', $this->extractVhost('amqp://user:pass@broker.internal/production'));
    }

    public function test_url_encoded_default_vhost(): void
    {
        $this->assertSame('/', $this->extractVhost('amqp://user:pass@broker.internal/%2F'));
    }
}
