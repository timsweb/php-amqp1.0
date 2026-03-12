<?php
declare(strict_types=1);

namespace AMQP10\Exception;

use AMQP10\Exception\ConnectionFailedException;
use AMQP10\Exception\AuthenticationException;
use AMQP10\Exception\ConnectionClosedException;

abstract class ConnectionException extends AmqpException
{
}
