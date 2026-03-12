<?php
declare(strict_types=1);

namespace AMQP10\Exception;

use AMQP10\Exception\InvalidAddressException;
use AMQP10\Exception\FrameException;
use AMQP10\Exception\SaslException;

abstract class ProtocolException extends AmqpException
{
}
