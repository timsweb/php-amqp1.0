<?php
declare(strict_types=1);

namespace AMQP10\Tests\Integration;

use AMQP10\Client\Client;
use AMQP10\Connection\Session;
use AMQP10\Protocol\PerformativeEncoder;
use AMQP10\Transport\BlockingAdapter;
use AMQP10\Connection\Connection;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected const URI = 'amqp://guest:guest@localhost:5672/';

    protected function newClient(): Client
    {
        return new Client(self::URI);
    }
}
