<?php
declare(strict_types=1);

namespace AMQP10\Exception;

use AMQP10\Exception\PublishException;
use AMQP10\Exception\ConsumerException;
use AMQP10\Exception\MessageTimeoutException;

abstract class MessagingException extends AmqpException
{
}
