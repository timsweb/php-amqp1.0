<?php

namespace AMQP10\Exception;

use AMQP10\Exception\AmqpException;

class ConnectionException extends AmqpException
{
}

class ConnectionFailedException extends ConnectionException
{
}

class AuthenticationException extends ConnectionException
{
}

class ConnectionClosedException extends ConnectionException
{
}
