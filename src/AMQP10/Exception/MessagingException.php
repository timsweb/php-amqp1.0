<?php

namespace AMQP10\Exception;

use AMQP10\Exception\AmqpException;

class MessagingException extends AmqpException
{
}

class PublishException extends MessagingException
{
}

class ConsumerException extends MessagingException
{
}

class MessageTimeoutException extends MessagingException
{
}
