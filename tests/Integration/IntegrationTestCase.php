<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Client\Client;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

abstract class IntegrationTestCase extends TestCase
{
    protected const URI = 'amqp://guest:guest@localhost:5672/';

    protected function newClient(): Client
    {
        return new Client(self::URI);
    }

    /**
     * Run a test body inside the Revolt event loop.
     * All RevoltTransport operations (connect, read, send) require an event loop context.
     *
     * The closure is queued as a callback and the event loop is run until it is empty.
     * Exceptions thrown inside the closure are re-thrown after the loop exits.
     */
    protected function runInEventLoop(\Closure $fn): void
    {
        $exception = null;
        EventLoop::queue(function () use ($fn, &$exception): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });
        EventLoop::run();
        if ($exception !== null) {
            throw $exception;
        }
    }
}
