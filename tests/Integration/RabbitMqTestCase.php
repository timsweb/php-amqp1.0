<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Testcontainers\Container\Container;
use Testcontainers\Wait\WaitForLog;

/**
 * Base class for integration tests that need a live RabbitMQ broker.
 *
 * Starts a single RabbitMQ container for the entire test class (setUpBeforeClass)
 * and stops it afterwards (tearDownAfterClass). Tests within a class share the
 * same broker instance for speed.
 */
abstract class RabbitMqTestCase extends TestCase
{
    private static ?Container $container = null;
    private static string $amqpUri = '';

    /** Host port that RabbitMQ's 5672 is mapped to on the test runner. */
    private static int $hostPort = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$container !== null) {
            // Already running (e.g. if PHPUnit reuses the process across test classes).
            return;
        }

        self::$hostPort = self::findFreePort();

        self::$container = Container::make('rabbitmq:4-management')
            ->withPort((string) self::$hostPort, '5672')
            ->withWait(new WaitForLog('Server startup complete'));

        self::$container->run();

        self::$amqpUri = sprintf('amqp://guest:guest@localhost:%d/', self::$hostPort);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$container !== null) {
            self::$container->stop();
            self::$container->remove();
            self::$container = null;
            self::$amqpUri   = '';
            self::$hostPort  = 0;
        }
    }

    protected function amqpUri(): string
    {
        return self::$amqpUri;
    }

    protected function newClient(): \AMQP10\Client\Client
    {
        return new \AMQP10\Client\Client($this->amqpUri());
    }

    /**
     * Run a test body inside the Revolt event loop.
     * All RevoltTransport operations (connect, read, send) require an event loop context.
     */
    protected function runInEventLoop(\Closure $fn): void
    {
        $exception = null;
        \Revolt\EventLoop::queue(function () use ($fn, &$exception): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });
        \Revolt\EventLoop::run();
        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Find an available TCP port on the loopback interface.
     */
    private static function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return 15672; // fallback
        }
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);
        return (int) $port;
    }
}
