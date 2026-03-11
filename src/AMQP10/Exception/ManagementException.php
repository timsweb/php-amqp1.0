<?php

namespace AMQP10\Exception;

use AMQP10\Exception\AmqpException;

class ManagementException extends AmqpException
{
}

class ExchangeNotFoundException extends ManagementException
{
}

class QueueNotFoundException extends ManagementException
{
}

class BindingException extends ManagementException
{
}
